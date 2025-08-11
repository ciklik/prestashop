{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div class="customization-details">
    {foreach from=$customizations item=custom}
        <div class="customization-item">
            <span class="customization-name">{$custom.name}:</span>
            {if $custom.type == "0"}
                {* Fichier - afficher l'image avec la logique PrestaShop *}
                <div class="customization-image">
                    <img src="{$urls.base_url}upload/{$custom.value}_small?reference={$custom.name|urlencode}" 
                         alt="{$custom.name}" 
                         class="customization-img"
                         style="max-width: 100px; max-height: 100px; object-fit: cover; border-radius: 4px;">
                </div>
                
            {else}
                {* Texte - afficher normalement *}
                <span class="customization-value">{$custom.value}</span>
               
            {/if}
            {if $custom.quantity > 1}
                <span class="customization-quantity">({l s='Quantité' mod='ciklik'}: {$custom.quantity})</span>
            {/if}
        </div>
    {/foreach}
</div> 