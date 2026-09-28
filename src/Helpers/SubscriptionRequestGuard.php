<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Règles d'accès aux actions du contrôleur front des abonnements
 * (controllers/front/subscription.php), sans dépendance à PrestaShop.
 *
 * Toute action modifie l'abonnement, sauf l'affichage de la page de choix du
 * point relais : seule celle-ci est servie en GET. Tout le reste exige un POST
 * portant le jeton du client, que la boutique ait ou non activé
 * PS_TOKEN_ENABLE.
 */
class SubscriptionRequestGuard
{
    /** Actions appelées en AJAX par Mes abonnements et la fiche produit : réponse JSON */
    const AJAX_ACTIONS = ['addUpsell', 'updateProductQuantity', 'removeProduct', 'addProduct'];

    /** Actions servies en GET : l'affichage de la page de choix du relais, rien d'autre */
    const GET_ACTIONS = ['relay'];

    /** Quantité maximale d'un produit ou d'un upsell, bornée comme l'API */
    const MAX_QUANTITY = 9999;

    /**
     * L'action répond-elle en JSON ?
     *
     * @param mixed $action
     *
     * @return bool
     */
    public static function isAjaxAction($action): bool
    {
        return is_string($action) && in_array($action, self::AJAX_ACTIONS, true);
    }

    /**
     * La méthode HTTP est-elle acceptée pour cette action ? POST pour toutes,
     * GET seulement pour l'affichage de la page relais.
     *
     * @param mixed $method Méthode de la requête ($_SERVER['REQUEST_METHOD'])
     * @param mixed $action
     *
     * @return bool
     */
    public static function isMethodAllowed($method, $action): bool
    {
        if ('POST' === $method) {
            return true;
        }

        return 'GET' === $method && is_string($action) && in_array($action, self::GET_ACTIONS, true);
    }

    /**
     * Le jeton reçu est-il celui attendu ? Comparaison à temps constant, un
     * jeton absent, vide ou non chaîne (token[]=...) est refusé.
     *
     * @param mixed $expected Jeton du client connecté (Tools::getToken(false))
     * @param mixed $received Jeton reçu dans la requête
     *
     * @return bool
     */
    public static function isTokenValid($expected, $received): bool
    {
        return is_string($expected) && '' !== $expected
            && is_string($received) && '' !== $received
            && hash_equals($expected, $received);
    }

    /**
     * Quantité d'upsell acceptable : entier de 0 (retrait) à MAX_QUANTITY.
     *
     * @param mixed $quantity Valeur reçue (Tools::getValue)
     *
     * @return bool
     */
    public static function isValidUpsellQuantity($quantity): bool
    {
        if (is_int($quantity)) {
            $quantity = (string) $quantity;
        }

        return is_string($quantity)
            && 1 === preg_match('/^[0-9]{1,4}$/D', $quantity)
            && (int) $quantity <= self::MAX_QUANTITY;
    }
}
