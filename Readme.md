# Available Default Product

When an order is put in status 'Paid' (it must have a status code of 'paid' which is the one by default)
the module checks if the product is still in stock. If not, it checks for other variations of the product
and sets the first one in stock as the default one.

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is AvailableDefaultProduct.
* Activate it in your thelia administration panel

### Composer

Add it in your main thelia composer.json file

```
composer require thelia/available-default-product-module:~1.0.0
```
