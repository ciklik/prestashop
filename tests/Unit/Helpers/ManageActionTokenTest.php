<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\ManageActionToken;

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_', 'test-cookie-key');
}

/**
 * Jeton des actions du bloc abonnement de la fiche commande : propre à
 * chaque employé, identique à l'affichage et à la vérification
 */
class ManageActionTokenTest extends TestCase
{
    private static function employee(int $id, string $passwd)
    {
        $employee = new \stdClass();
        $employee->id = $id;
        $employee->passwd = $passwd;

        return $employee;
    }

    public function testTokenIsTiedToEmployee()
    {
        $alice = self::employee(3, '$2y$10$alice');
        $token = ManageActionToken::forEmployee($alice);

        $this->assertSame(sha1(_COOKIE_KEY_ . 'ciklik_manage' . '3' . '$2y$10$alice'), $token);
        $this->assertTrue(ManageActionToken::isValid(self::employee(3, '$2y$10$alice'), $token));

        // Autre employé, ou mot de passe changé : jeton refusé
        $this->assertFalse(ManageActionToken::isValid(self::employee(4, '$2y$10$alice'), $token));
        $this->assertFalse(ManageActionToken::isValid(self::employee(3, '$2y$10$changed'), $token));

        // Ancien jeton commun à tous les employés : refusé
        $this->assertFalse(ManageActionToken::isValid($alice, sha1(_COOKIE_KEY_ . 'ciklik_manage')));
    }

    public function testNoTokenWithoutIdentifiableEmployee()
    {
        $this->assertSame('', ManageActionToken::forEmployee(null));
        $this->assertSame('', ManageActionToken::forEmployee(self::employee(0, 'hash')));
        $this->assertSame('', ManageActionToken::forEmployee(self::employee(3, '')));

        $this->assertFalse(ManageActionToken::isValid(null, ''));
        $this->assertFalse(ManageActionToken::isValid(self::employee(0, 'hash'), sha1(_COOKIE_KEY_ . 'ciklik_manage' . '0hash')));
        $this->assertFalse(ManageActionToken::isValid(self::employee(3, 'hash'), false));
        $this->assertFalse(ManageActionToken::isValid(self::employee(3, 'hash'), ['x']));
    }
}
