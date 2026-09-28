<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Sql\SqlQueries;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mise à niveau 1.24.0, pour les installations existantes : changement de
 * point relais depuis l'espace client.
 *
 * Crée la table ciklik_relay_search_quota, compteur des recherches de relais
 * par client et par boutique (RelaySearchQuota). Aucun réglage ni hook
 * nouveau : la fonction est active dès la mise à jour pour tout abonnement
 * livré en point relais.
 *
 * Comme en 1.23.0, l'échec de création ne fait pas échouer le script :
 * PrestaShop désactiverait le module (un moyen de paiement) et monterait
 * quand même la version en base, sans jamais rejouer le script. Sans la
 * table, la recherche de relais est refusée côté client (le compteur est
 * inaccessible), les relais déjà utilisés restent proposés, et le journal
 * porte l'erreur.
 */
function upgrade_module_1_24_0($module)
{
    foreach (SqlQueries::installRelaySearchQuotaQueries() as $query) {
        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'Ciklik upgrade 1.24.0 - création de ciklik_relay_search_quota échouée : '
                    . Db::getInstance()->getMsgError(),
                3
            );
        }
    }

    return true;
}
