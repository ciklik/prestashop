{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<section id="ciklik-displayPaymentReturn">
  {if !empty($transaction)}
    <p>{l s='Your transaction reference is %transaction%.' mod='ciklik' sprintf=['%transaction%' => $transaction]}</p>
  {/if}
  {if !empty($subscription_items)}
    <div class="ciklik-subscription-summary">
      <h4>{l s='Subscription details' mod='ciklik'}</h4>
      <ul>
        {foreach from=$subscription_items item=item}
          <li>
            <strong>{$item.name|escape:'html':'UTF-8'}</strong>
            — {$item.frequency.name|escape:'html':'UTF-8'}
            {if $item.frequency.discount_percent > 0}
              (-{$item.frequency.discount_percent|escape:'html':'UTF-8'}%)
            {elseif $item.frequency.discount_price > 0}
              (-{$item.frequency.discount_price|escape:'html':'UTF-8'})
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>
  {/if}
</section>

