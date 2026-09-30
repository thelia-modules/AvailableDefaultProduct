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

namespace AvailableDefaultProduct\Tests;

use AvailableDefaultProduct\EventListener\CheckDefaultAvailability;
use Propel\Runtime\Propel;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Model\CartQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Calls the listener directly, without the wrapping transaction IntegrationTestCase
 * normally opens ($useTransaction = false): the listener's own beginTransaction() /
 * commit() / rollBack() must be a genuine, outermost transaction for a forced commit
 * failure to really revert what it wrote, the way it does in production. Fixtures are
 * cleaned up by hand since no rollback undoes them.
 *
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's:
 * the database name must end with `_test`.
 */
final class CheckDefaultAvailabilityResilienceTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    private FixtureFactory $fixtures;

    private ?Product $product = null;

    private ?Order $order = null;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        $this->fixtures = $this->createFixtureFactory();
    }

    protected function tearDown(): void
    {
        // fixtures->order() also creates a cart, a customer, and two order addresses: no rollback
        // undoes them ($useTransaction = false), so the counter FixtureFactory uses for unique
        // values (cart tokens, customer emails...) would otherwise collide with a later run against
        // the same database.
        if (null !== $this->order) {
            $cartId = $this->order->getCartId();
            $customerId = $this->order->getCustomerId();
            $invoiceAddressId = $this->order->getInvoiceOrderAddressId();
            $deliveryAddressId = $this->order->getDeliveryOrderAddressId();

            $this->order->delete();
            CartQuery::create()->findPk($cartId)?->delete();
            CustomerQuery::create()->findPk($customerId)?->delete();
            OrderAddressQuery::create()->findPk($invoiceAddressId)?->delete();
            OrderAddressQuery::create()->findPk($deliveryAddressId)?->delete();
        }

        $this->product?->delete();

        parent::tearDown();
    }

    public function testFailureDuringTheExchangeIsLoggedAndDoesNotBreakThePaidOrder(): void
    {
        $this->product = $this->fixtures->product($this->fixtures->category(), $this->fixtures->taxRule(), $this->fixtures->currency());
        $defaultSaleElements = ProductSaleElementsQuery::create()
            ->filterByProductId($this->product->getId())
            ->filterByIsDefault(true)
            ->findOne();
        // Already exhausted: in production this is what Thelia\Action\Order::updateStatus() (priority
        // 128) leaves behind before this listener runs. Calling the listener directly here skips the
        // dispatcher, so nothing decreases it for us.
        $defaultSaleElements->setQuantity(0.0)->save();
        $alternative = $this->fixtures->productSaleElement($this->product, ['quantity' => 5.0, 'isDefault' => false]);

        $this->order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId($this->order->getId());
        $orderProduct->setProductRef($this->product->getRef());
        $orderProduct->setProductSaleElementsRef($defaultSaleElements->getRef());
        $orderProduct->setProductSaleElementsId($defaultSaleElements->getId());
        $orderProduct->setTitle('Test product');
        $orderProduct->setQuantity(1.0);
        $orderProduct->setPrice('10.000000');
        $orderProduct->setPromoPrice('10.000000');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->setVirtual(0);
        $orderProduct->setIsOffered(0);
        $orderProduct->save($this->getPropelConnection());

        $paidStatus = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID)
            ?? throw new \RuntimeException('Seeded order status "paid" is missing — run bin/test-prepare.');
        $event = new OrderEvent($this->order);
        $event->setStatus($paidStatus->getId());

        $logger = new RecordingLogger();
        $failingConnection = new FailingOnCommitConnection(Propel::getConnection(OrderTableMap::DATABASE_NAME));
        $listener = new CheckDefaultAvailability($logger, $failingConnection);

        $listener->replaceExhaustedDefaultSaleElements($event);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $alternative = ProductSaleElementsQuery::create()->findPk($alternative->getId());

        self::assertTrue($defaultSaleElements->getIsDefault(), 'The failed commit rolled back the exchange: the default is unchanged.');
        self::assertFalse($alternative->getIsDefault());
        self::assertTrue($logger->hasErrorRecords(), 'The failure is logged.');
    }
}
