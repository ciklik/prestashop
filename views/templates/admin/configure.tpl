{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{if $usePsAccounts|default:false}
    <prestashop-accounts></prestashop-accounts>

    <script src="{$urlAccountsCdn|escape:'htmlall':'UTF-8'}" rel=preload></script>

    <script>
        /*********************
     * PrestaShop Account *
     * *******************/
        window?.psaccountsVue?.init();
    </script>
{/if}
