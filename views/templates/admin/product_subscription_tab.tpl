{*
* @author    Ciklik SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

<div class="panel product-tab">
    <h3>{l s='Abonnement Ciklik' mod='ciklik'}</h3>
    
    <div class="form-group">
        <label class="control-label col-lg-3">
            {l s='Activer les abonnements' mod='ciklik'}
        </label>
        <div class="col-lg-9">
            <span class="switch prestashop-switch fixed-width-lg">
                <input type="radio" name="ciklik_subscription_enabled" id="ciklik_subscription_enabled_on" value="1" {if $ciklik_subscription_enabled}checked="checked"{/if}>
                <label for="ciklik_subscription_enabled_on">{l s='Yes' mod='ciklik'}</label>
                <input type="radio" name="ciklik_subscription_enabled" id="ciklik_subscription_enabled_off" value="0" {if !$ciklik_subscription_enabled}checked="checked"{/if}>
                <label for="ciklik_subscription_enabled_off">{l s='No' mod='ciklik'}</label>
                <a class="slide-button btn"></a>
            </span>
        </div>
    </div>

    <div class="form-group">
        <label class="control-label col-lg-3">
            {l s='Fréquences disponibles' mod='ciklik'}
        </label>
        <div class="col-lg-9">
            <div class="row">
                {foreach from=$ciklik_frequencies item=frequency}
                    <div class="col-lg-4">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" 
                                       name="ciklik_frequencies[]" 
                                       value="{$frequency.id_frequency|intval}"
                                       {if in_array($frequency.id_frequency, $selected_frequencies)}checked="checked"{/if}>
                                {$frequency.name|escape:'html':'UTF-8'}
                                {if $frequency.discount_percent > 0}
                                    (-{$frequency.discount_percent|floatval}%)
                                {/if}
                            </label>
                        </div>
                    </div>
                {/foreach}
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        // Gestion de l'activation/désactivation des fréquences
        $('#ciklik_subscription_enabled_off').change(function() {
            if ($(this).is(':checked')) {
                $('input[name="ciklik_frequencies[]"]').prop('disabled', true);
            }
        });
        
        $('#ciklik_subscription_enabled_on').change(function() {
            if ($(this).is(':checked')) {
                $('input[name="ciklik_frequencies[]"]').prop('disabled', false);
            }
        });
        
        // Désactive les fréquences si l'abonnement est désactivé au chargement
        if ($('#ciklik_subscription_enabled_off').is(':checked')) {
            $('input[name="ciklik_frequencies[]"]').prop('disabled', true);
        }
    });
</script> 