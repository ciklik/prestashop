{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 <small><u data-toggle="modal" data-target="#updateInterval{$subscription->uuid|escape:'html':'UTF-8'}" data-bs-toggle="modal" data-bs-target="#updateInterval{$subscription->uuid|escape:'html':'UTF-8'}">
        {l s='Change frequency' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="updateInterval{$subscription->uuid|escape:'html':'UTF-8'}">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">{l s='Change my subscription frequency' mod='ciklik'}</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

            <!-- Corps de la boîte modale -->
            <div class="modal-body">
                <p>{l s='You can select a new frequency that will apply to all products in your subscription if available.' mod='ciklik'}</p>
                <p>{l s='The date of your next order will not change, the frequency change will take effect from the following order.' mod='ciklik'}</p>
                <form id="newIntervalForm-{$subscription->uuid|escape:'html':'UTF-8'}" action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/contents" method="POST">
                <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                <label for="interval">{l s='Choose' mod='ciklik'}</label>
                {if $use_frequency_mode === '1'}
                    <select name="product_combination" id="product_combination" required>
                        {if !empty($subscription->contents)}
                            {assign var=content value=$subscription->contents[0]}
                            {if !empty($content.other_combinations)}
                                {foreach from=$content.other_combinations item=combination}
                                    <option value="{$combination.frequency_id|escape:'html':'UTF-8'}">
                                        {$combination.display_name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            {/if}
                        {else}
                            <option value="">{l s='No other combination available' mod='ciklik'}</option>
                        {/if}
                    </select>
                    <input type="hidden" name="use_frequency_mode" value="1">
                {else}
                    <select name="product_combination" id="product_combination" required>
                        {if !empty($subscription->contents)}
                            {assign var=content value=$subscription->contents[0]}
                            {if !empty($content.other_combinations)}
                                {foreach from=$content.other_combinations item=combination}
                                    <option value="{$combination.id_product_attribute|escape:'html':'UTF-8'}">
                                        {$combination.display_name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            {/if}
                        {else}
                            <option value="">{l s='No other combination available' mod='ciklik'}</option>
                        {/if}
                    </select>
                    <input type="hidden" name="use_frequency_mode" value="0">
                {/if}

                    <button type="submit">{l s='Change frequency' mod='ciklik'}</button>
                </form>
            </div>

            <!-- Pied de la boîte modale -->
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">{l s='Cancel' mod='ciklik'}</button>
            </div>

        </div>
    </div>
</div>
