<?php


namespace AvailableDefaultProduct\EventListeners;


use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartItem;
use Thelia\Model\CartItemQuery;
use Thelia\Model\CartQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

class CheckDefaultAvailability extends BaseAction implements EventSubscriberInterface
{
    public function checkDefaultAvailability(OrderEvent $orderEvent) {

        /** We only want order in statud paid. Exits the method if not */
        if ($orderEvent->getOrder()->getStatusId() !== OrderStatusQuery::create()->findOneByCode('paid')->getId()) {
            return ;
        }

        $cartID = $orderEvent->getOrder()->getCartId();

        $cartItems = CartItemQuery::create()
            ->filterByCartId($cartID);

        /** Check every @var CartItem $item in the cart */
        foreach ($cartItems as $item) {
            $pse = ProductSaleElementsQuery::create()
                ->findOneById($item->getProductSaleElementsId());

            /** Check if product variation is default and has stock */
            if ($pse->getIsDefault() == 1 && $pse->getQuantity() < 1) {
                $pseList = ProductSaleElementsQuery::create()
                    ->filterByProductId($pse->getProductId());

                /** Parse through all @var ProductSaleElements $otherPse until one which is not default and not out of stock is found*/
                foreach ($pseList as $otherPse) {
                    if ($otherPse->getIsDefault() == 0 && $otherPse->getQuantity() > 0) {
                        $pse->setIsDefault(0)->save();
                        $otherPse->setIsDefault(1)->save();
                        break;
                    }
                }
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::ORDER_UPDATE_STATUS => array("checkDefaultAvailability", 128)
        );
    }
}