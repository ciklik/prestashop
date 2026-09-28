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
 * Plafond d'appels à l'API transporteur depuis l'espace client, tenu en base
 * (table ciklik_relay_search_quota), par client et par boutique.
 *
 * Un compteur en cookie se remettait à zéro à la déconnexion : le plafond
 * tenait à la session, pas au client. Ici le compteur suit le compte client,
 * et un second plafond, global à la boutique, borne la consommation du
 * compte transporteur quels que soient les clients.
 *
 * Fenêtre glissante simple : le compteur du client démarre au premier appel
 * et repart de zéro une fois la fenêtre écoulée. Le total boutique additionne
 * les compteurs dont la fenêtre est encore ouverte (borne haute, jamais en
 * dessous du réel).
 *
 * La lecture, la décision et l'écriture se font sous un verrou nommé par
 * boutique (GET_LOCK) : deux recherches simultanées ne peuvent plus lire le
 * même compteur et dépasser ensemble un plafond.
 */
class RelaySearchQuota
{
    /** Recherche accordée, compteur incrémenté */
    const GRANTED = 'granted';

    /** Plafond du client ou de la boutique atteint pour la fenêtre en cours */
    const LIMITED = 'limited';

    /** Compteur inaccessible (verrou, base, table absente) : recherche refusée, journalisée */
    const UNAVAILABLE = 'unavailable';

    /** Appels autorisés par client et par fenêtre */
    const LIMIT = 20;

    /** Appels autorisés par boutique et par fenêtre, tous clients confondus */
    const SHOP_LIMIT = 1000;

    /** Durée de la fenêtre, en secondes */
    const WINDOW = 3600;

    /** Attente maximale du verrou, en secondes */
    const LOCK_TIMEOUT = 2;

    /**
     * Décision pure, sans accès à la base : le client peut-il consommer un
     * appel maintenant, et quel compteur persister ?
     *
     * @param mixed $row Compteur du client ['window_start' => int, 'count' => int], null ou corrompu = aucun
     * @param mixed $shopTotal Appels de la boutique sur les fenêtres ouvertes, ce client compris
     * @param int $now Horodatage courant
     * @param int $limit
     * @param int $window
     * @param int $shopLimit
     *
     * @return array ['allowed' => bool, 'window_start' => int, 'count' => int] compteur à persister
     */
    public static function decide($row, $shopTotal, int $now, int $limit = self::LIMIT, int $window = self::WINDOW, int $shopLimit = self::SHOP_LIMIT): array
    {
        $count = 0;
        $start = $now;

        if (is_array($row) && isset($row['window_start'], $row['count'])
            && is_numeric($row['window_start']) && is_numeric($row['count'])) {
            $count = max(0, (int) $row['count']);
            $start = (int) $row['window_start'];

            // Fenêtre écoulée, ou début dans le futur (horloge douteuse)
            if ($start > $now || $now - $start >= $window) {
                $count = 0;
                $start = $now;
            }
        }

        if ($count >= $limit) {
            return ['allowed' => false, 'window_start' => $start, 'count' => $count];
        }

        // Plafond boutique : le total inclut déjà le compteur courant du
        // client quand sa fenêtre est ouverte
        $total = is_numeric($shopTotal) ? max(0, (int) $shopTotal) : 0;
        if ($total >= $shopLimit) {
            return ['allowed' => false, 'window_start' => $start, 'count' => $count];
        }

        return ['allowed' => true, 'window_start' => $start, 'count' => $count + 1];
    }

    /**
     * Nom du verrou MySQL d'une boutique. Les verrous nommés valent pour tout
     * le serveur : base et préfixe y entrent pour qu'une autre boutique
     * hébergée sur le même serveur ne partage pas le verrou. 64 caractères
     * au plus.
     *
     * @param int $idShop
     *
     * @return string
     */
    public static function lockName(int $idShop): string
    {
        $database = defined('_DB_NAME_') ? (string) _DB_NAME_ : '';

        return 'ciklik_rsq_' . md5($database . '|' . _DB_PREFIX_ . '|' . $idShop);
    }

