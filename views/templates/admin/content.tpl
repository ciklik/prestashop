{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
<div id="ajax_confirmation" class="alert alert-success hide"></div>
{* ajaxBox allows*}
<div id="ajaxBox" style="display:none"></div>
<div id="content-message-box"></div>

<prestashop-accounts></prestashop-accounts>
<div id="ps-billing"></div>
<div id="ps-modal"></div>

{if $hasSubscription && isset($content)}
    {$content}
{/if}

<script src="{$urlAccountsCdn|escape:'htmlall':'UTF-8'}" rel=preload></script>
<script src="{$urlBilling|escape:'htmlall':'UTF-8'}" rel=preload></script>


<script>
    /*********************
     * PrestaShop Account *
     * *******************/
    window?.psaccountsVue?.init();

    // Check if Account is associated before displaying Billing component
    if(window.psaccountsVue.isOnboardingCompleted() == true)
    {
        /*********************
         * PrestaShop Billing *
         * *******************/
        window.psBilling.initialize(window.psBillingContext.context, '#ps-billing', '#ps-modal', (type, data) => {
            // Event hook listener
            switch (type) {
                // Hook triggered when the subscription is created
                case window.psBilling.EVENT_HOOK_TYPE.SUBSCRIPTION_CREATED:
                    console.log('subscription created', data);
                    break;
                // Hook when the subscription is updated
                case window.psBilling.EVENT_HOOK_TYPE.SUBSCRIPTION_UPDATED:
                    console.log('subscription updated', data);
                    break;
                // Hook triggered when the subscription is cancelled
                case window.psBilling.EVENT_HOOK_TYPE.SUBSCRIPTION_CANCELLED:
                    console.log('subscription cancelled', data);
                    break;
            }
        });
    }
</script>
