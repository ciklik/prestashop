<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Addons;

use ModuleAdminController;

if (!defined('_PS_VERSION_')) {
    exit;
}

trait Account
{
    public static function isCiklikAddonsBuild()
    {
        return false;
    }

    public static function injectAccount(ModuleAdminController $controller, $context)
    {
        return;
    }
}
