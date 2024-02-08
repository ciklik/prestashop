{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{if !empty($transaction)}
  <section id="ciklik-displayOrderConfirmation">
    <p>{l s='Your transaction reference is %transaction%.' mod='ciklik' sprintf=['%transaction%' => $transaction]}</p>
  </section>
{/if}
