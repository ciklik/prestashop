{*
* @author    Metrogeek SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

{* Aucun champ « name » : rien n'est envoyé avec le formulaire du produit, les actions passent par AJAX *}
<div class="panel product-tab" id="ciklik-transform"
     data-url="{$ciklik_transform_url|escape:'html':'UTF-8'}"
     data-id-product="{$ciklik_id_product|intval}"
     data-confirm-transform="{l s='Transform this product? Combinations are created and the page is reloaded.' mod='ciklik'}"
     data-confirm-realign="{l s='Copy the stock of each one-time combination onto its subscription combinations?' mod='ciklik'}"
     data-error="{l s='The request failed. Reload the page and try again.' mod='ciklik'}"
     data-label-total="{l s='%d combinations in total: %d one-time, %d subscription combinations created.' mod='ciklik'}"
     data-label-simple="{l s='The product has no combination: a one-time combination will be created from the product, with its stock (%d).' mod='ciklik'}"
     data-label-existing="{l s='%d existing combinations will receive Purchase type = one-time. Their IDs do not change.' mod='ciklik'}"
     data-label-stock="{l s='The stock of each one-time combination is copied onto its subscription combinations: the total quantity shown by PrestaShop goes from %d to %d.' mod='ciklik'}"
     data-label-price="{l s='Subscription combinations are created at the one-time price: set their price impact afterwards in the Combinations tab.' mod='ciklik'}"
     data-label-cart="{l s='Open carts containing this product without a combination will have to choose again.' mod='ciklik'}"
     data-label-engine="{l s='These tables do not support transactions, a failure is undone by deleting what was created:' mod='ciklik'}"
     data-label-realign="{l s='%d subscription combinations will change, %d already aligned.' mod='ciklik'}"
     data-label-orphans="{l s='Left as is (no one-time combination with the same attributes):' mod='ciklik'}">
    <div class="panel-heading">
        <i class="icon-refresh"></i> {l s='Ciklik subscription' mod='ciklik'}
    </div>

    {if $ciklik_transform.has_subscription}
        <p>{l s='This product has subscription combinations.' mod='ciklik'}</p>
        <p class="help-block">{l s='Realign the stock: the quantity of each one-time combination is copied onto the subscription combinations with the same attributes. Nothing happens automatically afterwards.' mod='ciklik'}</p>
        <button type="button" class="btn btn-default" data-ciklik-action="realign_preview">{l s='Preview stock realignment' mod='ciklik'}</button>
    {elseif $ciklik_transform_refusal}
        <div class="alert alert-warning">{$ciklik_transform_refusal|escape:'html':'UTF-8'}</div>
    {else}
        <p>{l s='Transform into a subscription product: adds Purchase type and Subscription frequency to the combinations, without changing the existing ones.' mod='ciklik'}</p>
        <div class="form-group">
            <label>{l s='Frequencies' mod='ciklik'}</label>
            {foreach from=$ciklik_transform.frequencies item=frequency}
                <div class="checkbox">
                    <label>
                        <input type="checkbox" class="ciklik-transform-frequency" value="{$frequency.id_attribute|intval}"{if $frequency.id_attribute == $ciklik_transform.default_frequency} checked="checked" disabled="disabled"{/if}>
                        {$frequency.name|escape:'html':'UTF-8'}
                        {if $frequency.id_attribute == $ciklik_transform.default_frequency}<small class="text-muted">({l s='default frequency, required' mod='ciklik'})</small>{/if}
                    </label>
                </div>
            {/foreach}
        </div>
        <button type="button" class="btn btn-default" data-ciklik-action="preview">{l s='Preview' mod='ciklik'}</button>
    {/if}

    <div class="ciklik-transform-result" style="margin-top: 10px;"></div>
    <button type="button" class="btn btn-primary" data-ciklik-action="transform" style="display: none;">{l s='Transform into subscription' mod='ciklik'}</button>
    <button type="button" class="btn btn-primary" data-ciklik-action="realign_stock" style="display: none;">{l s='Realign the stock' mod='ciklik'}</button>
</div>
<script type="text/javascript" src="{$ciklik_transform_js|escape:'html':'UTF-8'}"></script>
