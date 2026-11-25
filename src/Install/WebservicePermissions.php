<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Droit de lecture des déclinaisons (ressource combinations) pour la clé webservice du module : Ciklik y lit le nom et
 * la référence d'une déclinaison quand un produit est ajouté à un abonnement depuis son administration.
 *
 * Ajout seulement : les deux lignes GET et HEAD sont insérées si elles manquent, les autres droits de la clé ne sont
 * jamais touchés. WebserviceKey::setPermissionForAccount n'est pas utilisé : il supprime tous les droits de la clé
 * avant de réinsérer la liste reçue, ce qui effacerait un droit ajouté à la main et laisserait la clé sans aucun
 * droit (renouvellements bloqués) si l'insertion échouait.
 *
 * Idempotent : appelé par le script de mise à jour 1.29.0 et à chaque enregistrement de la configuration.
 */
class WebservicePermissions
{
    const COMBINATIONS = 'combinations';

    const READ_METHODS = ['GET', 'HEAD'];

    /**
     * @return bool vrai si la clé du module a le droit de lire les déclinaisons (déjà présent ou ajouté)
     */
    public static function ensureCombinations()
    {
        $accountId = self::accountId();
        if ($accountId <= 0) {
            return false;
        }

        $db = \Db::getInstance();

        foreach (self::missingMethods($accountId) as $method) {
            $db->insert('webservice_permission', [
                'resource' => self::COMBINATIONS,
                'method' => $method,
                'id_webservice_account' => $accountId,
            ]);
        }

        return self::missingMethods($accountId) === [];
    }

    /**
     * @return bool
     */
    public static function hasCombinations()
    {
        $accountId = self::accountId();

        return $accountId > 0 && self::missingMethods($accountId) === [];
    }

    /**
     * Clé du module, seulement si elle existe encore et appartient bien au module : jamais de droit rattaché à une clé
     * supprimée, ni à une autre clé qui aurait repris son identifiant.
     *
     * @return int
     */
    private static function accountId()
    {
        // Valeur du contexte courant, sinon valeur globale (installation multiboutique)
        $accountId = (int) \Configuration::get(\Ciklik::CONFIG_WEBSERVICE_ID);
        if ($accountId <= 0) {
            $accountId = (int) \Configuration::get(\Ciklik::CONFIG_WEBSERVICE_ID, null, 0, 0);
        }
        if ($accountId <= 0) {
            return 0;
        }

        $exists = \Db::getInstance()->getValue(
            'SELECT `id_webservice_account` FROM `' . _DB_PREFIX_ . 'webservice_account`'
            . ' WHERE `id_webservice_account` = ' . $accountId
            . ' AND `is_module` = 1 AND `module_name` = \'ciklik\''
        );

        return (int) $exists === $accountId ? $accountId : 0;
    }

    /**
     * @param int $accountId
     *
     * @return array méthodes de lecture pas encore autorisées sur la ressource combinations
     */
    private static function missingMethods($accountId)
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `method` FROM `' . _DB_PREFIX_ . 'webservice_permission`'
            . ' WHERE `id_webservice_account` = ' . (int) $accountId
            . ' AND `resource` = \'' . pSQL(self::COMBINATIONS) . '\''
        );

        return array_values(array_diff(self::READ_METHODS, is_array($rows) ? array_column($rows, 'method') : []));
    }
}
