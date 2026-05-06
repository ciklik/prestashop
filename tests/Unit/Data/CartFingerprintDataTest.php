<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Teste l'encodage/décodage JSON du fingerprint panier
 *
 * Le module n'accepte que le format JSON. Le format PHP serialize
 * est rejeté (vulnérabilité d'injection d'objets).
 */
class CartFingerprintDataTest extends TestCase
{
    /**
     * Données de référence pour les tests
     *
     * @return array
     */
    private function getSampleData()
    {
        return [
            'id_customer' => 42,
            'id_address_delivery' => 10,
            'id_address_invoice' => 11,
            'id_lang' => 1,
            'id_currency' => 2,
            'id_carrier_reference' => 5,
            'upsells' => [],
            'frequency_id' => 3,
            'customizations' => [],
        ];
    }

    /**
     * Vérifie que encodeDatas() produit du JSON valide
     */
    public function testEncodeDataProducesValidJson()
    {
        $fingerprint = CartFingerprintData::create($this->getSampleData());
        $encoded = $fingerprint->encodeDatas();

        $decoded = json_decode($encoded, true);
        $this->assertNotNull($decoded, 'encodeDatas() doit produire du JSON valide');
        $this->assertSame(42, $decoded['id_customer']);
        $this->assertSame(10, $decoded['id_address_delivery']);
        $this->assertSame(3, $decoded['frequency_id']);
    }

    /**
     * Vérifie le décodage d'un fingerprint au format JSON
     */
    public function testExtractDatasFromJson()
    {
        $data = $this->getSampleData();
        $json = json_encode($data);

        $result = CartFingerprintData::extractDatas($json);

        $this->assertSame(42, $result->id_customer);
        $this->assertSame(10, $result->id_address_delivery);
        $this->assertSame(11, $result->id_address_invoice);
        $this->assertSame(1, $result->id_lang);
        $this->assertSame(2, $result->id_currency);
        $this->assertSame(5, $result->id_carrier_reference);
        $this->assertSame(3, $result->frequency_id);
    }

    /**
     * Vérifie qu'un JSON invalide lève une exception
     */
    public function testExtractDatasFromInvalidJson()
    {
        $this->expectException(\InvalidArgumentException::class);

        CartFingerprintData::extractDatas('{invalid json content');
    }

    /**
     * Vérifie que le format PHP serialize est rejeté
     */
    public function testExtractDatasRejectsSerializeFormat()
    {
        $this->expectException(\InvalidArgumentException::class);

        // Payload serialize en dur (le validateur Addons interdit l'appel serialize())
        $serialized = 'a:9:{s:11:"id_customer";i:42;s:19:"id_address_delivery";i:10;s:18:"id_address_invoice";i:11;s:7:"id_lang";i:1;s:11:"id_currency";i:2;s:20:"id_carrier_reference";i:5;s:7:"upsells";a:0:{}s:12:"frequency_id";i:3;s:14:"customizations";a:0:{}}';
        CartFingerprintData::extractDatas($serialized);
    }

    /**
     * Vérifie qu'un format totalement invalide lève une exception
     */
    public function testExtractDatasFromGarbageData()
    {
        $this->expectException(\InvalidArgumentException::class);

        CartFingerprintData::extractDatas('completely garbage data');
    }

    /**
     * Vérifie qu'un fingerprint avec clés manquantes lève une exception
     */
    public function testExtractDatasRejectsMissingRequiredKeys()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required key');

        $incomplete = json_encode(['id_customer' => 1]);
        CartFingerprintData::extractDatas($incomplete);
    }

    /**
     * Vérifie le cycle complet encode → decode (round-trip)
     */
    public function testRoundTrip()
    {
        $original = CartFingerprintData::create($this->getSampleData());
        $encoded = $original->encodeDatas();
        $restored = CartFingerprintData::extractDatas($encoded);

        $this->assertSame($original->id_customer, $restored->id_customer);
        $this->assertSame($original->id_address_delivery, $restored->id_address_delivery);
        $this->assertSame($original->id_address_invoice, $restored->id_address_invoice);
        $this->assertSame($original->id_lang, $restored->id_lang);
        $this->assertSame($original->id_currency, $restored->id_currency);
        $this->assertSame($original->id_carrier_reference, $restored->id_carrier_reference);
        $this->assertSame($original->frequency_id, $restored->frequency_id);
        $this->assertSame($original->upsells, $restored->upsells);
        $this->assertSame($original->customizations, $restored->customizations);
    }

    /**
     * Vérifie le round-trip avec des customizations non vides
     */
    public function testRoundTripWithCustomizations()
    {
        $data = $this->getSampleData();
        $data['customizations'] = [
            1 => [
                1 => [
                    ['type' => 1, 'name' => 'Gravure', 'value' => 'Mon texte'],
                ],
            ],
        ];

        $original = CartFingerprintData::create($data);
        $encoded = $original->encodeDatas();
        $restored = CartFingerprintData::extractDatas($encoded);

        $this->assertNotEmpty($restored->customizations);
    }

    /**
     * Vérifie que frequency_id null est préservé
     */
    public function testNullFrequencyIdIsPreserved()
    {
        $data = $this->getSampleData();
        $data['frequency_id'] = null;

        $original = CartFingerprintData::create($data);
        $encoded = $original->encodeDatas();
        $restored = CartFingerprintData::extractDatas($encoded);

        $this->assertNull($restored->frequency_id);
    }
}
