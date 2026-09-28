<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikDeliveryOverride;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikDeliveryOverrideTest extends TestCase
{
    /**
     * Identifiants acceptes : alphanumeriques, tiret, souligne, longueur bornee par module
     */
    public function testValidRelayIds()
    {
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('mondialrelay', '012345'));
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('colissimo', 'A1B2C3D4'));
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('dpdfrance', 'P12345'));
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('nkmgls', '2500012345678901ABCD'));
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('chronopost', 'AB_12-345'));
        // Module en majuscules accepte (normalise)
        $this->assertTrue(CiklikDeliveryOverride::isValidRelayId('Mondialrelay', '12345'));
    }

    /**
     * Trop long pour la colonne du module transporteur : refus
     */
    public function testTooLongRelayIdIsRejected()
    {
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', '0123456'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('colissimo', '123456789'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('dpdfrance', '123456789'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('nkmgls', str_repeat('1', 21)));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('chronopost', '12345678901'));
    }

    /**
     * Caracteres hors liste, vide, module inconnu : refus
     */
    public function testInvalidCharactersOrModuleAreRejected()
    {
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', ''));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', '12 34'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', "1234\n"));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', '12;34'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('mondialrelay', 'é1234'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('laposte', '12345'));
        $this->assertFalse(CiklikDeliveryOverride::isValidRelayId('', '12345'));
    }

    /**
     * Payload conforme aux regles partagees BO/front : champs connus, longueurs,
     * validateurs PrestaShop, pays ISO alpha-2 ; vide accepte
     */
    public function testValidPayload()
    {
        $this->assertTrue(CiklikDeliveryOverride::isValidPayload([]));
        $this->assertTrue(CiklikDeliveryOverride::isValidPayload([
            'name' => 'Tabac "Le Cèdre"',
            'name2' => 'Le Cèdre',
            'address1' => '12 rue de la Paix, bât. C',
            'address2' => 'Cour 2',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'FR',
            'phone' => '+33 1 23 45 67 89',
            'product_code' => 'A2P',
            'network' => 'X00',
            'parcel_shop_working_day' => '{"mon":"9-18"}',
        ]));
    }

    /**
     * Payload refuse : champ inconnu, valeur non chaine, trop long, validateur
     * PrestaShop en echec, code pays hors ISO alpha-2
     */
    public function testInvalidPayloadIsRejected()
    {
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['latitude' => '48.87']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['name' => ['Tabac']]));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['name' => str_repeat('a', 65)]));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['name' => 'Tabac <script>']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['address1' => '12 rue {test}']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['zipcode' => '75002!']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['city' => 'Paris;']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['phone' => 'abc']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['country_iso' => 'FRA']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['country_iso' => 'F1']));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload(['parcel_shop_working_day' => str_repeat('x', 4001)]));
        $this->assertFalse(CiklikDeliveryOverride::isValidPayload([0 => 'Tabac']));
    }

    /**
     * Nettoyage d'une valeur : balises, caracteres de controle, espaces de bord
     */
    public function testCleanPayloadValue()
    {
        $this->assertSame('Tabac du coin', CiklikDeliveryOverride::cleanPayloadValue("Tabac\x00 du\ncoin "));
        $this->assertSame('Tabac  du coin', CiklikDeliveryOverride::cleanPayloadValue("Tabac\x01 du\ncoin "));
        $this->assertSame('Tabac alert(1)', CiklikDeliveryOverride::cleanPayloadValue('Tabac <script>alert(1)</script>'));
        $this->assertSame('75002', CiklikDeliveryOverride::cleanPayloadValue(75002));
        $this->assertSame('', CiklikDeliveryOverride::cleanPayloadValue(['Paris']));
        $this->assertSame('', CiklikDeliveryOverride::cleanPayloadValue(null));
    }

    /**
     * Les longueurs par module couvrent exactement les modules supportes
     */
    public function testMaxLengthsCoverSupportedModules()
    {
        $this->assertEqualsCanonicalizing(
            CiklikDeliveryOverride::SUPPORTED_MODULES,
            array_keys(CiklikDeliveryOverride::RELAY_ID_MAX_LENGTHS)
        );
    }
}
