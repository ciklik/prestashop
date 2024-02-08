{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{if count($frequency)}
  <input type="hidden" name="id_frequency" value="{$frequency.id_frequency}">
{/if}

<div class="form-group">
  <label class="control-label col-lg-4 required" for="customer-group">{l s='Type d\'intervalle' mod='ciklik'}</label>
  <div class="col-lg-8">
    <select name="interval" class="fixed-width-xl">
      {foreach from=$intervals item=interval}
        <option value="{$interval.value}" {if count($frequency) && $frequency.interval === $interval.value}selected{/if}>{$interval.label}</option>
      {/foreach}
    </select>
  </div>
</div>

<div class="form-group">
  <label class="control-label col-lg-4 required" for="customer-group">{l s='Valeur d\'intervalle' mod='ciklik'}</label>
  <div class="col-lg-8">
    <select name="interval_count" class="fixed-width-xl">
      {foreach from=$interval_counts item=interval_count}
        <option value="{$interval_count}" {if count($frequency) && $frequency.interval_count === $interval_count}selected{/if}>{$interval_count}</option>
      {/foreach}
    </select>
  </div>
</div>
