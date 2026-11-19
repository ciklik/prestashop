<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Helpers;

use PrestaShop\Module\Ciklik\Api\CiklikApiResponseHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Abonnement visé par une action de la fiche commande du back-office.
 *
 * L'UUID vient de la requête : il doit être celui de l'abonnement lié chez
 * Ciklik à la commande consultée, et l'abonnement doit appartenir au client
 * de cette commande. Décision sans PrestaShop : la lecture de l'abonnement et
 * sa construction sont injectées.
 */
class OrderSubscriptionGuard
{
    /** Abonnement de la commande et de son client */
    const OK = 'ok';

    /** Commande sans abonnement connu de Ciklik, ou Ciklik injoignable */
    const ORDER_UNKNOWN = 'order_unknown';

    /** UUID posté différent de celui de l'abonnement de la commande */
    const NOT_ORDER_SUBSCRIPTION = 'not_order_subscription';

    /** Lecture de l'abonnement impossible */
    const LOAD_FAILED = 'load_failed';

    /** Abonnement d'un autre client que celui de la commande */
    const OTHER_CUSTOMER = 'other_customer';

    /**
     * @param string|null $orderSubscriptionUuid UUID lié à la commande chez Ciklik, null si inconnu
     * @param string $uuid UUID posté
     * @param int $idCustomer Client de la commande
     * @param callable $load Lecture de l'abonnement : function (string $uuid): array (Subscription::getOne)
     * @param callable $build Construction depuis le corps : function (array $body) (SubscriptionData::create)
     *
     * @return array{status: string, subscription: object|null, cause: string|null}
     *               cause : motif court pour le journal, sans donnée du client ni texte de l'API
     */
    public static function resolve($orderSubscriptionUuid, $uuid, $idCustomer, callable $load, callable $build)
    {
        if (!is_string($orderSubscriptionUuid) || '' === $orderSubscriptionUuid) {
            return self::result(self::ORDER_UNKNOWN, null, 'commande sans abonnement chez Ciklik, ou Ciklik injoignable');
        }

        if (0 !== strcasecmp($orderSubscriptionUuid, (string) $uuid)) {
            return self::result(self::NOT_ORDER_SUBSCRIPTION, null, 'abonnement différent de celui de la commande');
        }

        try {
            $response = call_user_func($load, (string) $uuid);
        } catch (\Throwable $e) {
            return self::result(self::LOAD_FAILED, null, 'exception ' . get_class($e));
        }

        if (!is_array($response) || empty($response['status'])) {
            return self::result(self::LOAD_FAILED, null, CiklikApiResponseHandler::failureSummary($response));
        }

        if (empty($response['body']['external_fingerprint'])) {
            return self::result(self::LOAD_FAILED, null, 'abonnement sans empreinte');
        }

        try {
            $subscription = call_user_func($build, $response['body']);
        } catch (\Throwable $e) {
            return self::result(self::LOAD_FAILED, null, 'empreinte illisible (' . get_class($e) . ')');
        }

        if ((int) $subscription->external_fingerprint->id_customer !== (int) $idCustomer) {
            return self::result(self::OTHER_CUSTOMER, null, 'abonnement d\'un autre client');
        }

        return self::result(self::OK, $subscription, null);
    }

    /**
     * @param string $status
     * @param object|null $subscription
     * @param string|null $cause
     *
     * @return array
     */
    private static function result($status, $subscription, $cause)
    {
        return ['status' => $status, 'subscription' => $subscription, 'cause' => $cause];
    }
}
