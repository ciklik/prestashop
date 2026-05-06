{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{if $usePsAccounts|default:false}
    <div id="ajax_confirmation" class="alert alert-success hide"></div>
    {* ajaxBox allows*}
    <div id="ajaxBox" style="display:none"></div>
    <div id="content-message-box"></div>

    <prestashop-accounts></prestashop-accounts>

    {if isset($content)}
        {$content}
    {/if}

    <script src="{$urlAccountsCdn|escape:'htmlall':'UTF-8'}" rel=preload></script>

    <script>
        /*********************
     * PrestaShop Account *
     * *******************/
        window?.psaccountsVue?.init();
    </script>
{else}
    {if isset($content)}
        {$content}
    {/if}
{/if}
