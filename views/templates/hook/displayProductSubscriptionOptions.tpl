{*
* @author    Ciklik SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

{if $ciklik_subscription_enabled}
  <div class="ciklik-subscription-options" 
       data-currency-code="{if isset($currency.iso_code)}{$currency.iso_code}{else}EUR{/if}"
       data-locale="{if isset($language.iso_code)}{$language.iso_code}{else}fr{/if}"
       data-base-price="{if isset($product.price) && $product.price}{$product.price}{else}0{/if}">
    <h3 class="subscription-title">{l s='Type d\'achat' mod='ciklik'}</h3>
    
    <div class="subscription-frequencies">
      {* Option achat unique *}
      <div class="frequency-option">
        <input type="radio" name="ciklik_frequency" id="frequency_0" value="0" checked>
        <label for="frequency_0" class="frequency-card">
          <div class="frequency-header">
            <span class="frequency-name">{l s='Achat unique' mod='ciklik'}</span>
          </div>
          <div class="frequency-price">
            <span class="current-price" data-base-price="0">
              {Tools::displayPrice($product.price)}
            </span>
          </div>
        </label>
      </div>

      {* Options d'abonnement avec réductions *}
      {foreach from=$ciklik_frequencies item=frequency}
        {if $frequency.discount_percent > 0 || $frequency.discount_price > 0}
          <div class="frequency-option">
            <input type="radio" name="ciklik_frequency" id="frequency_{$frequency.id_frequency}" value="{$frequency.id_frequency}">
            <label for="frequency_{$frequency.id_frequency}" class="frequency-card discount-card">
              <div class="frequency-header">
                <span class="frequency-name">{l s='Abonnement' mod='ciklik'} : {$frequency.name|escape:'html':'UTF-8'}</span>
                <span class="discount-badge">
                  {if $frequency.discount_percent > 0}
                    -{$frequency.discount_percent|floatval}%
                  {else}
                    -{Tools::displayPrice($frequency.discount_price)}
                  {/if}
                </span>
              </div>
              <div class="frequency-price">
                <span class="original-price" data-base-price="0">
                  {if isset($product.price)}{Tools::displayPrice($product.price)}{else}{Tools::displayPrice(0)}{/if}
                </span>
                <span class="discounted-price" 
                      data-discount-percent="{$frequency.discount_percent|floatval}"
                      data-discount-price="{$frequency.discount_price|floatval}"
                      data-base-price="0">
                  {if $frequency.discount_percent > 0}
                    {assign var=base_price value=($product.price|floatval)}
                    {assign var=discount_percent value=($frequency.discount_percent|floatval)}
                    {if $base_price > 0 && $discount_percent > 0}
                      {assign var=discounted_price value=$base_price * (1 - ($discount_percent / 100))}
                      {Tools::displayPrice($discounted_price)}
                    {else}
                      {if isset($product.price)}{Tools::displayPrice($product.price)}{else}{Tools::displayPrice(0)}{/if}
                    {/if}
                  {else}
                    {assign var=base_price value=($product.price|floatval)}
                    {assign var=discount_price value=($frequency.discount_price|floatval)}
                    {if $base_price > 0 && $discount_price > 0}
                      {assign var=discounted_price value=$base_price - $discount_price}
                      {if $discounted_price > 0}
                        {Tools::displayPrice($discounted_price)}
                      {else}
                        {Tools::displayPrice(0)}
                      {/if}
                    {else}
                      {if isset($product.price)}{Tools::displayPrice($product.price)}{else}{Tools::displayPrice(0)}{/if}
                    {/if}
                  {/if}
                </span>
              </div>
            </label>
          </div>
        {else}
          {* Fréquences sans réduction *}
          <div class="frequency-option">
            <input type="radio" name="ciklik_frequency" id="frequency_{$frequency.id_frequency}" value="{$frequency.id_frequency}">
            <label for="frequency_{$frequency.id_frequency}" class="frequency-card">
              <div class="frequency-header">
                <span class="frequency-name">{l s='Abonnement' mod='ciklik'} : {$frequency.name|escape:'html':'UTF-8'}</span>
              </div>
              <div class="frequency-price">
                <span class="current-price" data-base-price="0">
                  {if isset($product.price)}{Tools::displayPrice($product.price)}{else}{Tools::displayPrice(0)}{/if}
                </span>
              </div>
            </label>
          </div>
        {/if}
      {/foreach}
    </div>
  </div>
{/if} 