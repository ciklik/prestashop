{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{if !empty($transaction) || !empty($payment_pending)}
  <section id="ciklik-displayOrderDetail" class="box">
    {if !empty($payment_pending)}
      <p class="ciklik-payment-pending">{l s='Your payment is being confirmed by the payment provider. Your order will be prepared as soon as the payment is received.' mod='ciklik'}</p>
    {/if}
    {if !empty($transaction)}
      <p>{l s='Your transaction reference is %transaction%.' mod='ciklik' sprintf=['%transaction%' => $transaction]}</p>
    {/if}
  </section>
{/if}
