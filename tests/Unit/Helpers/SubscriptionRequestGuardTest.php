<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\SubscriptionRequestGuard;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Accès aux actions d'abonnement : POST seulement (sauf l'affichage de la
 * page relais), jeton du client exigé, quantité d'upsell bornée
 */
class SubscriptionRequestGuardTest extends TestCase
{
    private const SECRET = 'cle-serveur-de-test';

    /**
     * Toute action passe en POST ; en GET, seul l'affichage de la page relais
     */
    public function testOnlyRelayPageIsServedWithGet()
    {
        foreach (['stop', 'resume', 'skip', 'newdate', 'updateaddress', 'contents', 'saverelay', 'addUpsell', 'relay'] as $action) {
            $this->assertTrue(SubscriptionRequestGuard::isMethodAllowed('POST', $action), $action);
        }

        $this->assertTrue(SubscriptionRequestGuard::isMethodAllowed('GET', 'relay'));

        foreach (['stop', 'resume', 'skip', 'newdate', 'updateaddress', 'contents', 'saverelay',
            'addUpsell', 'updateProductQuantity', 'removeProduct', 'addProduct', '', 'inconnue', false, ['relay']] as $action) {
            $this->assertFalse(SubscriptionRequestGuard::isMethodAllowed('GET', $action), var_export($action, true));
        }

        foreach (['HEAD', 'PUT', 'DELETE', 'get', '', null] as $method) {
            $this->assertFalse(SubscriptionRequestGuard::isMethodAllowed($method, 'relay'), var_export($method, true));
        }
    }

    public function testAjaxActions()
    {
        foreach (['addUpsell', 'updateProductQuantity', 'removeProduct', 'addProduct'] as $action) {
            $this->assertTrue(SubscriptionRequestGuard::isAjaxAction($action), $action);
        }

        foreach (['stop', 'relay', 'saverelay', 'ADDUPSELL', '', false, null, ['addUpsell']] as $action) {
            $this->assertFalse(SubscriptionRequestGuard::isAjaxAction($action), var_export($action, true));
        }
    }

    /**
     * Jeton absent, vide, altéré, d'un autre client ou non chaîne : refus
     */
    public function testTokenValidation()
    {
        // Forme de Tools::getToken(false) : md5 de la clé serveur, du client et de son mot de passe
        $expected = md5(self::SECRET . '42' . 'hash');

        $this->assertTrue(SubscriptionRequestGuard::isTokenValid($expected, $expected));

        foreach ([
            'absent' => false,
            'vide' => '',
            'altéré' => substr($expected, 0, -1) . ('0' === substr($expected, -1) ? '1' : '0'),
            'autre client' => md5(self::SECRET . '43' . 'hash'),
            'tableau' => [$expected],
            'majuscules' => strtoupper($expected),
        ] as $case => $received) {
            $this->assertFalse(SubscriptionRequestGuard::isTokenValid($expected, $received), $case);
        }

        // Jeton attendu indisponible : tout est refusé, même une chaîne vide
        $this->assertFalse(SubscriptionRequestGuard::isTokenValid('', ''));
        $this->assertFalse(SubscriptionRequestGuard::isTokenValid(null, 'abc'));
    }

    /**
     * Upsell : de 0 (retrait) à 9999, entier seulement ; une valeur illisible
     * n'est jamais lue comme un retrait
     */
    public function testUpsellQuantityBounds()
    {
        foreach (['0', '1', '12', '9999', 0, 5, 9999, '0001'] as $quantity) {
            $this->assertTrue(SubscriptionRequestGuard::isValidUpsellQuantity($quantity), var_export($quantity, true));
        }

        foreach (['-1', '10000', '99999', '1.5', ' 2', '2 ', '', 'abc', '1e3', "3\n", -1, 10000, 1.0, false, null, ['1']] as $quantity) {
            $this->assertFalse(SubscriptionRequestGuard::isValidUpsellQuantity($quantity), var_export($quantity, true));
        }
    }
}
