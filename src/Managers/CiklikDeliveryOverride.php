<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Override de point relais posé par le marchand en back-office.
 *
 * Source de vérité pour les prochains rebills : les drivers de
 * {@see DeliveryModuleManager} consultent l'override avant de retomber sur le
 * clonage historique (dernière commande payée). Clé : un override par couple
 * (client, module transporteur) — partagé entre les abonnements d'un même
 * client sur le même transporteur (limite v1 documentée dans la conception).
 */
class CiklikDeliveryOverride
{
    /**
     * Modules transporteur relais supportés. Doit rester aligné avec les
     * drivers présents dans DeliveryModuleManager.
     */
    const SUPPORTED_MODULES = ['mondialrelay', 'dpdfrance', 'colissimo', 'nkmgls', 'chronopost'];

    /**
     * Récupère l'override d'un client pour un module transporteur.
     *
     * @param int $idCustomer ID client PrestaShop
     * @param string $carrierModule Nom du module transporteur (minuscules)
     *
     * @return array|null ['relay_id' => string, 'payload' => array] ou null si aucun override
     */
    public static function get(int $idCustomer, string $carrierModule)
    {
        // Défensif : un échec de lecture de l'override ne doit jamais remonter
        // au driver appelant — le rebill retombe alors sur le clonage historique.
        try {
            $query = new \DbQuery();
            $query->select('relay_id, payload');
            $query->from('ciklik_delivery_override');
            $query->where('id_customer = ' . (int) $idCustomer);
            $query->where("carrier_module = '" . pSQL(strtolower($carrierModule)) . "'");

            $row = \Db::getInstance()->getRow($query);
        } catch (\Exception $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);

        return [
            'relay_id' => (string) $row['relay_id'],
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * Crée ou remplace l'override d'un client pour un module transporteur.
     *
     * @param int $idCustomer ID client PrestaShop
     * @param string $carrierModule Nom du module transporteur (minuscules)
     * @param string $relayId Identifiant du relais chez le transporteur
     * @param array $payload Champs propres au transporteur (voir conception §5)
     *
     * @return bool
     */
    public static function save(int $idCustomer, string $carrierModule, string $relayId, array $payload): bool
    {
        $carrierModule = strtolower($carrierModule);

        if ($idCustomer <= 0
            || '' === trim($relayId)
            || !in_array($carrierModule, self::SUPPORTED_MODULES, true)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        // REPLACE INTO (atomique sur la clé unique id_customer/carrier_module) :
        // upsert sans la fenêtre de course d'un DELETE puis INSERT séparés, où un
        // rebill lisant entre les deux ne verrait aucun override.
        return \Db::getInstance()->insert(
            'ciklik_delivery_override',
            [
                'id_customer' => (int) $idCustomer,
                'carrier_module' => pSQL($carrierModule),
                'relay_id' => pSQL($relayId),
                'payload' => pSQL((string) json_encode($payload), true),
                'date_add' => pSQL($now),
                'date_upd' => pSQL($now),
            ],
            false,
            true,
            \Db::REPLACE
        );
    }

    /**
     * Supprime l'override : retour au comportement automatique (clonage
     * depuis la dernière commande payée) au prochain rebill.
     *
     * @param int $idCustomer ID client PrestaShop
     * @param string $carrierModule Nom du module transporteur (minuscules)
     *
     * @return bool
     */
    public static function delete(int $idCustomer, string $carrierModule): bool
    {
        return \Db::getInstance()->delete(
            'ciklik_delivery_override',
            'id_customer = ' . (int) $idCustomer
                . " AND carrier_module = '" . pSQL(strtolower($carrierModule)) . "'"
        );
    }
}
