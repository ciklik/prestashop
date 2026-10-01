<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Data;

use PrestaShop\Module\Ciklik\Helpers\UuidHelper;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Paiement en attente d'un abonnement : le renouvellement refusé, à régler par
 * le lien de reprise Ciklik (champ `pending_payment` de l'API).
 *
 * Le lien connecte le client à son compte Ciklik : il ne s'écrit jamais dans
 * la page, seul le contrôleur pendingpayment y redirige.
 */
class PendingPaymentData
{
    /**
     * @var string
     */
    public $subscription_uuid;

    /**
     * Produits de l'abonnement (display_content)
     *
     * @var string
     */
    public $label;

    /**
     * Montant TTC à régler
     *
     * @var float
     */
    public $amount;

    /**
     * Code ISO 4217
     *
     * @var string|null
     */
    public $currency;

    /**
     * Lien de reprise, en https
     *
     * @var string
     */
    public $retry_link;

    /**
     * Paiements en attente des abonnements actifs du client, indexés par uuid
     * d'abonnement. Un abonnement d'un autre client est écarté, quel que soit
     * le filtre appliqué par l'API.
     *
     * @param mixed $subscriptions Contenu de `data` renvoyé par GET subscriptions
     * @param string $ownerUuid UUID Ciklik du client connecté
     *
     * @return self[]
     */
    public static function collection($subscriptions, string $ownerUuid): array
    {
        if (!is_array($subscriptions) || !UuidHelper::isValid($ownerUuid)) {
            return [];
        }

        $payments = [];
        foreach ($subscriptions as $subscription) {
            $pending = is_array($subscription) ? ($subscription['pending_payment'] ?? null) : null;

            if (!is_array($pending)
                || empty($subscription['active'])
                || 0 !== strcasecmp((string) ($subscription['user_uuid'] ?? ''), $ownerUuid)
                || !UuidHelper::isValid((string) ($subscription['uuid'] ?? ''))
                || !is_numeric($pending['amount'] ?? null) || $pending['amount'] <= 0
                || !self::isHttpsLink($pending['retry_link'] ?? null)) {
                continue;
            }

            $payment = new self();
            $payment->subscription_uuid = $subscription['uuid'];
            $payment->label = is_string($subscription['display_content'] ?? null) ? $subscription['display_content'] : '';
            $payment->amount = (float) $pending['amount'];
            $payment->currency = is_string($pending['currency'] ?? null) ? $pending['currency'] : null;
            $payment->retry_link = $pending['retry_link'];

            $payments[$payment->subscription_uuid] = $payment;
        }

        return $payments;
    }

    /**
     * @param mixed $link
     *
     * @return bool
     */
    private static function isHttpsLink($link): bool
    {
        return is_string($link)
            && 'https' === strtolower((string) parse_url($link, PHP_URL_SCHEME))
            && '' !== (string) parse_url($link, PHP_URL_HOST);
    }
}
