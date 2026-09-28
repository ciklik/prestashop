<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\ProductIdentifier;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductIdentifierTest extends TestCase
{
    // =========================================================================
    // Tests mode fréquence
    // =========================================================================

    /**
     * Mode fréquence : produit avec déclinaison "42:108"
     */
    public function testFrequencyModeWithCombination()
    {
        $result = ProductIdentifier::extract('42:108', true);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id_product']);
        $this->assertEquals(108, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : produit simple "42:0"
     */
    public function testFrequencyModeSimpleProduct()
    {
        $result = ProductIdentifier::extract('42:0', true);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id_product']);
        $this->assertEquals(0, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : avec hash customization "42:108_abc123"
     */
    public function testFrequencyModeWithCustomizationHash()
    {
        $result = ProductIdentifier::extract('42:108_abc123', true);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id_product']);
        $this->assertEquals(108, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : produit simple avec hash customization "42:0_abc123"
     */
    public function testFrequencyModeSimpleProductWithHash()
    {
        $result = ProductIdentifier::extract('42:0_abc123', true);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id_product']);
        $this->assertEquals(0, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : grands IDs
     */
    public function testFrequencyModeWithLargeIds()
    {
        $result = ProductIdentifier::extract('99999:88888', true);

        $this->assertNotNull($result);
        $this->assertEquals(99999, $result['id_product']);
        $this->assertEquals(88888, $result['id_product_attribute']);
    }

    // =========================================================================
    // Tests mode attributs
    // =========================================================================

    /**
     * Mode attributs : id_product_attribute entier "108"
     */
    public function testAttributeModeInteger()
    {
        $result = ProductIdentifier::extract('108', false);

        $this->assertNotNull($result);
        $this->assertEquals(0, $result['id_product']);
        $this->assertEquals(108, $result['id_product_attribute']);
    }

    /**
     * Mode attributs : valeur numérique en string
     */
    public function testAttributeModeNumericString()
    {
        $result = ProductIdentifier::extract('42', false);

        $this->assertNotNull($result);
        $this->assertEquals(0, $result['id_product']);
        $this->assertEquals(42, $result['id_product_attribute']);
    }

    // =========================================================================
    // Tests cas limites et formats invalides
    // =========================================================================

    /**
     * Chaîne vide : retourne null
     */
    public function testEmptyStringReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('', true));
        $this->assertNull(ProductIdentifier::extract('', false));
    }

    /**
     * Format invalide non numérique en mode fréquence
     */
    public function testFrequencyModeInvalidNonNumericReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('abc:def', true));
    }

    /**
     * Format invalide : partie produit non numérique
     */
    public function testFrequencyModeInvalidProductPartReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('abc:108', true));
    }

    /**
     * Format invalide : séparateur seul
     */
    public function testColonOnlyReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract(':', true));
    }

    /**
     * Format invalide en mode attributs : texte
     */
    public function testAttributeModeNonNumericReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('abc', false));
    }

    /**
     * Mode attributs avec "0" : retourne 0 (produit sans combinaison)
     */
    public function testAttributeModeZeroReturnsZero()
    {
        $result = ProductIdentifier::extract('0', false);

        $this->assertNotNull($result);
        $this->assertEquals(0, $result['id_product']);
        $this->assertEquals(0, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : valeurs négatives retournent null
     */
    public function testNegativeValuesReturnNull()
    {
        $this->assertNull(ProductIdentifier::extract('-1:108', true));
        $this->assertNull(ProductIdentifier::extract('42:-5', true));
    }

    /**
     * Mode attributs : valeur négative retourne null
     */
    public function testAttributeModeNegativeReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('-1', false));
    }

    /**
     * Mode fréquence : espaces dans la chaîne sont gérés
     */
    public function testFrequencyModeWithSpacesIsTrimmed()
    {
        $result = ProductIdentifier::extract(' 42 : 108 ', true);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['id_product']);
        $this->assertEquals(108, $result['id_product_attribute']);
    }

    /**
     * Mode fréquence : trop de séparateurs retourne null
     */
    public function testFrequencyModeTooManySeparatorsReturnsNull()
    {
        $this->assertNull(ProductIdentifier::extract('42:108:999', true));
    }

    // =========================================================================
    // Tests resolveProductIds (batch)
    // =========================================================================

    /**
     * Résolution batch avec tableau vide
     */
    public function testResolveProductIdsEmptyArray()
    {
        $result = ProductIdentifier::resolveProductIds([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Résolution batch avec des IDs déjà connus (id_product > 0)
     */
    public function testResolveProductIdsSkipsKnownProducts()
    {
        // Pas de requête SQL attendue si tous les id_product sont > 0
        $items = [
            ['id_product' => 42, 'id_product_attribute' => 108],
            ['id_product' => 55, 'id_product_attribute' => 200],
        ];

        $result = ProductIdentifier::resolveProductIds($items);

        $this->assertEquals(42, $result[0]['id_product']);
        $this->assertEquals(55, $result[1]['id_product']);
    }

    // =========================================================================
    // Tests isValidExternalId (routes produits de l'API)
    // =========================================================================

    /**
     * @dataProvider validExternalIdProvider
     */
    public function testIsValidExternalIdAcceptsBothModes(string $externalId)
    {
        $this->assertTrue(ProductIdentifier::isValidExternalId($externalId));
    }

    public function validExternalIdProvider(): array
    {
        return [
            'mode fréquence' => ['11:42'],
            'mode fréquence, produit simple' => ['42:0'],
            'mode fréquence avec customization' => ['11:42_' . str_repeat('a', 32)],
            'mode attributs' => ['42'],
            'mode attributs avec customization' => ['42_' . str_repeat('A', 32)],
        ];
    }

    /**
     * @dataProvider invalidExternalIdProvider
     */
    public function testIsValidExternalIdRejectsInvalidFormats($externalId)
    {
        $this->assertFalse(ProductIdentifier::isValidExternalId($externalId));
    }

    public function invalidExternalIdProvider(): array
    {
        return [
            'vide' => [''],
            'false (paramètre absent)' => [false],
            'null' => [null],
            'tableau' => [['42']],
            'entier' => [42],
            'lettres' => ['abc'],
            'colon seul' => [':'],
            'colon final' => ['42:'],
            'double colon' => ['1:2:3'],
            'négatif' => ['-1'],
            'espaces' => [' 42'],
            'saut de ligne final' => ["42\n"],
            'hash trop court' => ['42_' . str_repeat('a', 31)],
            'underscore sans hash' => ['42_'],
            'slash' => ['42/1'],
            'path traversal' => ['../../etc:passwd'],
        ];
    }
}
