<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Tests\Integration;

use PrestaShop\Module\Ciklik\Managers\CiklikCategoryFrequencyAssigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Activation de fréquences par catégorie sur une vraie base : recherche des produits avec et sans
 * sous-catégories, exclusions, ajout idempotent, catégories refusées
 */
class CategoryFrequencyAssignerTest extends IntegrationTestCase
{
    /** @var int */
    private $frequency;

    /** @var int */
    private $idLang;

    /** @var int */
    private $idShop;

    protected function setUp(): void
    {
        $this->frequency = (int) \Db::getInstance()->getValue('SELECT id_frequency FROM `' . _DB_PREFIX_ . 'ciklik_frequency` ORDER BY id_frequency');
        if (!$this->frequency) {
            $this->markTestSkipped('Aucune fréquence du mode fréquences enregistrée.');
        }
        $this->idLang = (int) \Context::getContext()->language->id;
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    public function testFindsProductsWithAndWithoutSubcategories()
    {
        $parent = $this->createCategory('IT catégorie parente');
        $child = $this->createCategory('IT sous-catégorie', $parent);
        $this->createProduct('IT actif parent', $parent);
        $this->createProduct('IT pack parent', $parent, 0, ['cache_is_pack' => 1]);
        $this->createProduct('IT actif enfant', $child);
        $this->createProduct('IT inactif enfant', $child, 0, ['active' => 0]);
        $this->createProduct('IT virtuel enfant', $child, 0, ['is_virtual' => 1]);

        $only = $this->preview($parent, false);
        $this->assertSame(2, $only['found']);
        $this->assertSame(['pack' => 1, 'virtual' => 0, 'inactive' => 0], $only['excluded']);
        $this->assertSame(1, $only['updated']);

        $all = $this->preview($parent, true);
        $this->assertSame(5, $all['found']);
        $this->assertSame(['pack' => 1, 'virtual' => 1, 'inactive' => 1], $all['excluded']);
        $this->assertSame(2, $all['updated']);
        $this->assertSame(2, $all['rows']);
    }

    public function testApplyAddsOnlyMissingAssociations()
    {
        $category = $this->createCategory('IT catégorie ajout');
        $this->createProduct('IT ajout un', $category);
        $this->createProduct('IT ajout deux', $category);

        $plan = $this->preview($category, true);
        $this->assertSame(2, CiklikCategoryFrequencyAssigner::apply($plan));
        // Rejouer le même aperçu n'ajoute rien
        $this->assertSame(0, CiklikCategoryFrequencyAssigner::apply($plan));

        $again = $this->preview($category, true);
        $this->assertSame(0, $again['rows']);
        $this->assertSame(2, $again['unchanged']);
    }

    public function testRootAndUnknownCategoriesAreRefused()
    {
        foreach ([(int) \Configuration::get('PS_ROOT_CATEGORY'), 999999999] as $idCategory) {
            try {
                $this->preview($idCategory, true);
                $this->fail('catégorie ' . $idCategory . ' acceptée');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('unknown_category', $e->getMessage());
            }
        }
    }

    private function preview(int $idCategory, bool $withChildren): array
    {
        return CiklikCategoryFrequencyAssigner::preview($idCategory, $withChildren, [$this->frequency], $this->idLang, $this->idShop);
    }
}
