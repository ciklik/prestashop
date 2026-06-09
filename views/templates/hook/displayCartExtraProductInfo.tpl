{*
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Hook : displayCartExtraProductInfo (PrestaShop >= 8.2)
 *
 * Variables disponibles :
 *   - $purchase_type   : 'subscription' | 'one_off'
 *   - $frequency_name  : nom de la fréquence si abonnement, null sinon
 *   - $id_product      : ID du produit (int)
 *   - $id_product_attribute : ID combinaison (int)
 *   - $product         : objet/array produit présenté par le panier
 *
 * Pour surcharger ce template depuis un thème :
 *   themes/{theme}/modules/ciklik/views/templates/hook/displayCartExtraProductInfo.tpl
 *}
<div class="ciklik-cart-extra-product-info" data-ciklik-purchase-type="{$purchase_type|escape:'html':'UTF-8'}">
  <div class="ciklik-cart-extra-product-info__row ciklik-cart-extra-product-info__row--purchase-type">
    <span class="ciklik-cart-extra-product-info__label">{l s='Purchase type:' mod='ciklik'}</span>
    <span class="ciklik-cart-extra-product-info__value">
      {if $purchase_type === 'subscription'}
        {l s='Subscription' mod='ciklik'}
      {else}
        {l s='One-off purchase' mod='ciklik'}
      {/if}
    </span>
  </div>
  {if $purchase_type === 'subscription' && $frequency_name}
    <div class="ciklik-cart-extra-product-info__row ciklik-cart-extra-product-info__row--frequency">
      <span class="ciklik-cart-extra-product-info__label">{l s='Frequency:' mod='ciklik'}</span>
      <span class="ciklik-cart-extra-product-info__value">{$frequency_name|escape:'html':'UTF-8'}</span>
    </div>
  {/if}
</div>
