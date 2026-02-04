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
              {$ciklik_product_price_formatted}
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
                    -{$frequency.formatted_discount_price}
                  {/if}
                </span>
              </div>
              <div class="frequency-price">
                <span class="original-price" data-base-price="0">
                  {$frequency.formatted_original_price}
                </span>
                <span class="discounted-price"
                      data-discount-percent="{$frequency.discount_percent|floatval}"
                      data-discount-price="{$frequency.discount_price|floatval}"
                      data-base-price="0">
                  {$frequency.formatted_discounted_price}
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
                  {$ciklik_product_price_formatted}
                </span>
              </div>
            </label>
          </div>
        {/if}
      {/foreach}
    </div>
  </div>
{/if}
