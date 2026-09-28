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
     * Longueur maximale de l'identifiant relais, par module, alignée sur les
     * colonnes réelles des tables transporteurs (num varchar(6) Mondial Relay,
     * colissimo_id varchar(8), relay_id varchar(8) DPD, id_pr varchar(10)
     * Chronopost). PrestaShop forçant sql_mode='' à la connexion, un
     * identifiant trop long serait sinon tronqué silencieusement en base et
     * produirait un code invalide sur l'étiquette du rebill.
     */
    const RELAY_ID_MAX_LENGTHS = [
        'mondialrelay' => 6,
        'colissimo' => 8,
        'dpdfrance' => 8,
        'nkmgls' => 20,
        'chronopost' => 10,
    ];

    /**
     * Champs de payload acceptés : longueur max et méthode Validate
     * éventuelle. Règle unique pour le back-office (manage.php) et l'espace
     * client (CiklikSubscriptionRelay::buildPayload). Les champs sans
     * validateur (formats propriétaires des transporteurs) sont seulement
     * nettoyés et bornés. Les valeurs finissent sur des étiquettes et dans
     * les flux transporteurs : pas de balises, pas de caractères de contrôle
     * ni de retours-ligne.
     */
    const RELAY_PAYLOAD_RULES = [
        'name' => ['max' => 64, 'validate' => 'isGenericName'],
        'name2' => ['max' => 64, 'validate' => 'isGenericName'],
        'address1' => ['max' => 128, 'validate' => 'isAddress'],
        'address2' => ['max' => 128, 'validate' => 'isAddress'],
        'zipcode' => ['max' => 12, 'validate' => 'isPostCode'],
        'city' => ['max' => 64, 'validate' => 'isCityName'],
        'country_iso' => ['max' => 2, 'validate' => null],
        'phone' => ['max' => 32, 'validate' => 'isPhoneNumber'],
        'product_code' => ['max' => 16, 'validate' => 'isGenericName'],
        'network' => ['max' => 16, 'validate' => 'isGenericName'],
        // Horaires GLS : json_encode du GLSWorkingDay du module, ~640 caractères
        // pour 6 jours d'ouverture (mesuré sur les données réelles). Un plafond
        // à 255 rejetait la sélection de tout relais connu GLS.
        'parcel_shop_working_day' => ['max' => 4000, 'validate' => null],
    ];

    /**
     * Nettoie une valeur de champ de payload : balises, caractères de
     * contrôle et retours-ligne remplacés, espaces de bord retirés. Une
     * valeur non scalaire devient vide.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function cleanPayloadValue($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags((string) $value));

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Le payload nettoyé respecte-t-il RELAY_PAYLOAD_RULES : champs connus
     * seulement, chaînes bornées, validateur PrestaShop satisfait, code pays
     * ISO alpha-2 ? Un payload vide est valide (relais sans détail).
     *
     * @param array $payload
     *
     * @return bool
     */
    public static function isValidPayload(array $payload): bool
    {
        foreach ($payload as $field => $value) {
            if (!is_string($field) || !isset(self::RELAY_PAYLOAD_RULES[$field]) || !is_string($value)) {
                return false;
            }

            $rules = self::RELAY_PAYLOAD_RULES[$field];

            if (mb_strlen($value, 'UTF-8') > $rules['max']) {
                return false;
            }

            if ($rules['validate'] && !call_user_func(['\Validate', $rules['validate']], $value)) {
                return false;
            }
        }

        // Le code pays doit rester un ISO alpha-2 exploitable par les drivers
        if (isset($payload['country_iso']) && !preg_match('/^[a-zA-Z]{2}$/', $payload['country_iso'])) {
            return false;
        }

        return true;
    }

    /**
     * L'identifiant relais est-il acceptable pour ce module : caractères
     * alphanumériques (tiret et souligné compris) et longueur bornée ?
     * Règle commune au back-office et à l'espace client.
     *
     * @param string $carrierModule Nom du module transporteur (minuscules)
     * @param string $relayId
     *
     * @return bool
     */
    public static function isValidRelayId(string $carrierModule, string $relayId): bool
    {
        $carrierModule = strtolower($carrierModule);

        if (!in_array($carrierModule, self::SUPPORTED_MODULES, true)
            || !preg_match('/^[a-zA-Z0-9_-]+$/D', $relayId)) {
            return false;
        }

        // La regex garantit un identifiant ASCII : strlen suffit
        return !isset(self::RELAY_ID_MAX_LENGTHS[$carrierModule])
            || strlen($relayId) <= self::RELAY_ID_MAX_LENGTHS[$carrierModule];
    }

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
