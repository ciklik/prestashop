{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
<small><u data-toggle="modal" data-target="#changeDeliveryAddress{$subscription->uuid|escape:'html':'UTF-8'}" data-bs-toggle="modal" data-bs-target="#changeDeliveryAddress{$subscription->uuid|escape:'html':'UTF-8'}" data-bs-toggle="modal">
        {l s='Change address' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="changeDeliveryAddress{$subscription->uuid|escape:'html':'UTF-8'}">
<div class="modal-dialog">
    <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">{l s='Change the address for your next delivery' mod='ciklik'}</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

        <!-- Corps de la boîte modale -->
        <div class="modal-body">
            <p>{l s='Select the address where your next order will be shipped.' mod='ciklik'}</p>
            <p>
                {l s='If you want to create a new delivery address, go to your My Account area, Addresses section.' mod='ciklik'}
            </p>

            <form id="changeAddressForm-{$subscription->uuid|escape:'html':'UTF-8'}" action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/updateaddress" method="POST">
                <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                <label for="changeAddressForm">{l s='Address:' mod='ciklik'}</label>
                <select name="changeAddressForm" id="changeAddressForm">
                    {foreach from=$addresses item=$address}
                        <option value="{$address['id_address']|escape:'html':'UTF-8'}">
                            {$address['address1']|escape:'html':'UTF-8'} <br>
                            {$address['city']|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}

                </select>
                <button type="submit" class="btn btn-primary">{l s='Update' mod='ciklik'}</button>
            </form>
        </div>

        <!-- Pied de la boîte modale -->
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal" data-bs-dismiss="modal">{l s='Cancel' mod='ciklik'}</button>
        </div>

    </div>
</div>
</div>
