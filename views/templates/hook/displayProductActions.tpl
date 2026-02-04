{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{* Conteneur principal pour les actions produit *}
<div class="ciklik-product-actions">

  {* Section Upsell si activée *}
  {if $has_upsell}
    <div class="upsell-container" id="upsell-container-{$product->id_product}" style="margin-bottom: 0.5rem;display:inline-flex;padding-left: 0.5rem; margin-left: 0.5rem;">
      <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#upsellModal{$product->id_product}">
        {l s='Add to subscription' mod='ciklik'}
      </button>
    </div>

    {include file="module:ciklik/views/templates/front/actions/chooseUpsellSubscription.tpl" product=$product}
  {/if}

  {* Section Options d'abonnement si mode fréquence activé *}
  {if $has_subscription_mode}
    <div class="subscription-options-container">
      {include file="module:ciklik/views/templates/hook/displayProductSubscriptionOptions.tpl"}
    </div>
  {/if}

</div>
