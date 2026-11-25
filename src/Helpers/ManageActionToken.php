<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Jeton des actions du bloc abonnement de la fiche commande (back-office),
 * propre à chaque employé : calculé à l'affichage du bloc et vérifié par le
 * contrôleur des actions, à l'identique des deux côtés.
 */
class ManageActionToken
{
    /**
     * @param mixed $employee Employé authentifié (\Employee)
     *
     * @return string Jeton, '' sans employé identifiable
     */
    public static function forEmployee($employee): string
    {
        if (!is_object($employee) || !isset($employee->id, $employee->passwd)
            || (int) $employee->id <= 0 || !is_string($employee->passwd) || '' === $employee->passwd) {
            return '';
        }

        return sha1(_COOKIE_KEY_ . 'ciklik_manage' . (int) $employee->id . $employee->passwd);
    }

    /**
     * @param mixed $employee Employé authentifié (\Employee)
     * @param mixed $token Jeton reçu
     *
     * @return bool
     */
    public static function isValid($employee, $token): bool
    {
        $expected = self::forEmployee($employee);

        return '' !== $expected && is_string($token) && hash_equals($expected, $token);
    }
}
