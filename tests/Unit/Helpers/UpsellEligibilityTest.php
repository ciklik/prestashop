<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\UpsellEligibility;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Règle commune au bouton « Ajouter à l'abonnement » de la fiche produit et à
 * l'action addUpsell : le serveur refuse ce que l'interface ne propose pas
 */
class UpsellEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::resetMocks();
        \Configuration::updateValue('CIKLIK_ENABLE_UPSELL', '1');
        \Product::resetMocks();
        \Pack::$packs = [];
    }

    protected function tearDown(): void
    {
        \Configuration::resetMocks();
        \Product::resetMocks();
        \Pack::$packs = [];
    }

    /**
     * Produit tel que la fiche le montre à un client : actif, visible,
     * commandable, accessible, pas un pack
     */
    private function product(int $id = 12): \Product
    {
        return new \Product($id);
    }

    /**
     * Cas nominal : proposé, et l'accès est vérifié pour le client connecté
     */
    public function testEligibleProduct()
    {
        $this->assertNull(UpsellEligibility::refusal($this->product(), 42));
        $this->assertSame([42], \Product::$checkAccessCalls);

        // Visible au catalogue seulement ou à la recherche seulement : proposé
        foreach (['catalog', 'search'] as $visibility) {
            $product = $this->product();
            $product->visibility = $visibility;
            $this->assertNull(UpsellEligibility::refusal($product, 42), $visibility);
        }
    }

    /**
     * Fonction désactivée, ou clé absente : aucun ajout
     */
    public function testDisabledUpsellIsRefused()
    {
        \Configuration::updateValue('CIKLIK_ENABLE_UPSELL', '0');
        $this->assertFalse(UpsellEligibility::isEnabled());
        $this->assertSame(UpsellEligibility::DISABLED, UpsellEligibility::refusal($this->product(), 42));

        \Configuration::resetMocks();
        $this->assertSame(UpsellEligibility::DISABLED, UpsellEligibility::refusal($this->product(), 42));
    }

    /**
     * Produit introuvable ou absent de la boutique
     */
    public function testUnknownProductIsRefused()
    {
        $this->assertSame(UpsellEligibility::UNKNOWN_PRODUCT, UpsellEligibility::refusal(null, 42));
        $this->assertSame(UpsellEligibility::UNKNOWN_PRODUCT, UpsellEligibility::refusal(new \Product(null), 42));
        $this->assertSame(UpsellEligibility::UNKNOWN_PRODUCT, UpsellEligibility::refusal('12', 42));

        \Product::$mockAssociatedToShop = false;
        $this->assertSame(UpsellEligibility::UNKNOWN_PRODUCT, UpsellEligibility::refusal($this->product(), 42));
    }

    /**
     * Inactif, visible nulle part, non disponible à la commande, ou boutique
     * en mode catalogue : la fiche ne propose pas l'ajout
     */
    public function testNotOrderableProductIsRefused()
    {
        $inactive = $this->product();
        $inactive->active = false;
        $this->assertSame(UpsellEligibility::NOT_ORDERABLE, UpsellEligibility::refusal($inactive, 42));

        $hidden = $this->product();
        $hidden->visibility = 'none';
        $this->assertSame(UpsellEligibility::NOT_ORDERABLE, UpsellEligibility::refusal($hidden, 42));

        $notForSale = $this->product();
        $notForSale->available_for_order = false;
        $this->assertSame(UpsellEligibility::NOT_ORDERABLE, UpsellEligibility::refusal($notForSale, 42));

        \Configuration::updateValue('PS_CATALOG_MODE', '1');
        $this->assertSame(UpsellEligibility::NOT_ORDERABLE, UpsellEligibility::refusal($this->product(), 42));
    }

    /**
     * Produit réservé à d'autres groupes de clients
     */
    public function testProductOutOfCustomerGroupsIsRefused()
    {
        \Product::$mockAccess = false;

        $this->assertSame(UpsellEligibility::NO_ACCESS, UpsellEligibility::refusal($this->product(), 42));
        $this->assertSame([42], \Product::$checkAccessCalls);
    }

    /**
     * Pack : exclu par le hook, refusé à l'ajout
     */
    public function testPackIsRefused()
    {
        \Pack::$packs = [12];

        $this->assertSame(UpsellEligibility::PACK, UpsellEligibility::refusal($this->product(12), 42));
        $this->assertNull(UpsellEligibility::refusal($this->product(13), 42));
    }
}
