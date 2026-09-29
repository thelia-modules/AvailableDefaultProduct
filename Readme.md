# Available Default Product

Replaces the default sale element of a product by another one still in stock once it runs out.

When an order reaches the `paid` status, the module checks the sale elements of every ordered
product: if the one marked default just ran out of stock, the first other sale element of the same
product still in stock (in the order the database returns them) becomes the new default. A product
with no other sale element in stock keeps its exhausted default.

Thelia 3.2 or later. The 2.x line of this module is for Thelia 2.

## Installation

```
composer require thelia/available-default-product-module:^3.0
php bin/console module:refresh
php bin/console module:activate AvailableDefaultProduct
```

The module creates no table and stores no configuration: activation only registers the listener.

## Usage

The module has no service to call and no configuration screen: it reacts on its own to
`TheliaEvents::ORDER_UPDATE_STATUS`, after `Thelia\Action\Order::updateStatus()` (priority 128) has
saved the new status and decreased the stock of the order lines, inside its own transaction. It never
touches the cart: the order lines (`order_product`) are the only source read, so the swap still
happens even when the cart the order was placed from has since been emptied or deleted.

The module produces no external output (no e-mail, no API call, no file write): it only updates
`product_sale_elements.is_default`. Like the 2.x line, the swap saves the sale element directly and
dispatches no declination event of its own: a listener wanting to react to the change should watch
`ProductSaleElements::save()` or poll `is_default`.

A failure while swapping (a database error, for instance) is logged and does not interrupt the order:
by the time the module runs, the order is already paid and committed by the core listener, so raising
an exception here would only abort every listener still due to run after it (`Thelia\Action\Coupon::orderStatusChange`,
priority 10, among others) and could fail the payment module's own callback for a problem of the
module's making. A stuck exhausted default is fixed by hand from the back office.

## Changes in 3.0.0

- Thelia 3 only (PHP 8.3 or later). `routing.xml` (no route declared) and the unused `Config/schema.xml`
  are dropped.
- Reads the order lines (`order_product`) instead of the cart the order was placed from, which may no
  longer exist or no longer match the order by the time it is paid.
- The stock and status change of the core listener are read after they are saved, inside the same
  request but in a separate transaction: the module no longer risks reading a stock value the core
  has not decreased yet, or leaving a product with two or zero default sale elements if it is
  interrupted midway.
- The target status is read from the event and compared by code, against `OrderStatus::CODE_PAID`; a
  shop missing the `paid` status no longer makes the module fatal.
- The alternative sale element is picked by a single query (`filterByIsDefault(false)`, quantity greater
  than zero, ordered by id) instead of a PHP loop over every sale element of the product.
- A failure is logged (injected `LoggerInterface`) instead of being rethrown into the still-running
  order status change.

## Tests

Integration tests, run from the root of the Thelia project against its own dedicated test database
(`DATABASE_NAME` alone is not enough: under `APP_ENV=test`, Symfony Dotenv skips `.env.local`, so the
connection details have to be passed explicitly, and so does the project's real kernel class):

```
DATABASE_HOST=<host> DATABASE_PORT=<port> DATABASE_NAME=availabledefault_test DATABASE_USER=<user> DATABASE_PASSWORD=<password> php bin/test-prepare
DATABASE_HOST=<host> DATABASE_PORT=<port> DATABASE_NAME=availabledefault_test DATABASE_USER=<user> DATABASE_PASSWORD=<password> KERNEL_CLASS=App\Kernel vendor/bin/phpunit --bootstrap vendor/thelia/modules/AvailableDefaultProduct/Tests/bootstrap.php vendor/thelia/modules/AvailableDefaultProduct/Tests
```
