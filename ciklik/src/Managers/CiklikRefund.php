<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Configuration;
use Cookie;
use Employee;
use Tools;
use Validate;

class CiklikRefund
{
    public static function canRun(): bool
    {
        $cookie = new Cookie('psAdmin', '', (int) Configuration::get('PS_COOKIE_LIFETIME_BO'));
        $cookie->disallowWriting();
        $employee = new Employee((int) $cookie->id_employee);

        return Validate::isLoadedObject($employee)
            && $employee->checkPassword((int) $cookie->id_employee, $cookie->passwd)
            && (!isset($cookie->remote_addr)
                || $cookie->remote_addr == ip2long(Tools::getRemoteAddr())
                || !Configuration::get('PS_COOKIE_CHECKIP'));
    }

}
