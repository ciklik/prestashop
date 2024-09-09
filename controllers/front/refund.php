<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Transaction;
use PrestaShop\Module\Ciklik\Data\TransactionData;
use PrestaShop\Module\Ciklik\Managers\CiklikRefund;
use PrestaShop\Module\Ciklik\Managers\CiklikTransaction;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikRefundModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if (!CiklikRefund::canRun()) {
            $this->ajaxFailAndDie(
                $this->module->l('Unauthorized')
            );
        }

        $order = new Order(Tools::getValue('orderId'));

        $transactionData = $this->getTransactionData($order);

        $amount = $this->getRefundAmount($transactionData);

        $refundedTransactionData = $this->doRefund($transactionData, $amount);

        $newMaxRefundAmount = $refundedTransactionData->amount - $refundedTransactionData->amount_refunded;

        $currency = new Currency($order->id_currency);

        if ('total' === Tools::getValue('refundType')) {
            $this->setOrderAsRefund($order);
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Refund has been processed'),
            'refund' => [
                'refunded' => $refundedTransactionData->amount_refunded
                    ? $this->context->currentLocale->formatPrice($refundedTransactionData->amount_refunded, $currency->iso_code)
                    : 0,
                'available' => $newMaxRefundAmount > 0,
                'max' => sprintf(
                    $this->module->l('Amount (Max. %s)'),
                    $this->context->currentLocale->formatPrice($newMaxRefundAmount, $currency->iso_code)
                ),
            ],
        ]));
    }

    private function retrieveTransactionId(Order $order): string
    {
        $transaction_id = CiklikTransaction::getIdByOrder($order);

        if (!$transaction_id) {
            $this->ajaxFailAndDie(
                $this->module->l('Error: Could not find Ciklik transaction')
            );
        }

        return $transaction_id;
    }

    private function getTransactionData(Order $order): TransactionData
    {
        $transaction_id = $this->retrieveTransactionId($order);

        try {
            $transactionData = (new Transaction($this->context->link))->getOne($transaction_id);
        } catch (Exception $e) {
            $this->ajaxFailAndDie(
                $this->module->l('There was an error while processing the refund')
            );
        }

        return $transactionData;
    }

    private function getRefundAmount(TransactionData $transactionData)
    {
        $maxAmount = $transactionData->amount - $transactionData->amount_refunded;

        switch (Tools::getValue('refundType')) {
            case 'partial':
                $amount = (float) str_replace(',', '.', Tools::getValue('amount'));
                if ($amount > $maxAmount) {
                    $this->ajaxFailAndDie(
                        $this->module->l('Error: Amount is higher than maximum refundable'));
                }

                return $amount;
            case 'total':
                return $maxAmount;
            default:
                return 0;
        }
    }

    private function doRefund(TransactionData $transactionData, $amount): TransactionData
    {
        try {
            $refundResult = (new Transaction($this->context->link))->refund($transactionData->transaction_id, $amount);
        } catch (Exception $e) {
            $this->ajaxFailAndDie(
                $this->module->l('There was an error while processing the refund')
            );
        }

        return $refundResult;
    }

    private function setOrderAsRefund(Order $order)
    {
        $orders = Order::getByReference($order->reference);
        foreach ($orders as $o) {
            $currentOrderState = $o->getCurrentOrderState();
            if ($currentOrderState->id !== (int) Configuration::get('PS_OS_REFUND')) {
                $o->setCurrentState(Configuration::get('PS_OS_REFUND'));
            }
        }
    }

    protected function ajaxRenderAndExit($value = null, $responseCode = null, $controller = null, $method = null)
    {
        $this->renderAndExit($value, $controller, $method);
    }

    protected function ajaxFailAndDie($msg = null, $statusCode = 500)
    {
        header("X-PHP-Response-Code: $statusCode", true, $statusCode);
        $json = ['error' => true, 'message' => $msg];

        $this->ajaxRenderAndExit(json_encode($json));
    }

    protected function renderAndExit($value = null, $controller = null, $method = null)
    {
        $this->ajaxRender($value, $controller, $method);
        exit;
    }
}
