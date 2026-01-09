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

<form method="get" action="{$link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], ['tab' => 'orders'])|escape:'html':'UTF-8'}" class="form-horizontal">
    <input type="hidden" name="controller" value="AdminCiklikSubscriptionsOrders">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="tab" value="orders">

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-filter"></i>
            {l s='Filtres' mod='ciklik'}
        </div>
        <div class="form-wrapper">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Statut' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <select name="filter_status" class="form-control fixed-width-md">
                        <option value="">{l s='-- Tous --' mod='ciklik'}</option>
                        <option value="pending" {if $filters.filter_status == 'pending'}selected{/if}>{l s='En attente' mod='ciklik'}</option>
                        <option value="completed" {if $filters.filter_status == 'completed'}selected{/if}>{l s='Terminé' mod='ciklik'}</option>
                        <option value="canceled" {if $filters.filter_status == 'canceled'}selected{/if}>{l s='Annulé' mod='ciklik'}</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='UUID Abonnement' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_subscription_uuid" value="{$filters.filter_subscription_uuid|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='ID Utilisateur' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_user_id" value="{$filters.filter_user_id|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Total payé' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_total_paid" value="{$filters.filter_total_paid|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Source' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_source" value="{$filters.filter_source|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='UUID Client' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_by_customer_uuid" value="{$filters.filter_by_customer_uuid|escape:'html':'UTF-8'}" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='ID Commande PrestaShop' mod='ciklik'}</label>
                <div class="col-lg-9">
                    <input type="text" name="filter_prestashop_order_id" value="{$filters.filter_prestashop_order_id|escape:'html':'UTF-8'}" class="form-control">
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
        {l s='Liste des Commandes' mod='ciklik'}
    </div>
    <div class="table-responsive-row clearfix">
        <table class="table">
            <thead>
                <tr class="nodrag nodrop">
                    <th>{l s='Commande Ciklik' mod='ciklik'}</th>
                    <th>{l s='Statut' mod='ciklik'}</th>
                    <th>{l s='Total payé' mod='ciklik'}</th>
                    <th>{l s='Créé le' mod='ciklik'}</th>
                    <th>{l s='UUID Abonnement' mod='ciklik'}</th>
                    <th>{l s='Commande PrestaShop' mod='ciklik'}</th>
                </tr>
            </thead>
            <tbody>
                {if !empty($orders)}
                    {foreach $orders as $order}
                        <tr>
                            <td>
                                <a href="https://app.ciklik.co/app/resources/checkout-orders/{$order.order_id|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                                    {$order.order_id|escape:'html':'UTF-8'}
                                </a>
                            </td>
                            <td>
                                {if $order.status == 'completed'}
                                    <span class="label label-success">{l s='Payée' mod='ciklik'}</span>
                                {elseif $order.status == 'pending'}
                                    <span class="label label-warning">{l s='En attente' mod='ciklik'}</span>
                                {elseif $order.status == 'failed'}
                                    <span class="label label-danger">{l s='Echec' mod='ciklik'}</span>
                                {else}
                                    <span class="label label-default">{$order.status|escape:'html':'UTF-8'}</span>
                                {/if}
                            </td>
                            <td class="text-right">
                                {if isset($order.total_paid)}
                                    {$order.total_paid|escape:'html':'UTF-8'}{l s='€' mod='ciklik'}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if isset($order.created_at)}
                                    {$order.created_at|escape:'html':'UTF-8'}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                {if isset($order.subscription_uuid) && $order.subscription_uuid}
                                    {$order.subscription_uuid|escape:'html':'UTF-8'}
                                    {if isset($order.customer_email) && $order.customer_email}
                                        <br>
                                        <strong>{l s='Client:' mod='ciklik'}</strong><br>
                                        <a href="mailto:{$order.customer_email|escape:'html':'UTF-8'}">{$order.customer_email|escape:'html':'UTF-8'}</a>
                                        {if isset($order.customer_link) && $order.customer_link}
                                            <br>
                                            <a href="{$order.customer_link|escape:'html':'UTF-8'}" target="_blank" class="btn btn-xs btn-default">
                                                <i class="icon-user"></i> {l s='Voir la fiche client' mod='ciklik'}
                                            </a>
                                        {/if}
                                    {/if}
                                {else}
                                    -
                                {/if}
                            </td>
                            <td>
                                
                                {if isset($order.prestashop_order_link) && $order.prestashop_order_link}
                                    <a href="{$order.prestashop_order_link|escape:'html':'UTF-8'}" target="_blank" class="btn btn-default">
                                        <i class="icon-external-link"></i> {l s='Voir la commande' mod='ciklik'} #{$order.prestashop_order_id|escape:'html':'UTF-8'}
                                    </a>
                                {elseif isset($order.prestashop_order_id) && $order.prestashop_order_id}
                                    {l s='Commande' mod='ciklik'} #{$order.prestashop_order_id|escape:'html':'UTF-8'}
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                {else}
                    <tr>
                        <td colspan="6" class="text-center">{l s='Aucune commande trouvée' mod='ciklik'}</td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
    {include file='module:ciklik/views/templates/admin/subscriptions_orders/pagination.tpl'}
</div>

