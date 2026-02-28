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


            <div class="alert alert-success ciklik-manage-success" style="display:none"></div>
            <div class="alert alert-danger ciklik-manage-danger" style="display:none"></div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{l s='Status' mod='ciklik'}:</strong>
                    <span id="ciklik-subscription-status" class="badge {if $subscription->active}badge-success{else}badge-danger{/if}">
                        {if $subscription->active}{l s='Active' mod='ciklik'}{else}{l s='Inactive' mod='ciklik'}{/if}
                    </span>
                </div>
                <div class="col-md-6">
                    <strong>{l s='Next payment' mod='ciklik'}:</strong>
                    <span id="ciklik-next-billing">{$subscription->next_billing->format('d/m/Y')}</span>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    {if $subscription->active}
                        <button type="button" id="ciklik-btn-deactivate" class="btn btn-outline-danger btn-sm">
                            <i class="material-icons">pause</i> {l s='Deactivate subscription' mod='ciklik'}
                        </button>
                    {else}
                        <button type="button" id="ciklik-btn-activate" class="btn btn-outline-success btn-sm">
                            <i class="material-icons">play_arrow</i> {l s='Activate subscription' mod='ciklik'}
                        </button>
                    {/if}

                    {if $subscription->active}
                        <button type="button" id="ciklik-btn-change-date" class="btn btn-outline-primary btn-sm ml-2 ms-2">
                            <i class="material-icons">date_range</i> {l s='Change next billing date' mod='ciklik'}
                        </button>
                    {/if}
                </div>
            </div>

            <div class="row mb-3" id="ciklik-change-date-form" style="display:none">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="date" id="ciklik-new-date" class="form-control" min="{date('Y-m-d', strtotime('+1 day'))}">
                        <button type="button" id="ciklik-btn-confirm-date" class="btn btn-primary">
                            {l s='Confirm' mod='ciklik'}
                        </button>
                        <button type="button" id="ciklik-btn-cancel-date" class="btn btn-outline-secondary">
                            {l s='Cancel' mod='ciklik'}
                        </button>
                    </div>
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

{if $subscription}
<script type="text/javascript">
$(function () {
    var manageUrl = '{$manageActionUrl|escape:"javascript":"UTF-8"}';
    var ajaxToken = '{$manageAjaxToken|escape:"javascript":"UTF-8"}';
    var subscriptionUuid = '{$subscription->uuid|escape:"javascript":"UTF-8"}';
    var labelActive = '{l s="Active" mod="ciklik" js=1}';
    var labelInactive = '{l s="Inactive" mod="ciklik" js=1}';
    var labelDeactivate = '<i class="material-icons">pause</i> {l s="Deactivate subscription" mod="ciklik" js=1}';
    var labelActivate = '<i class="material-icons">play_arrow</i> {l s="Activate subscription" mod="ciklik" js=1}';

    function showSuccess(msg) {
        $('.ciklik-manage-danger').hide();
        $('.ciklik-manage-success').html(msg).show();
    }

    function showError(msg) {
        $('.ciklik-manage-success').hide();
        $('.ciklik-manage-danger').html(msg).show();
    }

    function doManageAction(data, $btn) {
        $btn.attr('disabled', true);
        $('.ciklik-manage-success').hide();
        $('.ciklik-manage-danger').hide();

        data.ajax_token = ajaxToken;
        data.subscriptionUuid = subscriptionUuid;
        data.ajax = true;

        $.ajax({
            type: 'POST',
            url: manageUrl,
            dataType: 'json',
            data: data
        })
        .done(function (resp) {
            showSuccess(resp.message);

            if (typeof resp.subscription.active !== 'undefined') {
                var $status = $('#ciklik-subscription-status');
                if (resp.subscription.active) {
                    $status.removeClass('badge-danger').addClass('badge-success').text(labelActive);
                    $('#ciklik-btn-activate').attr('id', 'ciklik-btn-deactivate')
                        .removeClass('btn-outline-success').addClass('btn-outline-danger')
                        .html(labelDeactivate);
                    $('#ciklik-btn-change-date').show();
                    bindDeactivate();
                } else {
                    $status.removeClass('badge-success').addClass('badge-danger').text(labelInactive);
                    $('#ciklik-btn-deactivate').attr('id', 'ciklik-btn-activate')
                        .removeClass('btn-outline-danger').addClass('btn-outline-success')
                        .html(labelActivate);
                    $('#ciklik-btn-change-date').hide();
                    $('#ciklik-change-date-form').hide();
                    bindActivate();
                }
            }

            if (resp.subscription.next_billing) {
                var parts = resp.subscription.next_billing.split('-');
                $('#ciklik-next-billing').text(parts[2] + '/' + parts[1] + '/' + parts[0]);
                $('#ciklik-change-date-form').hide();
            }
        })
        .fail(function (xhr) {
            try {
                var jsondata = JSON.parse(xhr.responseText);
                showError(jsondata.message);
            } catch (e) {
                showError('An error occurred');
            }
        })
        .always(function () {
            $btn.attr('disabled', false);
        });
    }

    function bindDeactivate() {
        $('#ciklik-btn-deactivate').off('click').on('click', function () {
            doManageAction({ action: 'deactivate' }, $(this));
        });
    }

    function bindActivate() {
        $('#ciklik-btn-activate').off('click').on('click', function () {
            doManageAction({ action: 'activate' }, $(this));
        });
    }

    bindDeactivate();
    bindActivate();

    $('#ciklik-btn-change-date').on('click', function () {
        $('#ciklik-change-date-form').show();
    });

    $('#ciklik-btn-cancel-date').on('click', function () {
        $('#ciklik-change-date-form').hide();
        $('#ciklik-new-date').val('');
    });

    $('#ciklik-btn-confirm-date').on('click', function () {
        var newDate = $('#ciklik-new-date').val();
        if (!newDate) return;
        doManageAction({ action: 'changeNextBilling', next_billing: newDate }, $(this));
    });
});
</script>
{/if}
