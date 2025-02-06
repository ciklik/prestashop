{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='Subscriptions' mod='ciklik'}
{/block}

{block name='page_content'}
    {if $subscriptions}
        <table class="table table-striped table-bordered table-labeled">
            <thead class="thead-default">
            <tr>
                <th>{l s='Status' mod='ciklik'}</th>
                <th>{l s='Description' mod='ciklik'}</th>
                <th>{l s='Shipped to' mod='ciklik'}</th>
                <th>{l s='Next billing' mod='ciklik'}</th>
                <th>&nbsp;</th>
            </tr>
            </thead>
            <tbody>
            {foreach from=$subscriptions item=subscription}
                <tr>
                    <td>
                        {if $subscription->active}
                            <span class="label label-pill" style="background-color:#32CD32">
                {l s='Active' mod='ciklik'}
              </span>
                        {else}
                            <span class="label label-pill" style="background-color:#8f0621">
                {l s='Inactive' mod='ciklik'}
              </span>
                        {/if}
                    </td>
                    <td>
                        <div>{$subscription->display_content}</div>
                        
                        <div>{$subscription->display_interval}</div>
                        {if $enable_change_interval === '1'}
                            {include file="module:ciklik/views/templates/front/actions/changeInterval.tpl" subscription=$subscription}
                        {/if}
                        {if !empty($subscription->upsells)}
                            {include file="module:ciklik/views/templates/front/actions/ListUpsellSubscriptionAndDelete.tpl" subscription=$subscription}
                        {/if}
                    </td>
                    <td>
                        {$subscription->address->first_name} {$subscription->address->last_name}<br>
                        {$subscription->address->address} <br>

                        {$subscription->address->postcode} {$subscription->address->city}, {$subscription->address->country}
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
                        {/if}
                    </td>
                    <td class="text-sm-center order-actions">
                        {if $enable_engagement === '1'}
                            {if PrestaShop\Module\Ciklik\Helpers\IntervalHelper::addIntervalToDate(
                                $subscription->created_at->toImmutable(),
                                $engagement_interval,
                                $engagement_interval_count
                                )
                            ->isPast()}
                                    {if $subscription->active}
                                        <a href="{$subcription_base_link}/{$subscription->uuid}/stop">{l s='Stop' mod='ciklik'}</a>
                                    {else}
                                        <a href="{$subcription_base_link}/{$subscription->uuid}/resume">{l s='Resume' mod='ciklik'}</a>
                                    {/if}
                                {else}
                                <small>
                                    Résiliable à partir du : <br>
                                    {PrestaShop\Module\Ciklik\Helpers\IntervalHelper::addIntervalToDate(
                                            $subscription->created_at->toImmutable(),
                                            $engagement_interval,
                                            $engagement_interval_count
                                        )->format('d/m')}
                                </small>
                                {/if}
                        {else}
                            {if $subscription->active}
                                <a href="{$subcription_base_link}/{$subscription->uuid}/stop">{l s='Stop' mod='ciklik'}</a>
                            {else}
                                <a href="{$subcription_base_link}/{$subscription->uuid}/resume">{l s='Resume' mod='ciklik'}</a>
                            {/if}
                        {/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {else}
        <div class="alert alert-info">{l s='No subscription' mod='ciklik'}</div>
    {/if}
{/block}
