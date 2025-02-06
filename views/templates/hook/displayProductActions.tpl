{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div class="upsell-container" id="upsell-container-{$product->id_product}" style="margin-bottom: 0.5rem;float:left;display:inline-flex;padding-left: 0.5rem; margin-left: 0.5rem;">
  <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#upsellModal{$product->id_product}">
    {l s='Ajouter à l\'abonnement' mod='ciklik'}
  </button>
</div>

{include file="module:ciklik/views/templates/front/actions/chooseUpsellSubscription.tpl" product=$product}