{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div class="customization-details">
    {foreach from=$customizations item=productCustomization}

        <div class="product-customization">
            <div class="product-info">
                <strong>{l s='Produit' mod='ciklik'} #{$productCustomization.id_product}</strong>
                {if $productCustomization.id_product_attribute > 0}
                    <span class="attribute-info">({l s='Déclinaison' mod='ciklik'} #{$productCustomization.id_product_attribute})</span>
                {/if}
            </div>
            
            {if isset($productCustomization.customizations.fields) && !empty($productCustomization.customizations.fields)}
                <div class="customization-fields">
                    <h6>{l s='Champs de personnalisation' mod='ciklik'}</h6>
                    {foreach from=$productCustomization.customizations.fields item=field}
                        <div class="customization-field">
                            <span class="field-name">{$field.name}:</span>
                            <span class="field-value">{$field.value}</span>
                        </div>
                    {/foreach}
                </div>
            {/if}
            
            {if isset($productCustomization.customizations.files) && !empty($productCustomization.customizations.files)}
                <div class="customization-files">
                    <h6>{l s='Fichiers de personnalisation' mod='ciklik'}</h6>
                    {foreach from=$productCustomization.customizations.files item=file}
                        <div class="customization-file">
                            <span class="file-name">{$file.name}:</span>
                            <span class="file-filename">{if isset($file.filename)}{$file.filename}{else}-{/if}</span>
                        </div>
                    {/foreach}
                </div>
            {/if}
        </div>
    {/foreach}
</div> 