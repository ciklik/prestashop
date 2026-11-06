<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Tests\Integration;

use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Données de test créées dans la boutique, puis supprimées après chaque test
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var int[] */
    private $products = [];

    /** @var int[] */
    private $categories = [];

    /** @var int[] */
    private $attributeGroups = [];

    protected function tearDown(): void
    {
        $db = \Db::getInstance();
        foreach (array_reverse($this->products) as $idProduct) {
            \Configuration::deleteByName('CIKLIK_TRANSFORM_' . $idProduct);
            \Configuration::deleteByName('CIKLIK_SYNC_FAILED_' . (int) \Context::getContext()->shop->id . '_' . $idProduct);
            $db->delete('ciklik_product_frequency', 'id_product = ' . (int) $idProduct);
            $product = new \Product($idProduct);
            if (\Validate::isLoadedObject($product)) {
                $product->delete();
            }
        }
        foreach (array_reverse($this->categories) as $idCategory) {
            $category = new \Category($idCategory);
            if (\Validate::isLoadedObject($category)) {
                $category->delete();
            }
        }
        foreach ($this->attributeGroups as $idGroup) {
            $group = new \AttributeGroup($idGroup);
            if (\Validate::isLoadedObject($group)) {
                $group->delete();
            }
        }
        $this->products = $this->categories = $this->attributeGroups = [];
    }

    /**
     * @return array Texte identique dans toutes les langues
     */
    protected static function lang(string $text): array
    {
        $values = [];
        foreach (\Language::getIDs(false) as $idLang) {
            $values[(int) $idLang] = $text;
        }

        return $values;
    }

    protected function createCategory(string $name, int $idParent = 0): int
    {
        $category = new \Category();
        $category->name = self::lang($name);
        $category->link_rewrite = self::lang(\Tools::str2url($name));
        $category->id_parent = $idParent ?: (int) \Configuration::get('PS_HOME_CATEGORY');
        $category->active = 1;
        $this->assertTrue((bool) $category->add(), 'catégorie ' . $name);
        $this->categories[] = (int) $category->id;

        return (int) $category->id;
    }

    /**
     * Produit simple ; $flags : active (1 par défaut), is_virtual, cache_is_pack
     */
    protected function createProduct(string $name, int $idCategory, int $quantity = 0, array $flags = []): int
    {
        $product = new \Product();
        $product->name = self::lang($name);
        $product->link_rewrite = self::lang(\Tools::str2url($name));
        $product->id_category_default = $idCategory;
        $product->price = 10;
        $product->reference = 'IT-' . strtoupper(substr(md5($name), 0, 8));
        $product->ean13 = '3700000099' . sprintf('%03d', count($this->products));
        $product->active = isset($flags['active']) ? (int) $flags['active'] : 1;
        $this->assertTrue((bool) $product->add(), 'produit ' . $name);
        $this->products[] = (int) $product->id;
        $product->addToCategories([$idCategory]);
        \StockAvailable::setQuantity((int) $product->id, 0, $quantity);

        // Pack et virtuel posés directement : seuls ces drapeaux comptent pour le module
        $extra = array_intersect_key($flags, ['is_virtual' => 1, 'cache_is_pack' => 1]);
        if ($extra) {
            \Db::getInstance()->update('product', array_map('intval', $extra), 'id_product = ' . (int) $product->id);
        }

        return (int) $product->id;
    }

    /**
     * Groupe d'attributs de test et ses valeurs
     *
     * @return int[] id_attribute
     */
    protected function createAttributes(string $groupName, array $names): array
    {
        $group = new \AttributeGroup();
        $group->name = self::lang($groupName);
        $group->public_name = self::lang($groupName);
        $group->group_type = 'select';
        $this->assertTrue((bool) $group->add(), 'groupe ' . $groupName);
        $this->attributeGroups[] = (int) $group->id;

        // ProductAttribute depuis PrestaShop 8, Attribute avant
        $class = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
        $ids = [];
        foreach ($names as $name) {
            $attribute = new $class();
            $attribute->id_attribute_group = (int) $group->id;
            $attribute->name = self::lang($name);
            $this->assertTrue((bool) $attribute->add(), 'attribut ' . $name);
            $ids[] = (int) $attribute->id;
        }

        return $ids;
    }

    /**
     * @param int|int[] $attributes
     */
    protected function addCombination(int $idProduct, $attributes, string $reference, string $ean, int $quantity): int
    {
        $combination = new \Combination();
        $combination->id_product = $idProduct;
        $combination->reference = $reference;
        $combination->ean13 = $ean;
        $combination->price = 0;
        $this->assertTrue((bool) $combination->add(), 'déclinaison ' . $reference);
        $combination->setAttributes((array) $attributes);
        \StockAvailable::setQuantity($idProduct, (int) $combination->id, $quantity);

        return (int) $combination->id;
    }
}
