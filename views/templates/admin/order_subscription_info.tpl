{*
* @author    Ciklik SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

<div class="card">
    <h3 class="card-header">
        <img src="{$moduleLogoSrc}" alt="{$moduleDisplayName}" width="20" height="20"> {l s='Subscription information' mod='ciklik'}
    </h3>
    <div class="card-body">
        {if $subscription_items}
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{l s='Product' mod='ciklik'}</th>
                            <th>{l s='Frequency' mod='ciklik'}</th>
                            <th>{l s='Quantity' mod='ciklik'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$subscription_items item=item}
                            <tr>
                                <td>{$item.name|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if $item.frequency}
                                        {$item.frequency|escape:'html':'UTF-8'}
                                        {if $item.discount_percent > 0}
                                            <span class="badge badge-success">-{$item.discount_percent|escape:'html':'UTF-8'}%</span>
                                        {/if}
                                        {if $item.discount_price} <span class="fw-bold"> -{$item.discount_price|escape:'html':'UTF-8'} €</span>{/if}
                                    {else}
                                        {l s='One-time purchase' mod='ciklik'}
                                    {/if}
                                </td>
                                <td>{$item.quantity|intval}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="text-muted">{l s='No subscription products in this order.' mod='ciklik'}</p>
        {/if}

        {if $subscription}


            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{l s='Status' mod='ciklik'}:</strong>
                    <span class="badge {if $subscription->active}badge-success{else}badge-danger{/if}">
                        {if $subscription->active}{l s='Active' mod='ciklik'}{else}{l s='Inactive' mod='ciklik'}{/if}
                    </span>
                </div>
                <div class="col-md-6">
                    <strong>{l s='Next payment' mod='ciklik'}:</strong>
                    {$subscription->next_billing->format('d/m/Y')}
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{l s='UUID' mod='ciklik'}:</strong>
                    <code>{$subscription->uuid|escape:'html':'UTF-8'}</code>
                </div>
                <div class="col-md-6">
                    <strong>{l s='End date' mod='ciklik'}:</strong>
                    {if $subscription->end_date}
                        {$subscription->end_date->format('d/m/Y')}
                    {else}
                        {l s='Not defined' mod='ciklik'}
                    {/if}
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Ciklik link' mod='ciklik'}:</strong>
                    <div class="mt-2">
                        <a href="{$ciklik_order_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-link">
                            <i class="material-icons">open_in_new</i> {l s='View order on Ciklik' mod='ciklik'}
                        </a>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Delivery address' mod='ciklik'}:</strong>
                    <address class="mt-2">
                        {$subscription->address->first_name|escape:'html':'UTF-8'} {$subscription->address->last_name|escape:'html':'UTF-8'}<br>
                        {$subscription->address->address|escape:'html':'UTF-8'}<br>
                        {if $subscription->address->address1}{$subscription->address->address1|escape:'html':'UTF-8'}<br>{/if}
                        {$subscription->address->postcode|escape:'html':'UTF-8'} {$subscription->address->city|escape:'html':'UTF-8'}<br>
                        {$subscription->address->country|escape:'html':'UTF-8'}
                    </address>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Fingerprint' mod='ciklik'}:</strong>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm">
                            <tbody>
                                <tr>
                                    <th>{l s='Customer ID' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_customer|intval}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Delivery address' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_address_delivery|intval}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Billing address' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_address_invoice|intval}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Language' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_lang|intval}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Currency' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_currency|intval}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Carrier' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_carrier_reference|intval}</td>
                                </tr>
                                {if $subscription->external_fingerprint->frequency_id}
                                <tr>
                                    <th>{l s='Frequency' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->frequency_id|intval}</td>
                                </tr>
                                {/if}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {if $subscription->upsells}
                <h4 class="mt-4">{l s='Additional products' mod='ciklik'}</h4>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{l s='Product' mod='ciklik'}</th>
                                <th>{l s='Quantity' mod='ciklik'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$subscription->upsells item=upsell}
                                <tr>
                                    <td>{$upsell->name|escape:'html':'UTF-8'}</td>
                                    <td>{$upsell->quantity|intval}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {/if}
        {/if}
    </div>
</div>
