<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use PrestaShop\Module\Ciklik\Data\OrderData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * POST ciklik/gateway/payments : Ciklik annonce que le paiement d'une commande (PayPal par prélèvement bancaire)
 * est confirmé ou refusé. L'appel ne prouve rien : la commande est relue dans l'API Ciklik, et seule une
 * commande du module encore « en attente de paiement » est modifiée.
 */
class PaymentGateway extends AbstractGateway implements EntityGateway
{
    const KIND_PAID = 'paid';
    const KIND_ERROR = 'error';

    // Raison d'une réponse sans changement, lue dans les journaux de Ciklik.
    const REASON_UNCHANGED = 'not_awaiting_or_still_pending';
    const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    public function post()
    {
        $ciklikOrderId = (int) \Tools::getValue('ciklik_order_id');
        $lock = null;

        try {
            // Relecture : jamais de confiance dans l'appel (même réponse 400 qu'OrderGateway)
            $orderData = (new \PrestaShop\Module\Ciklik\Api\Order(\Context::getContext()->link))->getOne($ciklikOrderId);

            if (!$orderData instanceof OrderData) {
                \PrestaShopLogger::addLog('Ciklik PaymentGateway - commande Ciklik ' . $ciklikOrderId . ' illisible dans l\'API', 2);
                (new Response())->setBody(['error' => 'Order has not been retrieved'])->sendBadRequest();

                return;
            }

            $psOrderId = self::findOrderId($orderData);

            // Un seul passage à la fois par commande : réessai de Ciklik et rejeu ne doivent ni doubler le paiement
            // ni envoyer deux fois le mail du changement d'état.
            if ($psOrderId > 0) {
                $lock = self::lockName($psOrderId);
                if (!(int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($lock) . '\', 10)', false)) {
                    $lock = null;
                    (new Response())->setBody(['error' => 'Order is being updated'])->sendConflict();

                    return;
                }
            }

            // Relue après le verrou : l'état a pu changer pendant l'attente.
            $order = $psOrderId > 0 ? new \Order($psOrderId) : null;

            // Introuvable (la commande PrestaShop n'est pas encore créée) : 404, l'app réessaie.
            // Une commande d'un autre module, ou d'un autre panier que celui de la commande Ciklik, n'est jamais touchée.
            if (!$order || !\Validate::isLoadedObject($order) || $order->module !== $this->module->name
                || !$orderData->isForCart((int) $order->id_cart)) {
                (new Response())->setBody(['error' => 'Order not found'])->sendNotFound();

                return;
            }

            $currentState = (int) $order->current_state;
            $plan = self::plan($orderData, $currentState);

            if ($plan['target'] === null) {
                (new Response())->setBody(self::responseBody((int) $order->id, false, $currentState, self::REASON_UNCHANGED))->send();

                return;
            }

            // Montant encaissé différent du total de la commande (panier modifié avant la création) : jamais
            // « payée » ; la commande reste en attente et le journal le signale.
            if ($plan['kind'] === self::KIND_PAID && !self::amountMatches($orderData->total_paid, $order->total_paid)) {
                \PrestaShopLogger::addLog(
                    'Ciklik PaymentGateway - montant encaissé ' . $orderData->total_paid . ' différent du total de la commande '
                        . (int) $order->id . ' (' . $order->total_paid . ') : commande laissée en attente, à vérifier',
                    3,
                    null,
                    'Order',
                    (int) $order->id,
                    true
                );
                (new Response())->setBody(self::responseBody((int) $order->id, false, $currentState, self::REASON_AMOUNT_MISMATCH))->send();

                return;
            }

            if ($plan['kind'] === self::KIND_PAID) {
                $this->recordPayment($order, $orderData);
            }

            // Réutilise le paiement ajouté : sans cela, le passage à un état payé en ajouterait un second
            // pour le reste dû de la facture.
            $history = new \OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($plan['target'], $order, $plan['kind'] === self::KIND_PAID);

            try {
                $history->addWithemail();
            } catch (\Throwable $e) {
                // L'historique est enregistré avant l'envoi (OrderHistory::addWithemail) : seul le mail au client manque.
                \PrestaShopLogger::addLog('Ciklik PaymentGateway - mail du changement d\'état non envoyé pour la commande ' . (int) $order->id . ' : ' . get_class($e), 2);
            }

            (new Response())->setBody(self::responseBody((int) $order->id, true, $plan['target'], null))->send();
        } catch (\Throwable $e) {
            self::logFailure($e, $ciklikOrderId);
            (new Response())->setBody(['error' => 'Payment update failed'])->sendInternalServerError();
        } finally {
            if ($lock !== null) {
                \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')', false);
            }
        }
    }

    /**
     * @param int $psOrderId
     *
     * @return string
     */
    public static function lockName($psOrderId)
    {
        return 'ciklik_payment_' . (int) $psOrderId;
    }

    /**
     * Montant relu chez Ciklik égal, au centime, au total de la commande PrestaShop.
     *
     * @param string|float $ciklikTotal
     * @param string|float $orderTotal
     *
     * @return bool
     */
    public static function amountMatches($ciklikTotal, $orderTotal)
    {
        return abs((float) $ciklikTotal - (float) $orderTotal) < 0.01;
    }

    private function recordPayment(\Order $order, OrderData $orderData)
    {
        if (self::isPaymentRecorded((string) $order->reference, (string) $orderData->paid_transaction_id)) {
            return;
        }

        $invoice = null;
        foreach ($order->getInvoicesCollection() as $candidate) {
            $invoice = $candidate;
        }

        $added = $order->addOrderPayment(
            (float) $order->total_paid,
            $orderData->getPspName(),
            $orderData->paid_transaction_id,
            null,
            null,
            $invoice
        );

        if (!$added) {
            throw new \RuntimeException('Payment could not be added to the order');
        }
    }

    /**
     * État cible de la commande PrestaShop d'après le paiement relu chez Ciklik.
     *
     * @param OrderData $orderData
     * @param int $currentState état actuel de la commande PrestaShop
     *
     * @return array{target: int|null, kind: string|null} kind : KIND_PAID (paiement à ajouter), KIND_ERROR ou null
     */
    public static function plan(OrderData $orderData, $currentState)
    {
        $target = $orderData->paymentUpdateTargetState((int) $currentState);

        if ($target === null) {
            return ['target' => null, 'kind' => null];
        }

        return [
            'target' => $target,
            'kind' => $orderData->payment_status === OrderData::PAYMENT_PAID ? self::KIND_PAID : self::KIND_ERROR,
        ];
    }

    /**
     * Commande PrestaShop de la commande Ciklik : le lien posé par Ciklik, sinon le panier.
     *
     * @return int 0 si introuvable
     */
    public static function findOrderId(OrderData $orderData)
    {
        if ($orderData->prestashop_order_id !== null && $orderData->prestashop_order_id > 0) {
            return (int) $orderData->prestashop_order_id;
        }

        if ($orderData->prestashop_cart_id === null || $orderData->prestashop_cart_id <= 0) {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE id_cart = ' . (int) $orderData->prestashop_cart_id . ' ORDER BY id_order ASC'
        );
    }

    /**
     * Un paiement de la commande porte-t-il déjà cette transaction ?
     *
     * @param string $orderReference référence de la commande PrestaShop
     * @param string $transactionId
     *
     * @return bool
     */
    public static function isPaymentRecorded($orderReference, $transactionId)
    {
        if ($transactionId === '') {
            return false;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_payment WHERE order_reference = "' . pSQL($orderReference)
            . '" AND transaction_id = "' . pSQL($transactionId) . '"'
        ) > 0;
    }

    /**
     * @return array
     */
    public static function responseBody($psOrderId, $updated, $state, $reason = null)
    {
        $body = [
            'ps_order_id' => (int) $psOrderId,
            'updated' => (bool) $updated,
            'state' => (int) $state,
        ];

        if ($reason !== null) {
            $body['reason'] = (string) $reason;
        }

        return $body;
    }

    /**
     * Journalise un échec sans donnée personnelle : identifiant de commande Ciklik, classe et message de l'erreur.
     * (public pour testabilité uniquement)
     *
     * @param \Throwable $e
     * @param int $ciklikOrderId
     */
    public static function logFailure(\Throwable $e, $ciklikOrderId)
    {
        \PrestaShopLogger::addLog(
            'Ciklik PaymentGateway - update failed for Ciklik order ' . (int) $ciklikOrderId . ': ' . get_class($e) . ' ' . $e->getMessage(),
            3,
            null,
            'Order',
            null,
            true
        );
    }
}
