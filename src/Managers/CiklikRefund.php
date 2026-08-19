<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikRefund
{
    public static function canRun(): bool
    {
        return null !== self::getAuthenticatedEmployee();
    }

    /**
     * Retourne l'employé BO authentifié via le cookie psAdmin, ou null.
     * Permet aux appelants de vérifier des droits de profil (ACL) et de
     * journaliser l'auteur des actions sensibles.
     *
     * @return \Employee|null
     */
    public static function getAuthenticatedEmployee()
    {
        $cookie = new \Cookie('psAdmin', '', (int) \Configuration::get('PS_COOKIE_LIFETIME_BO'));
        $cookie->disallowWriting();
        $employee = new \Employee((int) $cookie->id_employee);

        $valid = \Validate::isLoadedObject($employee)
            && $employee->checkPassword((int) $cookie->id_employee, $cookie->passwd)
            && (!isset($cookie->remote_addr)
                || $cookie->remote_addr == ip2long(\Tools::getRemoteAddr())
                || !\Configuration::get('PS_COOKIE_CHECKIP'));

        return $valid ? $employee : null;
    }
}
