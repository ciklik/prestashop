{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{if !empty($ciklik_bs5)}
  {* Hummingbird : même balisage que les liens natifs du menu du compte *}
  <a class="account-menu__link account-menu__link--ciklik{if isset($urls.current_url) && $urls.current_url === $transactionsLink} account-menu__link--active{/if}" id="ciklik-displayCustomerAccount-link" href="{$transactionsLink|escape:'html':'UTF-8'}"{if isset($urls.current_url) && $urls.current_url === $transactionsLink} aria-current="page"{/if}>
    <i class="account-menu__icon material-icons" aria-hidden="true">&#xE916;</i>
    {l s='Subscriptions' mod='ciklik'}
  </a>
{else}
  <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="ciklik-displayCustomerAccount-link" href="{$transactionsLink|escape:'html':'UTF-8'}">
    <span class="link-item">
      <i class="material-icons">&#xE916;</i>
      {l s='Subscriptions' mod='ciklik'}
    </span>
  </a>
{/if}
