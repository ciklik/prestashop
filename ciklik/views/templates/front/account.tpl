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
                        <div><strong>{$subscription->display_interval}</strong></div>
                    </td>
                    <td>
                        {$subscription->address->first_name} {$subscription->address->last_name}<br>
                        {$subscription->address->address} <br>

                        {$subscription->address->postcode} {$subscription->address->city}, {$subscription->address->country}
                    </td>
                    <td>
                        {if $subscription->active}
                            {$subscription->next_billing->format('d/m/Y')}
                        {/if}
                    </td>
                    <td class="text-sm-center order-actions">
                        {if $subscription->active}
                            <a href="{$subcription_base_link}/{$subscription->uuid}/stop">{l s='Stop' mod='ciklik'}</a>
                        {else}
                            <a href="{$subcription_base_link}/{$subscription->uuid}/resume">{l s='Resume' mod='ciklik'}</a>
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
