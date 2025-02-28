<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Ciklik;
use Configuration;
use Db;
use DbQuery;
use PrestaShopLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikCombination
{
    /**
     * Récupère toutes les combinaisons d'un produit qui ont un attribut de fréquence
     * 
     * @param int $id_product L'identifiant du produit
     * @return array Un tableau contenant les combinaisons avec:
     *               - id_product_attribute: ID de la combinaison
     *               - id_product: ID du produit
     *               - reference: Référence de la combinaison
     *               - price: Prix de la combinaison
     *               - id_attribute: ID de l'attribut de fréquence
     */
    public static function get(int $id_product): array
    {
        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pa.price, pac.id_attribute');
        $query->from('product_attribute', 'pa');
        $query->leftJoin('product_attribute_combination', 'pac', 'pac.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = "' . $id_product . '"');
        $query->where('pac.id_attribute IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group = ' . (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . ')');

        return Db::getInstance()->executeS($query);
    }

    /**
     * Récupère les autres combinaisons d'un produit ayant les mêmes attributs non-fréquentiels
     * qu'une combinaison donnée.
     * 
     * Par exemple, pour une combinaison "T-shirt Rouge Mensuel", cette fonction retournera
     * les combinaisons "T-shirt Rouge Hebdomadaire" et "T-shirt Rouge Trimestriel",
     * mais pas "T-shirt Bleu Mensuel".
     * 
     * @param int $id_product_attribute L'identifiant de la combinaison de référence
     * @return array Un tableau contenant les autres combinaisons avec:
     *               - id_product_attribute: ID de la combinaison
     *               - id_product: ID du produit
     *               - reference: Référence de la combinaison
     *               - id_attribute: ID de l'attribut
     *               - frequency_id: ID de l'attribut de fréquence
     *               - interval_id: ID du groupe d'attributs de fréquence
     */
    public static function getOtherCombinations(int $id_product_attribute): array
    {
        // Récupère les attributs de la combinaison actuelle
        $currentAttributes = Db::getInstance()->executeS('
            SELECT pac.id_attribute, ag.id_attribute_group
            FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group ag ON ag.id_attribute_group = a.id_attribute_group
            WHERE pac.id_product_attribute = ' . (int)$id_product_attribute
        );

        // Récupère l'ID du groupe d'attributs de fréquence
        $frequencyGroupId = (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);
        $nonFrequencyAttributes = [];

        // Identifie les attributs qui ne sont pas liés à la fréquence
        foreach ($currentAttributes as $attr) {
            if ($attr['id_attribute_group'] != $frequencyGroupId) {
                $nonFrequencyAttributes[] = $attr['id_attribute'];
            }
        }

        // Prépare la requête pour récupérer les autres combinaisons
        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pac.id_attribute, a.id_attribute as frequency_id, ag.id_attribute_group as interval_id');
        $query->from('product_attribute', 'pa');
        $query->leftJoin('product_attribute_combination', 'pac', 'pac.id_product_attribute = pa.id_product_attribute');
        $query->leftJoin('attribute', 'a', 'a.id_attribute = pac.id_attribute');
        $query->leftJoin('attribute_group', 'ag', 'ag.id_attribute_group = a.id_attribute_group');
        $query->where('pa.id_product = (SELECT id_product FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . (int)$id_product_attribute . ')');
        $query->where('pa.id_product_attribute != ' . (int)$id_product_attribute);
        $query->where('pac.id_attribute IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group = ' . $frequencyGroupId . ')');
        
        // Ajoute une condition pour correspondre aux attributs non liés à la fréquence
        if (!empty($nonFrequencyAttributes)) {
            $query->where('pa.id_product_attribute IN (
                SELECT pac.id_product_attribute 
                FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac 
                WHERE pac.id_attribute IN (' . implode(',', array_map('intval', $nonFrequencyAttributes)) . ')
                GROUP BY pac.id_product_attribute 
                HAVING COUNT(DISTINCT pac.id_attribute) = ' . count($nonFrequencyAttributes) . '
            )');
        }

        // Exécute la requête
        $results = Db::getInstance()->executeS($query);

        // Enrichit les résultats avec des informations supplémentaires
        foreach ($results as &$result) {
            // Récupère le prix de la combinaison
            //$result['price'] = \Product::getPriceStatic($result['id_product'], true, $result['id_product_attribute']);
            
            // Récupère les informations du produit, de l'attribut et du groupe d'attributs
            $language_id = \Context::getContext()->language->id;
            $product = new \Product($result['id_product'], false, $language_id);
            $attribute = new \Attribute($result['frequency_id'], $language_id);
            $attributeGroup = new \AttributeGroup($result['interval_id'], $language_id);
            
            // Formate le nom d'affichage de la combinaison
            $result['display_name'] = sprintf(
                '%s : %s',
                $attributeGroup->name,
                $attribute->name
            );
        }

        return $results;
    }


    /**
     * Récupère les combinaisons correspondantes pour une nouvelle fréquence
     * 
     * @param array $new_combination Détails de la nouvelle combinaison avec la fréquence souhaitée
     * @param array $current_external_ids Tableau des IDs de combinaisons actuelles à faire correspondre
     * @return array|null Tableau des IDs de combinaisons correspondantes ou null si aucune correspondance trouvée
     */
    public static function getMatchingCombinations(array $new_combination, int $current_external_id)
    {

        // Récupère les IDs de fréquence et d'intervalle depuis la nouvelle combinaison
        // Les IDs de fréquence et d'intervalle sont déjà présents dans $new_combination
        $frequency_id = (int)$new_combination['frequency_id'];
        $interval_id = (int)$new_combination['interval_id'];

        // Pour chaque ID de combinaison actuelle, trouve la combinaison correspondante avec la nouvelle fréquence
        $matching_combinations = [];
        // Récupère l'ID du produit pour la combinaison actuelle
        $query = new DbQuery();
        $query->select('id_product');
        $query->from('product_attribute');
        $query->where('id_product_attribute = ' . (int)$current_external_id);
        
        $product_id = Db::getInstance()->getValue($query);

        if (!$product_id) {
            return null;
        }

        // Trouve la combinaison correspondante pour ce produit avec la nouvelle fréquence
        // Récupérer d'abord les attributs de la combinaison actuelle (sauf fréquence)
        $currentAttributesQuery = new DbQuery();
        $currentAttributesQuery->select('pac.id_attribute');
        $currentAttributesQuery->from('product_attribute_combination', 'pac');
        $currentAttributesQuery->innerJoin('attribute', 'a', 'a.id_attribute = pac.id_attribute');
        $currentAttributesQuery->where('pac.id_product_attribute = ' . (int)$current_external_id);
        $currentAttributesQuery->where('a.id_attribute_group != ' . (int)$interval_id);
        
        $currentAttributes = array_column(Db::getInstance()->executeS($currentAttributesQuery), 'id_attribute');

        // Construire la requête pour trouver la combinaison avec les mêmes attributs mais une fréquence différente
        $query = new DbQuery();
        $query->select('DISTINCT pac.id_product_attribute, cf.interval, cf.interval_count');
        $query->from('product_attribute_combination', 'pac');
        $query->innerJoin('product_attribute', 'pa', 'pa.id_product_attribute = pac.id_product_attribute');
        $query->innerJoin('ciklik_frequencies', 'cf', 'cf.id_attribute = pac.id_attribute');
        $query->where('pa.id_product = ' . (int)$product_id);
        $query->where('pac.id_attribute = ' . (int)$frequency_id);

        // Pour chaque attribut actuel, ajouter une condition EXISTS
        foreach ($currentAttributes as $attributeId) {
            $query->where('EXISTS (
                SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute_combination pac2 
                WHERE pac2.id_product_attribute = pac.id_product_attribute 
                AND pac2.id_attribute = ' . (int)$attributeId . '
            )');
        }

        $matching = Db::getInstance()->getRow($query);

        if (!$matching) {
            PrestaShopLogger::addLog(
                'No matching combination found for product ' . $product_id . ' with frequency ' . $frequency_id . ' and current attributes ' . implode(',', $currentAttributes), // Message
                2, // Niveau de sévérité (1 = Erreur, 2 = Avertissement, 3 = Info)
                null, // Objet à l'origine du log (null si aucun)
                'ciklik', // Nom du module ou du composant (optionnel)
                null, // Identifiant de l'objet lié (optionnel)
                true // Permet d'afficher ou non l'erreur aux administrateurs (true = affiché)
            );
        }
        
        return $matching ? $matching : null;
    }


    /**
     * Récupère les informations détaillées d'une combinaison de produit spécifique
     * 
     * @param int $id_product_attribute L'ID de la combinaison de produit dont on veut récupérer les détails
     * 
     * @return array|null Retourne un tableau contenant :
     *                    - id_product_attribute : L'ID de la combinaison
     *                    - id_product : L'ID du produit parent
     *                    - reference : Le code référence de la combinaison
     *                    - price : Le prix de la combinaison
     *                    - display_name : Nom formaté avec tous les attributs
     *                    - frequency_id : ID de l'attribut de fréquence si présent
     *                    - interval_id : ID de l'attribut d'intervalle si présent
     *                    Plus tous les IDs de groupes d'attributs en clés avec leurs IDs d'attributs en valeurs
     *                    Retourne null si la combinaison n'est pas trouvée
     */
    public static function getCombinationDetails(int $id_product_attribute): ?array
    {
        // Get basic combination info
        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pa.price');
        $query->from('product_attribute', 'pa');
        $query->where('pa.id_product_attribute = ' . (int)$id_product_attribute);
        
        $combination = Db::getInstance()->getRow($query);
        
        if (!$combination) {
            return null;
        }

        // Get all attributes for this combination
        $query = new DbQuery();
        $query->select('a.id_attribute, a.id_attribute_group, al.name as attribute_name, agl.name as group_name');
        $query->from('product_attribute_combination', 'pac');
        $query->leftJoin('attribute', 'a', 'pac.id_attribute = a.id_attribute');
        $query->leftJoin('attribute_lang', 'al', 'a.id_attribute = al.id_attribute AND al.id_lang = ' . (int)\Context::getContext()->language->id);
        $query->leftJoin('attribute_group_lang', 'agl', 'a.id_attribute_group = agl.id_attribute_group AND agl.id_lang = ' . (int)\Context::getContext()->language->id);
        $query->where('pac.id_product_attribute = ' . (int)$id_product_attribute);

        $attributes = Db::getInstance()->executeS($query);

        // Build display name and organize attributes
        $displayParts = [];
        $frequency_id = null;
        $interval_id = null;
        $attributesByGroup = [];

        foreach ($attributes as $attribute) {
            $displayParts[] = $attribute['attribute_name'];
            $attributesByGroup[$attribute['id_attribute_group']] = $attribute['id_attribute'];

            // Check if this is frequency attribute
            if ($attribute['id_attribute_group'] == Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID)) {
                $frequency_id = $attribute['id_attribute'];
                $interval_id = $attribute['id_attribute_group'];
            }
        }

        return [
            'id_product_attribute' => (int)$combination['id_product_attribute'],
            'id_product' => (int)$combination['id_product'],
            'reference' => $combination['reference'],
            'price' => $combination['price'],
            'display_name' => implode(' - ', $displayParts),
            'frequency_id' => $frequency_id,
            'interval_id' => $interval_id
        ];
    }



    /**
     * Récupère tous les identifiants d'attributs produit pour un produit donné
     * 
     * @param int $id_product L'identifiant du produit pour lequel récupérer les combinaisons
     * @return array Tableau des identifiants d'attributs produit
     */
    public static function getIds(int $id_product): array
    {
        $ids = [];
        $combinations = static::get($id_product);

        if (count($combinations)) {
            foreach ($combinations as $combination) {
                $ids[] = $combination['id_product_attribute'];
            }
        }

        return $ids;
    }

    /**
     * Récupère une combinaison spécifique d'un produit avec une fréquence donnée et des contraintes d'attributs
     * 
     * Cette méthode permet de trouver une combinaison précise en filtrant sur :
     * - Un produit spécifique
     * - Un attribut de fréquence (ex: mensuel, hebdomadaire)
     * - Des attributs supplémentaires optionnels (ex: taille, couleur)
     *
     * @param int $id_product L'identifiant du produit
     * @param int $frequency_id_attribute L'identifiant de l'attribut de fréquence recherché
     * @param array $constraint_attributes_ids Tableau optionnel d'identifiants d'attributs additionnels pour filtrer la recherche
     * 
     * @return array|false Les données de la combinaison trouvée avec:
     *                     - id_product_attribute: ID de la combinaison
     *                     - id_product: ID du produit
     *                     - reference: Référence de la combinaison
     *                     - price: Prix de la combinaison
     *                     - id_attribute: ID de l'attribut
     *                     Retourne false si aucune combinaison n'est trouvée
     */
    public static function getOne(
        int $id_product,
        int $frequency_id_attribute,
        array $constraint_attributes_ids = [])
    {
        // Crée une nouvelle requête SQL
        $query = new DbQuery();
        // Sélectionne les champs nécessaires de la combinaison de produit
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pa.price, pac.id_attribute');
        // Table principale des combinaisons de produits
        $query->from('product_attribute', 'pa');
        // Joint avec la table des associations attributs-combinaisons
        $query->leftJoin('product_attribute_combination', 'pac', 'pa.id_product_attribute = pac.id_product_attribute');
        // Filtre sur l'ID du produit
        $query->where('pa.id_product = ' . $id_product);
        // Filtre sur l'attribut de fréquence
        $query->where('pac.id_attribute = ' . $frequency_id_attribute);

        // Si des attributs de contrainte sont fournis
        if (count($constraint_attributes_ids)) {
            // Inverse l'ordre des attributs pour construire les sous-requêtes
            $constraint_attributes_ids = array_reverse($constraint_attributes_ids);
            $constraint_queries = [];
            $i = 0;
            // Pour chaque attribut de contrainte
            foreach ($constraint_attributes_ids as $constraint_attribute_id) {
                // Crée une sous-requête pour l'attribut
                $constraint_query = new DbQuery();
                $constraint_query->select('pa.id_product_attribute');
                $constraint_query->from('product_attribute', 'pa');
                $constraint_query->leftJoin('product_attribute_combination', 'pac', 'pa.id_product_attribute = pac.id_product_attribute');
                $constraint_query->where('pa.id_product = ' . $id_product);
                $constraint_query->where('pac.id_attribute = ' . $constraint_attribute_id);
                // Si une contrainte précédente existe, l'ajoute à la sous-requête
                if (array_key_exists($i - 1, $constraint_queries)) {
                    $constraint_query->where($constraint_queries[$i - 1]);
                }
                // Ajoute la sous-requête aux contraintes
                $constraint_queries[] = 'pa.id_product_attribute IN (' . $constraint_query->build() . ')';
                ++$i;
            }

            // Ajoute la dernière contrainte à la requête principale
            $query->where(end($constraint_queries));
        }

        // Exécute la requête et retourne la première ligne
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
    }

    /**
     * Vérifie si une combinaison de produit est éligible à l'abonnement
     * 
     * Cette fonction détermine si une combinaison de produit donnée possède un attribut
     * de fréquence, ce qui la rend éligible à l'abonnement. Elle vérifie dans la base
     * de données si la combinaison est associée à un attribut appartenant au groupe
     * d'attributs de fréquence configuré.
     *
     * @param int $id_product_attribute L'identifiant de la combinaison de produit à vérifier
     * @return bool Retourne true si la combinaison est éligible à l'abonnement, false sinon
     */
    public static function isSubscribable(int $id_product_attribute): bool
    {
        // Crée une nouvelle requête SQL
        $query = new DbQuery();
        // Sélectionne l'ID de l'attribut
        $query->select('pac.`id_attribute`');
        // Depuis la table des associations attributs-combinaisons
        $query->from('product_attribute_combination', 'pac');
        // Joint avec la table des attributs pour accéder au groupe d'attributs
        $query->leftJoin('attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`');
        // Filtre sur l'ID de la combinaison
        $query->where('pac.`id_product_attribute` = "' . $id_product_attribute . '"');
        // Vérifie que l'attribut appartient au groupe des fréquences
        $query->where('a.`id_attribute_group` = "' . (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . '"');

        // Retourne true si un attribut de fréquence est trouvé
        return (int) Db::getInstance()->getValue($query) > 0;
    }
}
