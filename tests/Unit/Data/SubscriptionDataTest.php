<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Détection des produits personnalisés dans le contenu d'un abonnement
 *
 * L'API renvoie external_id sans le hash de customisation et l'identifiant
 * complet dans external_id_with_customizations (SubscriptionResource).
 */
class SubscriptionDataTest extends TestCase
{
    private const HASH = 'e4e9ce5b2c8e9b5f7d3a1c4b6f8e2d0a';

    /**
     * Forme réelle de l'API : hash absent de external_id
     */
    public function testCustomizationDetectedFromExternalIdWithCustomizations()
    {
        $this->assertTrue(SubscriptionData::isCustomizationItem([
            'external_id' => '11:42',
            'external_id_with_customizations' => '11:42_' . self::HASH,
        ]));
    }

    /**
     * Mode attributs : même règle sur l'id_product_attribute seul
     */
    public function testCustomizationDetectedInAttributeMode()
    {
        $this->assertTrue(SubscriptionData::isCustomizationItem([
            'external_id' => '42',
            'external_id_with_customizations' => '42_' . self::HASH,
        ]));
    }

    /**
     * Réponse sans external_id_with_customizations (API antérieure) : hash lu sur external_id
     */
    public function testCustomizationDetectedFromExternalIdAlone()
    {
        $this->assertTrue(SubscriptionData::isCustomizationItem([
            'external_id' => '11:42_' . self::HASH,
        ]));
    }

    /**
     * @dataProvider plainProductProvider
     */
    public function testPlainProductIsNotCustomization(array $item)
    {
        $this->assertFalse(SubscriptionData::isCustomizationItem($item));
    }

    public function plainProductProvider(): array
    {
        return [
            'mode fréquence' => [['external_id' => '11:42', 'external_id_with_customizations' => '11:42']],
            'mode attributs' => [['external_id' => '42', 'external_id_with_customizations' => '42']],
            'sans external_id_with_customizations' => [['external_id' => '42']],
            'hash trop court' => [['external_id' => '42', 'external_id_with_customizations' => '42_' . str_repeat('a', 31)]],
            'ligne vide' => [[]],
        ];
    }
}
