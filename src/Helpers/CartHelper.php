<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Helpers;

use Cart;
use Product;

if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * Class Cart
 */
class CartHelper
{

    public static function shouldPaidWithTax(Cart $cart)
    {
        return (int) Product::getTaxCalculationMethod((int)$cart->id_customer) === 0;
    }
}