    /**
     * Consomme un appel pour ce client sur cette boutique, si les plafonds le
     * permettent. Toute erreur (verrou non obtenu, base, table absente)
     * refuse l'appel et est journalisée : sans compteur fiable, pas d'appel
     * au compte transporteur.
     *
     * @param int $idCustomer
     * @param int $idShop
     * @param int|null $now Horodatage courant (tests), time() sinon
     *
     * @return string self::GRANTED, self::LIMITED ou self::UNAVAILABLE
     */
    public static function consume(int $idCustomer, int $idShop, $now = null): string
    {
        $now = null === $now ? time() : (int) $now;

        if ($idCustomer <= 0) {
            return self::UNAVAILABLE;
        }

        $lockName = self::lockName($idShop);
        $locked = false;

        try {
            $db = \Db::getInstance();
            $table = _DB_PREFIX_ . 'ciklik_relay_search_quota';

            // Lectures sans le cache de requêtes de PrestaShop : un résultat
            // mis en cache fausserait le verrou comme le compteur
            $locked = 1 === (int) $db->getValue(
                'SELECT GET_LOCK(\'' . pSQL($lockName) . '\', ' . (int) self::LOCK_TIMEOUT . ')',
                false
            );

            if (!$locked) {
                self::logUnavailable('verrou non obtenu', $idCustomer);

                return self::UNAVAILABLE;
            }

            $row = $db->getRow(
                'SELECT window_start, `count` FROM `' . $table . '`'
                . ' WHERE id_customer = ' . (int) $idCustomer . ' AND id_shop = ' . (int) $idShop,
                false
            );
            $shopTotal = $db->getValue(
                'SELECT COALESCE(SUM(`count`), 0) FROM `' . $table . '`'
                . ' WHERE id_shop = ' . (int) $idShop . ' AND window_start > ' . (int) ($now - self::WINDOW),
                false
            );

            $decision = self::decide(is_array($row) ? $row : null, $shopTotal, $now, self::LIMIT, self::WINDOW, self::SHOP_LIMIT);

            if (!$decision['allowed']) {
                return self::LIMITED;
            }

            // REPLACE sur la clé unique (client, boutique) : création ou
            // remplacement du compteur en une requête
            $saved = $db->execute(
                'REPLACE INTO `' . $table . '` (id_customer, id_shop, window_start, `count`) VALUES ('
                . (int) $idCustomer . ', ' . (int) $idShop . ', '
                . (int) $decision['window_start'] . ', ' . (int) $decision['count'] . ')'
            );

            if (!$saved) {
                self::logUnavailable('écriture refusée', $idCustomer);

                return self::UNAVAILABLE;
            }

            // Ménage des compteurs de la boutique dont la fenêtre est close
            // depuis longtemps : la table reste de la taille des clients actifs
            $db->execute(
                'DELETE FROM `' . $table . '` WHERE id_shop = ' . (int) $idShop
                . ' AND window_start < ' . (int) ($now - 2 * self::WINDOW)
            );

            return self::GRANTED;
        } catch (\Throwable $e) {
            self::logUnavailable(get_class($e), $idCustomer);

            return self::UNAVAILABLE;
        } finally {
            if ($locked) {
                try {
                    \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lockName) . '\')', false);
                } catch (\Throwable $e) {
                    // Le serveur libère de toute façon le verrou à la fin de la connexion
                }
            }
        }
    }

    /**
     * @param string $detail Cause, sans donnée de requête
     * @param int $idCustomer
     */
    private static function logUnavailable($detail, $idCustomer)
    {
        \PrestaShopLogger::addLog(
            'RelaySearchQuota - compteur inaccessible (' . $detail . ') - customer ' . (int) $idCustomer,
            3,
            null,
            'RelaySearchQuota',
            (int) $idCustomer,
            true
        );
    }
}
