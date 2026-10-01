{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Avertissement de paiement en attente (renouvellement refusé), au panier,
 * dans le moyen de paiement Ciklik et dans « Mes abonnements ». Il ne bloque
 * pas la commande. Le bouton passe par le contrôleur pendingpayment : le lien
 * de reprise n'est jamais écrit dans la page.
 *}
<div class="alert alert-warning ciklik-pending-payment" role="alert">
  {foreach from=$ciklik_pending_payments item=payment}
    <p>{l s='You have a pending payment of %amount% for your subscription "%subscription%".' sprintf=['%amount%' => $payment.amount, '%subscription%' => $payment.label|truncate:80:'…'] mod='ciklik'}</p>
    <p><a href="{$payment.link|escape:'html':'UTF-8'}" class="btn btn-primary btn-sm" target="_blank" rel="noopener noreferrer">{l s='Settle this payment' mod='ciklik'}</a></p>
  {/foreach}
  <p class="small" style="margin-bottom:0;">{l s='A pending payment remains due, even if you place a new order.' mod='ciklik'}</p>
</div>
