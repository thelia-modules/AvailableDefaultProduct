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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's:
 * the database name must end with `_test`.
 */
final class CheckDefaultAvailabilityTest extends IntegrationTestCase
{
    private FixtureFactory $fixtures;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        $this->fixtures = $this->createFixtureFactory();
    }

    public function testDefaultSaleElementExhaustedByThePaidOrderIsReplacedByTheFirstAvailableAlternative(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(1.0)->save();
        $alternative = $this->fixtures->productSaleElement($product, ['quantity' => 5.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 1.0);

        $beforeDispatch = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        self::assertSame(1.0, $beforeDispatch->getQuantity(), 'Sanity check: stock is not decreased yet.');

        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $alternative = ProductSaleElementsQuery::create()->findPk($alternative->getId());

        // If the listener ran before Thelia\Action\Order::updateStatus() (priority 128), it would still
        // see a quantity of 1 and would never trigger the replacement: this is the ordering the module's
        // subscribed priority (64) guarantees.
        self::assertSame(0.0, $defaultSaleElements->getQuantity());
        self::assertFalse($defaultSaleElements->getIsDefault());
        self::assertTrue($alternative->getIsDefault());
        self::assertSame(5.0, $alternative->getQuantity());
    }

    public function testNothingReplacesTheDefaultWhenNoOtherSaleElementIsInStock(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(2.0)->save();
        $outOfStockAlternative = $this->fixtures->productSaleElement($product, ['quantity' => 0.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 2.0);

        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $outOfStockAlternative = ProductSaleElementsQuery::create()->findPk($outOfStockAlternative->getId());

        self::assertSame(0.0, $defaultSaleElements->getQuantity(), 'The order still decreases the stock.');
        self::assertTrue($defaultSaleElements->getIsDefault(), 'No alternative in stock: the default does not move.');
        self::assertFalse($outOfStockAlternative->getIsDefault());
    }

    public function testNothingHappensWhenTheOrderDoesNotReachThePaidStatus(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(0.0)->save();
        $alternative = $this->fixtures->productSaleElement($product, ['quantity' => 5.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 0.0);

        $this->dispatchStatusChange($order, OrderStatus::CODE_CANCELED);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $alternative = ProductSaleElementsQuery::create()->findPk($alternative->getId());

        self::assertTrue($defaultSaleElements->getIsDefault(), 'The order never reached "paid": the listener does nothing.');
        self::assertFalse($alternative->getIsDefault());
    }

    public function testNothingHappensWhenTheOrderedSaleElementsRowWasDeleted(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(1.0)->save();
        $deletedId = $defaultSaleElements->getId();

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 1.0);

        $defaultSaleElements->delete();
        self::assertNull(ProductSaleElementsQuery::create()->findPk($deletedId), 'Sanity check: the sale element is really gone.');

        // Called directly (not through the dispatcher) to inspect the logger: a missing row is a
        // routine case, matching Thelia\Action\Order::updateQuantity()'s own silent skip on the same
        // condition, and must not be logged as a failure.
        $logger = new RecordingLogger();
        $paidStatus = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID)
            ?? throw new \RuntimeException('Seeded order status "paid" is missing — run bin/test-prepare.');
        $event = new OrderEvent($order);
        $event->setStatus($paidStatus->getId());
        (new CheckDefaultAvailability($logger))->replaceExhaustedDefaultSaleElements($event);

        self::assertNull(ProductSaleElementsQuery::create()->findPk($deletedId), 'No exception: the missing row is skipped.');
        self::assertSame(0, $logger->count(), 'A deleted row is routine, not a failure: nothing is logged.');
    }

    public function testTwoOrderLinesOfTheSameSaleElementsAreProcessedOnce(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(2.0)->save();
        $alternative = $this->fixtures->productSaleElement($product, ['quantity' => 5.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 1.0);
        $this->orderProduct($order, $product, $defaultSaleElements, 1.0);

        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $alternative = ProductSaleElementsQuery::create()->findPk($alternative->getId());

        self::assertSame(0.0, $defaultSaleElements->getQuantity());
        self::assertFalse($defaultSaleElements->getIsDefault());
        self::assertTrue($alternative->getIsDefault(), 'Processed once: the alternative becomes the default exactly once.');
        self::assertSame(5.0, $alternative->getQuantity());
    }

    public function testPassingTheOrderToPaidTwiceIsIdempotent(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(1.0)->save();
        $alternative = $this->fixtures->productSaleElement($product, ['quantity' => 5.0, 'isDefault' => false]);
        // Untouched by either dispatch: only here to catch a listener that stops checking whether the
        // order line's sale element is still the default before looking for a replacement (it would
        // wrongly treat this spare row, now the only other one in stock, as a second candidate).
        $spare = $this->fixtures->productSaleElement($product, ['quantity' => 3.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $defaultSaleElements, 1.0);

        // The transition graph allows a status to reach itself (OrderStatusTransitionGraph::allows()),
        // so a second confirmation from a payment module must not misbehave.
        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);
        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $alternative = ProductSaleElementsQuery::create()->findPk($alternative->getId());
        $spare = ProductSaleElementsQuery::create()->findPk($spare->getId());

        self::assertFalse($defaultSaleElements->getIsDefault());
        self::assertTrue($alternative->getIsDefault(), 'The second call finds the order line pointing at an already non-default row and does nothing.');
        self::assertSame(5.0, $alternative->getQuantity(), 'No second decrease and no second swap.');
        self::assertFalse($spare->getIsDefault(), 'The spare row is never touched.');
    }

    public function testNothingHappensWhenTheOrderedSaleElementsIsNotTheDefaultOne(): void
    {
        $product = $this->product();
        $defaultSaleElements = $this->defaultSaleElements($product);
        $defaultSaleElements->setQuantity(5.0)->save();
        $orderedVariant = $this->fixtures->productSaleElement($product, ['quantity' => 1.0, 'isDefault' => false]);
        // Untouched: only here to catch a listener that stops checking whether the ordered sale
        // element is the default one before looking for a replacement (it would wrongly treat this
        // spare row as a candidate once the ordered variant runs out).
        $spare = $this->fixtures->productSaleElement($product, ['quantity' => 3.0, 'isDefault' => false]);

        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);
        $this->orderProduct($order, $product, $orderedVariant, 1.0);

        $this->dispatchStatusChange($order, OrderStatus::CODE_PAID);

        $defaultSaleElements = ProductSaleElementsQuery::create()->findPk($defaultSaleElements->getId());
        $orderedVariant = ProductSaleElementsQuery::create()->findPk($orderedVariant->getId());
        $spare = ProductSaleElementsQuery::create()->findPk($spare->getId());

        self::assertSame(0.0, $orderedVariant->getQuantity(), 'The order still decreases the stock of the variant it bought.');
        self::assertFalse($orderedVariant->getIsDefault(), 'Only the default running out triggers a replacement, not any purchased variant.');
        self::assertTrue($defaultSaleElements->getIsDefault());
        self::assertFalse($spare->getIsDefault(), 'The spare row is never touched.');
        self::assertSame(5.0, $defaultSaleElements->getQuantity());
    }

    private function product(): Product
    {
        return $this->fixtures->product($this->fixtures->category(), $this->fixtures->taxRule(), $this->fixtures->currency());
    }

    /**
     * Product::create() already creates a default ProductSaleElements: read it back
     * instead of creating a second one.
     */
    private function defaultSaleElements(Product $product): ProductSaleElements
    {
        return ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByIsDefault(true)
            ->findOne();
    }

    private function orderProduct(Order $order, Product $product, ProductSaleElements $productSaleElements, float $quantity): OrderProduct
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId($order->getId());
        $orderProduct->setProductRef($product->getRef());
        $orderProduct->setProductSaleElementsRef($productSaleElements->getRef());
        $orderProduct->setProductSaleElementsId($productSaleElements->getId());
        $orderProduct->setTitle('Test product');
        $orderProduct->setQuantity($quantity);
        $orderProduct->setPrice('10.000000');
        $orderProduct->setPromoPrice('10.000000');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->setVirtual(0);
        $orderProduct->setIsOffered(0);
        $orderProduct->save($this->getPropelConnection());

        return $orderProduct;
    }

    private function dispatchStatusChange(Order $order, string $targetStatusCode): void
    {
        $targetStatus = OrderStatusQuery::create()->findOneByCode($targetStatusCode)
            ?? throw new \RuntimeException("Seeded order status '$targetStatusCode' is missing — run bin/test-prepare.");

        $event = new OrderEvent($order);
        $event->setStatus($targetStatus->getId());

        $this->dispatcher()->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
