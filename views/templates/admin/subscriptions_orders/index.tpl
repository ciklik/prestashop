{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i>
        {l s='Abonnements et Commandes' mod='ciklik'}
    </div>
    <div class="panel-body">
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="{if $current_tab == 'subscriptions'}active{/if}">
                <a href="{$link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], ['tab' => 'subscriptions'])|escape:'html':'UTF-8'}" aria-controls="subscriptions" role="tab">
                    {l s='Abonnements' mod='ciklik'}
                </a>
            </li>
            <li role="presentation" class="{if $current_tab == 'orders'}active{/if}">
                <a href="{$link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], ['tab' => 'orders'])|escape:'html':'UTF-8'}" aria-controls="orders" role="tab">
                    {l s='Commandes' mod='ciklik'}
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane {if $current_tab == 'subscriptions'}active{/if}" id="subscriptions">
                {$subscriptions_content nofilter}
            </div>
            <div role="tabpanel" class="tab-pane {if $current_tab == 'orders'}active{/if}" id="orders">
                {$orders_content nofilter}
            </div>
        </div>
    </div>
</div>

