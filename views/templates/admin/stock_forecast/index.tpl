{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{* Filtres de date *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-filter"></i>
        {l s='Forecast period' mod='ciklik'}
    </div>
    <div class="panel-body">
        <form method="get" class="form-inline">
            <input type="hidden" name="controller" value="AdminCiklikStockForecast">
            <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">

            <div class="form-group" style="margin-right: 15px;">
                <label for="date_from" style="margin-right: 5px;">{l s='From' mod='ciklik'}</label>
                <input type="date" class="form-control" id="date_from" name="date_from"
                       value="{$date_from|escape:'html':'UTF-8'}">
            </div>

            <div class="form-group" style="margin-right: 15px;">
                <label for="date_to" style="margin-right: 5px;">{l s='To' mod='ciklik'}</label>
                <input type="date" class="form-control" id="date_to" name="date_to"
                       value="{$date_to|escape:'html':'UTF-8'}">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="icon-search"></i>
                {l s='Compute forecast' mod='ciklik'}
            </button>
        </form>
    </div>
</div>

{* Erreurs *}
{if !empty($errors)}
    <div class="alert alert-danger">
        <ul>
            {foreach from=$errors item=error}
                <li>{$error|escape:'html':'UTF-8'}</li>
            {/foreach}
        </ul>
    </div>
{/if}

{* Statistiques *}
<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="text-align: center;">
            <div style="font-size: 28px; font-weight: bold;">{$stats.total_subscriptions|intval}</div>
            <div style="color: #666;">{l s='Active subscriptions' mod='ciklik'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="text-align: center;">
            <div style="font-size: 28px; font-weight: bold;">{$stats.filtered_subscriptions|intval}</div>
            <div style="color: #666;">{l s='Renewals in period' mod='ciklik'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="text-align: center;">
            <div style="font-size: 28px; font-weight: bold;">{$stats.total_products|intval}</div>
            <div style="color: #666;">{l s='Distinct products' mod='ciklik'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="text-align: center;">
            <div style="font-size: 28px; font-weight: bold; {if $stats.alerts > 0}color: #e74c3c;{else}color: #27ae60;{/if}">{$stats.alerts|intval}</div>
            <div style="color: #666;">{l s='Stock alerts' mod='ciklik'}</div>
        </div>
    </div>
</div>

{* Tableau de prévision *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-bar-chart"></i>
        {l s='Stock forecast' mod='ciklik'}
        <span class="badge">{$forecast|count}</span>
    </div>

    {if empty($forecast)}
        <div class="panel-body">
            <div class="alert alert-info">
                {l s='No renewals planned for this period.' mod='ciklik'}
            </div>
        </div>
    {else}
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>{l s='Product' mod='ciklik'}</th>
                        <th>{l s='Combination' mod='ciklik'}</th>
                        <th class="text-center text-right">{l s='Quantity needed' mod='ciklik'}</th>
                        <th class="text-center text-right">{l s='Current stock' mod='ciklik'}</th>
                        <th class="text-center text-right">{l s='Stock after' mod='ciklik'}</th>
                        <th class="text-center">{l s='Status' mod='ciklik'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$forecast item=item}
                        <tr {if $item.alert}class="danger"{/if}>
                            <td>
                                {$item.product_name|escape:'html':'UTF-8'}
                                <small class="text-muted">(#{$item.id_product|intval})</small>
                            </td>
                            <td>
                                {if $item.combination_name}
                                    {$item.combination_name|escape:'html':'UTF-8'}
                                    <small class="text-muted">(#{$item.id_product_attribute|intval})</small>
                                {else}
                                    <span class="text-muted">-</span>
                                {/if}
                            </td>
                            <td class="text-center text-right">{$item.quantity|intval}</td>
                            <td class="text-center text-right">{$item.current_stock|intval}</td>
                            <td class="text-center text-right">
                                <strong {if $item.stock_after < 0}style="color: #e74c3c;"{elseif $item.stock_after < $low_stock_threshold}style="color: #f39c12;"{else}style="color: #27ae60;"{/if}>
                                    {$item.stock_after|intval}
                                </strong>
                            </td>
                            <td class="text-center">
                                {if $item.alert}
                                    <span class="label label-danger">{l s='Insufficient' mod='ciklik'}</span>
                                {elseif $item.stock_after < $low_stock_threshold}
                                    <span class="label label-warning">{l s='Low' mod='ciklik'}</span>
                                {else}
                                    <span class="label label-success">{l s='OK' mod='ciklik'}</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {/if}
</div>
