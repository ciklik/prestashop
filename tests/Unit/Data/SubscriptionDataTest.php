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
    /**
     * Ligne personnalisée visée : identifiant avec hash, ou sans hash quand
     * les seules lignes du produit sont personnalisées
     */
    public function testCustomizedLineIsTargeted()
    {
        $contents = [
            ['external_id' => '11:42', 'external_id_with_customizations' => '11:42_' . self::HASH, 'quantity' => 1],
            ['external_id' => '11:43', 'external_id_with_customizations' => '11:43', 'quantity' => 2],
        ];

        $this->assertTrue(SubscriptionData::targetsCustomizedLine($contents, '11:42'));
        $this->assertTrue(SubscriptionData::targetsCustomizedLine($contents, '11:42_' . self::HASH));
        $this->assertTrue(SubscriptionData::targetsCustomizedLine([], '11:42_' . self::HASH));

        // Mode attributs
        $this->assertTrue(SubscriptionData::targetsCustomizedLine(
            [['external_id' => '42', 'external_id_with_customizations' => '42_' . self::HASH]],
            '42'
        ));
    }

    /**
     * Ligne standard, produit absent de l'abonnement, ou ligne standard du
     * même produit à côté d'une ligne personnalisée : modification permise
     */
    public function testStandardLineIsNotTargeted()
    {
        $contents = [
            ['external_id' => '11:42', 'external_id_with_customizations' => '11:42_' . self::HASH],
            ['external_id' => '11:43', 'external_id_with_customizations' => '11:43'],
        ];

        $this->assertFalse(SubscriptionData::targetsCustomizedLine($contents, '11:43'));
        $this->assertFalse(SubscriptionData::targetsCustomizedLine($contents, '11:99'));
        $this->assertFalse(SubscriptionData::targetsCustomizedLine(null, '11:43'));

        $withStandardToo = array_merge($contents, [['external_id' => '11:42']]);
        $this->assertFalse(SubscriptionData::targetsCustomizedLine($withStandardToo, '11:42'));
        $this->assertFalse(SubscriptionData::targetsCustomizedLine(array_reverse($withStandardToo), '11:42'));
    }

    /**
     * Abonnement créé en mode déclinaisons (id de déclinaison seul), boutique
     * passée depuis en mode fréquences : il garde le mode déclinaisons, sinon
     * l'id de déclinaison est lu comme un id de produit et aucune fréquence
     * n'est proposée
     */
    public function testAttributeModeSubscriptionKeepsItsModeInFrequencyShop()
    {
        $contents = [['external_id' => '42', 'external_id_with_customizations' => '42']];

        $this->assertFalse(SubscriptionData::usesFrequencyMode($contents, true));
    }

    /**
     * Abonnement créé en mode fréquences, boutique passée en mode déclinaisons
     */
    public function testFrequencyModeSubscriptionKeepsItsModeInAttributeShop()
    {
        $contents = [['external_id' => '11:42'], ['external_id' => '12:0']];

        $this->assertTrue(SubscriptionData::usesFrequencyMode($contents, false));
    }

    /**
     * Sans contenu lisible, le mode actif de la boutique
     */
    public function testShopModeWithoutContent()
    {
        $this->assertTrue(SubscriptionData::usesFrequencyMode([], true));
        $this->assertFalse(SubscriptionData::usesFrequencyMode([], false));
        $this->assertTrue(SubscriptionData::usesFrequencyMode([['quantity' => 1]], true));
    }

    /**
     * @dataProvider externalIdModeProvider
     */
    public function testExternalIdMode(string $externalId, bool $frequencyMode)
    {
        $this->assertSame($frequencyMode, SubscriptionData::isFrequencyModeExternalId($externalId));
    }

    public function externalIdModeProvider(): array
    {
        return [
            'mode fréquences' => ['11:42', true],
            'mode fréquences sans déclinaison' => ['11:0', true],
            'mode fréquences personnalisé' => ['11:42_' . self::HASH, true],
            'mode déclinaisons' => ['42', false],
            'mode déclinaisons personnalisé' => ['42_' . self::HASH, false],
        ];
    }
}
