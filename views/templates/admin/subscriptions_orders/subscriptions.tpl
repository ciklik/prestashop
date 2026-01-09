{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{if !empty($errors)}
    <div class="alert alert-danger">
        <ul>
            {foreach $errors as $error}
                <li>{$error|escape:'html':'UTF-8'}</li>
            {/foreach}
        </ul>
    </div>
{/if}

<form method="get" action="{$link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], ['tab' => 'subscriptions'])|escape:'html':'UTF-8'}" class="form-horizontal">
    <input type="hidden" name="controller" value="AdminCiklikSubscriptionsOrders">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="tab" value="subscriptions">

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-filter"></i>
            {l s='Filtres' mod='ciklik'}
        </div>
        <div class="form-wrapper">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Activé' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <select name="filter_activated" class="form-control fixed-width-md">
                        <option value="">{l s='-- Tous --' mod='ciklik'}</option>
                        <option value="1" {if $filters.filter_activated == '1'}selected{/if}>{l s='Oui' mod='ciklik'}</option>
                        <option value="0" {if $filters.filter_activated == '0'}selected{/if}>{l s='Non' mod='ciklik'}</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Annulé' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <select name="filter_canceled" class="form-control fixed-width-md">
                        <option value="">{l s='-- Tous --' mod='ciklik'}</option>
                        <option value="1" {if $filters.filter_canceled == '1'}selected{/if}>{l s='Oui' mod='ciklik'}</option>
                        <option value="0" {if $filters.filter_canceled == '0'}selected{/if}>{l s='Non' mod='ciklik'}</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Expiré' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <select name="filter_expired" class="form-control fixed-width-md">
                        <option value="">{l s='-- Tous --' mod='ciklik'}</option>
                        <option value="1" {if $filters.filter_expired == '1'}selected{/if}>{l s='Oui' mod='ciklik'}</option>
                        <option value="0" {if $filters.filter_expired == '0'}selected{/if}>{l s='Non' mod='ciklik'}</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Email' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_subscriptions_by_email" value="{$filters.filter_subscriptions_by_email|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='ID Client' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_customer_id" value="{$filters.filter_customer_id|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <div class="col-lg-9 col-lg-offset-3">
                    <button type="submit" class="btn btn-default">
                        <i class="icon-search"></i> {l s='Rechercher' mod='ciklik'}
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i>
        {l s='Liste des Abonnements' mod='ciklik'}
    </div>
    <div class="table-responsive-row clearfix">
        <table class="table">
            <thead>
                <tr class="nodrag nodrop">
                    <th>{l s='ID Abo Ciklik' mod='ciklik'}</th>
                    <th>{l s='Actif' mod='ciklik'}</th>
                    <th>{l s='Créé le' mod='ciklik'}</th>
                    <th>{l s='Date de fin' mod='ciklik'}</th>
                    <th>{l s='Prochain paiement' mod='ciklik'}</th>
                    <th>{l s='Fréquence & Produits' mod='ciklik'}</th>
                </tr>
            </thead>
            <tbody>
                {if !empty($subscriptions)}
                    {foreach $subscriptions as $subscription}
                        <tr>
                            <td>{$subscription.uuid|escape:'html':'UTF-8'}
                            <br>
                            <strong>{l s='Client:' mod='ciklik'}</strong><br>
                             {if isset($subscription.customer_email) && $subscription.customer_email}
                                    <a href="mailto:{$subscription.customer_email|escape:'html':'UTF-8'}">{$subscription.customer_email|escape:'html':'UTF-8'}</a>
                                    {if isset($subscription.customer_link) && $subscription.customer_link}
                                        <br>
                                        <a href="{$subscription.customer_link|escape:'html':'UTF-8'}" target="_blank" class="btn btn-xs btn-default">
                                            <i class="icon-user"></i> {l s='Fiche client' mod='ciklik'}
                                        </a>
                                    {/if}
                                {elseif isset($subscription.customer_link) && $subscription.customer_link}
                                    <a href="{$subscription.customer_link|escape:'html':'UTF-8'}" target="_blank" class="btn btn-xs btn-default">
                                        <i class="icon-user"></i> {l s='Fiche client' mod='ciklik'}
                                    </a>
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if $subscription.active}
                                    <span class="label label-success">{l s='Oui' mod='ciklik'}</span>
                                {else}
                                    <span class="label label-danger">{l s='Non' mod='ciklik'}</span>
                                {/if}
                            </td>
                            <td>
                                {if isset($subscription.created_at)}
                                    {$subscription.created_at|escape:'html':'UTF-8'}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if isset($subscription.end_date) && $subscription.end_date}
                                    {$subscription.end_date|escape:'html':'UTF-8'}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if isset($subscription.next_billing)}
                                    {$subscription.next_billing|escape:'html':'UTF-8'}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                               {$subscription.display_interval|escape:'html':'UTF-8'} <br>
                               <strong>{l s='Produit(s):' mod='ciklik'}</strong><br>
                               {$subscription.display_content|escape:'html':'UTF-8'}
                            </td>
                        </tr>
                    {/foreach}
                {else}
                    <tr>
                        <td colspan="6" class="text-center">{l s='Aucun abonnement trouvé' mod='ciklik'}</td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
    {include file='module:ciklik/views/templates/admin/subscriptions_orders/pagination.tpl'}
</div>

