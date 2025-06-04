{**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 
{*
** Affichage résumé panier à droite
*}
{if isset($subscription_infos) && !empty($subscription_infos)}
  {foreach from=$cart.products item=product}
    {if isset($subscription_infos[$product.id_product])}
      <div class="ciklik-cart-subscription-info" style="margin: 10px 0; padding: 10px; background-color: #f8f9fa; border-radius: 4px; border-left: 4px solid #25b9d7;">
        <div class="subscription-info" style="font-size: 1em; color: #6c757d;">
          <i class="material-icons" style="font-size: 1.2em; vertical-align: middle; margin-right: 5px;">repeat</i>
          <strong>{l s='Abonnement' mod='ciklik'} :</strong> {$subscription_infos[$product.id_product]|escape:'html':'UTF-8'}
        </div>
      </div>
    {/if}
  {/foreach}
{/if} 