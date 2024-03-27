{**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
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
