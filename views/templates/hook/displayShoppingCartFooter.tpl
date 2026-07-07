{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Récap panier : mention légale de récurrence + avertissements optionnels.
 * Markup volontairement neutre (alertes Bootstrap 4) : à styliser côté thème.
 * Les textes proviennent de la config BO ; s'ils sont vides, on retombe sur des
 * chaînes traduisibles par défaut.
 *}
<div class="ciklik-cart-footer">
  <div class="alert alert-info" role="alert">
    {if $ciklik_footer_message}{$ciklik_footer_message nofilter}{else}{l s='At least one item in your cart is a recurring purchase. By proceeding to payment, you agree that your payment method will be automatically charged at the price and frequency shown on this page, until it ends or you cancel it. All cancellations are subject to the cancellation policy in our terms and conditions.' mod='ciklik'}{/if}
  </div>

  {if $ciklik_alert_mixed}
    <div class="alert alert-warning" role="alert">
      {if $ciklik_alert_mixed_message}{$ciklik_alert_mixed_message nofilter}{else}{l s='Your cart contains both subscription items and one-time purchases.' mod='ciklik'}{/if}
    </div>
  {/if}

  {if $ciklik_alert_frequencies}
    <div class="alert alert-warning" role="alert">
      {if $ciklik_alert_frequencies_message}{$ciklik_alert_frequencies_message nofilter}{else}{l s='Your cart contains subscriptions with different frequencies.' mod='ciklik'}{/if}
    </div>
  {/if}
</div>
