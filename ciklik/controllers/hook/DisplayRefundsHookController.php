<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DisplayRefundsHookController
{
    /** @var Ciklik */
    protected $module;

    /** @var Context */
    protected $context;

    /** @var Order */
    public $order;

    /**
     * @param $module Ciklik
     */
    public function __construct($module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
    }

    public function run($params)
    {
        $order = new Order($params['id_order']);

        $transaction_id = \PrestaShop\Module\Ciklik\Managers\CiklikTransaction::getIdByOrder($order);

        if (! $transaction_id) {
            return null;
        }

        try {
            $transactionData = (new \PrestaShop\Module\Ciklik\Api\Transaction($this->context->link))->getOne($transaction_id);
        } catch (Exception $e) {
            return null;
        }

        $maxRefundAmount = $transactionData->amount - $transactionData->amount_refunded;
        $currency = new \Currency($order->id_currency);
        $orderData = [
            'id' => $order->id,
            'currencySymbol' => $currency->sign,
        ];

        $this->context->smarty->assign([
            'moduleName' => $this->module->name,
            'moduleDisplayName' => $this->module->displayName,
            'moduleLogoSrc' => $this->module->getPathUri() . 'logo.png',
            'order' => $orderData,
            'refund' => [
                'refunded' => $transactionData->amount_refunded
                    ? $this->context->currentLocale->formatPrice($transactionData->amount_refunded, $currency->iso_code)
                    : 0,
                'available' => $maxRefundAmount > 0,
                'max' => sprintf(
                    $this->module->l('Amount (Max. %s)'),
                    $this->context->currentLocale->formatPrice($maxRefundAmount, $currency->iso_code)
                ),
            ],
            'actionUrl' => $this->context->link->getModuleLink('ciklik', 'refund'),
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayAdminOrderRefunds.tpl');
    }
}
