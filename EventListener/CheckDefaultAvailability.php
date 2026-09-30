<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AvailableDefaultProduct\EventListener;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * When an order reaches the "paid" status, the default sale element of each ordered
 * product that just ran out of stock is replaced by the first other sale element of
 * the same product still in stock, ordered by id.
 *
 * Listens after `Thelia\Action\Order::updateStatus()` (priority 128): that method
 * decreases the stock and saves the new status inside its own transaction before
 * returning, so this listener always reads the stock and the status as they stand
 * once the order is actually paid.
 */
final readonly class CheckDefaultAvailability implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?ConnectionInterface $connection = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['replaceExhaustedDefaultSaleElements', 64],
        ];
    }

    public function replaceExhaustedDefaultSaleElements(OrderEvent $event): void
    {
        $targetStatus = OrderStatusQuery::create()->findPk($event->getStatus());

        if (null === $targetStatus || OrderStatus::CODE_PAID !== $targetStatus->getCode()) {
            return;
        }

        $connection = $this->connection ?? Propel::getConnection(OrderTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $this->replaceExhaustedDefaultSaleElementsOfOrder($event->getOrder()->getId());
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            // The order is already paid and committed by the core listener (Thelia\Action\Order::updateStatus(),
            // priority 128): rethrowing here would abort every listener still due to run at a lower priority
            // (Coupon::orderStatusChange at 10, among others) and fail the payment module's callback for a
            // problem that is ours, not the customer's. The exhausted default is left as is; a merchant fixes
            // it by hand from the back office.
            $this->logger->error('AvailableDefaultProduct: failed to replace the exhausted default sale element of order #{orderId}: {message}', [
                'orderId' => $event->getOrder()->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    private function replaceExhaustedDefaultSaleElementsOfOrder(int $orderId): void
    {
        $processedProductIds = [];

        foreach (OrderProductQuery::create()->filterByOrderId($orderId)->find() as $orderProduct) {
            $productSaleElementsId = $orderProduct->getProductSaleElementsId();

            if (null === $productSaleElementsId) {
                continue;
            }

            $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($productSaleElementsId);

            if (null === $defaultSaleElements || true !== $defaultSaleElements->getIsDefault() || $defaultSaleElements->getQuantity() > 0) {
                continue;
            }

            $productId = $defaultSaleElements->getProductId();

            if (\in_array($productId, $processedProductIds, true)) {
                continue;
            }

            $processedProductIds[] = $productId;

            $replacement = $this->findFirstAvailableSaleElement($productId);

            if (null === $replacement) {
                continue;
            }

            $defaultSaleElements->setIsDefault(false)->save();
            $replacement->setIsDefault(true)->save();
        }
    }

    private function findFirstAvailableSaleElement(int $productId): ?ProductSaleElements
    {
        return ProductSaleElementsQuery::create()
            ->filterByProductId($productId)
            ->filterByIsDefault(false)
            ->filterByQuantity(0, Criteria::GREATER_THAN)
            ->orderById()
            ->findOne();
    }
}
