{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='Your subscriptions' mod='ciklik'}
{/block}

{block name='page_content'}
    {if $subscriptions}
        <table class="table table-striped table-bordered table-labeled">
            <thead class="thead-default">
            <tr>
                <th>{l s='Status' mod='ciklik'}</th>
                <th>{l s='Description' mod='ciklik'}</th>
                <th>{l s='Shipped to' mod='ciklik'}</th>
                <th>{l s='Next order' mod='ciklik'}</th>
                <th>&nbsp;</th>
            </tr>
            </thead>
            <tbody>
            {foreach from=$subscriptions item=subscription}
                <tr>
                    <td>
                        {if $subscription->active}
                            <span class="label label-pill badge badge-pill badge-success rounded-pill text-bg-success" style="background-color:#32CD32">{l s='Active' mod='ciklik'}</span>
                        {else}
                            <span class="label label-pill badge badge-pill badge-danger rounded-pill text-bg-danger" style="background-color:#8f0621">{l s='Inactive' mod='ciklik'}</span>
                        {/if}
                    </td>
                    <td>
                        <div>{$subscription->display_content|escape:'html':'UTF-8'}</div>

                        <div>{$subscription->display_interval|escape:'html':'UTF-8'}</div>

                        {if $enable_change_interval === '1'}
                            {include file="module:ciklik/views/templates/front/actions/changeInterval.tpl" subscription=$subscription }
                        {/if}
                        {if !empty($subscription->contents) && $subscription->active}
                            {include file="module:ciklik/views/templates/front/actions/subscriptionProducts.tpl" subscription=$subscription}
                        {/if}
                        {if !empty($subscription->upsells)}
                            {include file="module:ciklik/views/templates/front/actions/ListUpsellSubscriptionAndDelete.tpl" subscription=$subscription}
                        {/if}
                        {* Affichage des customizations *}
                        {if !empty($subscription->contents)}
                            {* Vérifier s'il y a au moins un élément avec des customizations *}
                            {assign var="has_customizations" value=false}
                            {foreach from=$subscription->contents item=content}
                                {if !empty($content.customizations)}
                                    {assign var="has_customizations" value=true}
                                {/if}
                            {/foreach}

                            {if $has_customizations}
                                <div class="subscription-customizations">
                                    <div class="customization-title">{l s='Customizations' mod='ciklik'}</div>
                                    {foreach from=$subscription->contents item=content}
                                        {if !empty($content.customizations)}
                                            <div>
                                            <a class="text-muted" data-toggle="collapse" data-bs-toggle="collapse" href="#customizations{$subscription->uuid|escape:'html':'UTF-8'}-{$content@index}" role="button" aria-expanded="false" aria-controls="customizations{$subscription->uuid|escape:'html':'UTF-8'}-{$content@index}">
                                                <i class="material-icons" style="font-size: 15px;">add</i> <small>
                                                {$content.name|escape:'html':'UTF-8'}
                                                </small>
                                            </a>
                                            <div class="collapse" id="customizations{$subscription->uuid|escape:'html':'UTF-8'}-{$content@index}">
                                                {include file="module:ciklik/views/templates/front/subscription_customizations.tpl" customizations=$content.customizations}
                                            </div>
                                            </div>
                                        {/if}
                                    {/foreach}
                                </div>
                            {/if}
                        {/if}
                    </td>
                    <td>
                        {$subscription->address->first_name|escape:'html':'UTF-8'} {$subscription->address->last_name|escape:'html':'UTF-8'}<br>
                        {$subscription->address->address|escape:'html':'UTF-8'} <br>

                        {$subscription->address->postcode|escape:'html':'UTF-8'} {$subscription->address->city|escape:'html':'UTF-8'}, {$subscription->address->country|escape:'html':'UTF-8'}
                        <br>
                        {include file="module:ciklik/views/templates/front/actions/changeDeliveryAddress.tpl" subscription=$subscription addresses=$addresses}
                    </td>
                    <td>
                        {if $subscription->active}
                            {$subscription->next_billing->format('d/m/Y')}
                            <br>
                            {if $allow_change_next_billing === '1'}
                                {include file="module:ciklik/views/templates/front/actions/changeRebillDate.tpl" subscription=$subscription}
                            {/if}
                            {if $enable_skip_next_delivery === '1'}
                                <br>
                                {include file="module:ciklik/views/templates/front/actions/skipNextDelivery.tpl" subscription=$subscription}
                            {/if}
                        {/if}
                    </td>
                    <td class="text-sm-center order-actions">
                        {if $enable_engagement === '1'}
                            {if PrestaShop\Module\Ciklik\Helpers\IntervalHelper::addIntervalToDate(
                                $subscription->created_at,
                                $engagement_interval,
                                $engagement_interval_count
                                )
                            ->getTimestamp() < $smarty.now}
                                    {if $subscription->active}
                                        <form action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/stop" method="POST" style="display:inline;">
                                            <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                            <button type="submit" class="btn btn-link" style="padding:0;">{l s='Stop' mod='ciklik'}</button>
                                        </form>
                                    {else}
                                        <form action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/resume" method="POST" style="display:inline;">
                                            <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                            <button type="submit" class="btn btn-link" style="padding:0;">{l s='Resume' mod='ciklik'}</button>
                                        </form>
                                    {/if}
                                {else}
                                <small>
                                    {l s='Cancellable from:' mod='ciklik'} <br>
                                    {PrestaShop\Module\Ciklik\Helpers\IntervalHelper::addIntervalToDate(
                                            $subscription->created_at,
                                            $engagement_interval,
                                            $engagement_interval_count
                                        )->format('d/m')}
                                </small>
                                {/if}
                        {else}
                            {if $subscription->active}
                                <form action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/stop" method="POST" style="display:inline;">
                                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                    <button type="submit" class="btn btn-link" style="padding:0;">{l s='Stop' mod='ciklik'}</button>
                                </form>
                            {else}
                                <form action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/resume" method="POST" style="display:inline;">
                                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                    <button type="submit" class="btn btn-link" style="padding:0;">{l s='Resume' mod='ciklik'}</button>
                                </form>
                            {/if}
                        {/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {else}
        <div class="alert alert-info">{l s='You have no subscription.' mod='ciklik'}</div>
    {/if}
{/block}
