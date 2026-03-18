{*
* @author    Metrogeek SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

<div class="panel product-tab">
    <div class="panel-heading">
        <i class="icon-refresh"></i> {l s='Ciklik Subscription' mod='ciklik'}
        {if $has_frequencies}
            <a href="{$admin_frequencies_link|escape:'html':'UTF-8'}" class="btn btn-default btn-sm pull-right">
                <i class="icon-cog"></i> {l s='Manage frequencies' mod='ciklik'}
            </a>
        {/if}
    </div>

    {* Bannière de statut avec toggle intégré *}
    <div class="{if $ciklik_subscription_enabled}alert alert-success{else}alert alert-warning{/if}" id="ciklik-subscription-status">
        <div class="row">
            <div class="col-lg-8">
                <p id="ciklik-status-enabled" {if !$ciklik_subscription_enabled}style="display:none"{/if}>
                    <i class="icon-check"></i>
                    <strong>{l s='Subscriptions are enabled for this product.' mod='ciklik'}</strong><br>
                    <small>{l s='Customers can choose a subscription frequency when adding this product to their cart.' mod='ciklik'}</small>
                </p>
                <p id="ciklik-status-disabled" {if $ciklik_subscription_enabled}style="display:none"{/if}>
                    <i class="icon-warning-sign"></i>
                    <strong>{l s='Subscriptions are disabled for this product.' mod='ciklik'}</strong><br>
                    <small>{l s='Enable to allow customers to subscribe to this product at checkout.' mod='ciklik'}</small>
                </p>
            </div>
            <div class="col-lg-4 text-right">
                <span class="switch prestashop-switch fixed-width-lg">
                    <input type="radio" name="ciklik_subscription_enabled" id="ciklik_subscription_enabled_on" value="1" {if $ciklik_subscription_enabled}checked="checked"{/if}>
                    <label for="ciklik_subscription_enabled_on">{l s='Yes' mod='ciklik'}</label>
                    <input type="radio" name="ciklik_subscription_enabled" id="ciklik_subscription_enabled_off" value="0" {if !$ciklik_subscription_enabled}checked="checked"{/if}>
                    <label for="ciklik_subscription_enabled_off">{l s='No' mod='ciklik'}</label>
                    <a class="slide-button btn"></a>
                </span>
            </div>
        </div>
    </div>

    {* Section fréquences *}
    <div id="ciklik-frequencies-section">
        {if !$has_frequencies}
            <div class="alert alert-info">
                <p>
                    <i class="icon-info-circle"></i>
                    {l s='No subscription frequencies have been created yet.' mod='ciklik'}
                </p>
                <a href="{$admin_frequencies_link|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
                    <i class="icon-plus"></i> {l s='Create your first frequency' mod='ciklik'}
                </a>
            </div>
        {else}
            <div class="form-group">
                <label class="control-label col-lg-3">
                    {l s='Available frequencies' mod='ciklik'}
                    <p class="help-block">
                        <span id="ciklik-selection-count">{$selected_count|intval}</span> / {$total_count|intval} {l s='selected' mod='ciklik'}
                    </p>
                </label>
                <div class="col-lg-9">
                    {* Boutons sélection *}
                    <div class="btn-group btn-group-sm" id="ciklik-bulk-actions">
                        <button type="button" class="btn btn-default" id="ciklik-select-all">
                            <i class="icon-check-square-o"></i> {l s='Select all' mod='ciklik'}
                        </button>
                        <button type="button" class="btn btn-default" id="ciklik-select-none">
                            <i class="icon-square-o"></i> {l s='Deselect all' mod='ciklik'}
                        </button>
                    </div>

                    {* Groupes de fréquences par intervalle *}
                    {foreach from=$grouped_frequencies key=interval item=group}
                        {if !empty($group.frequencies)}
                            <div class="form-group">
                                {if $group_count > 1}
                                    <h4>
                                        <i class="icon-time"></i>
                                        {$group.label|escape:'html':'UTF-8'}
                                    </h4>
                                {/if}
                                <div class="row">
                                    {foreach from=$group.frequencies item=frequency}
                                        <div class="col-lg-6 col-md-6">
                                            <div class="checkbox">
                                                <label>
                                                    <input type="checkbox"
                                                           name="ciklik_frequencies[]"
                                                           value="{$frequency.id_frequency|intval}"
                                                           {if in_array($frequency.id_frequency, $selected_frequencies)}checked="checked"{/if}>
                                                    <strong>{$frequency.name|escape:'html':'UTF-8'}</strong>
                                                    <small class="text-muted">&mdash; {$frequency.description|escape:'html':'UTF-8'}</small>
                                                    {if $frequency.discount_percent > 0}
                                                        <span class="label label-success">-{$frequency.discount_percent|floatval}%</span>
                                                    {/if}
                                                    {if $frequency.discount_price > 0}
                                                        <span class="label label-info">-{$frequency.discount_price|string_format:"%.2f"}{$currency_sign|escape:'html':'UTF-8'}</span>
                                                    {/if}
                                                </label>
                                            </div>
                                        </div>
                                    {/foreach}
                                </div>
                            </div>
                        {/if}
                    {/foreach}
                </div>
            </div>
        {/if}
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        var $section = $('#ciklik-frequencies-section');
        var $status = $('#ciklik-subscription-status');
        var $checkboxes = $section.find('input[name="ciklik_frequencies[]"]');
        var $bulkActions = $('#ciklik-bulk-actions');
        var $counter = $('#ciklik-selection-count');

        function updateCounter() {
            $counter.text($checkboxes.filter(':checked').length);
        }

        function toggleFrequencies(enabled) {
            $checkboxes.prop('disabled', !enabled);
            $bulkActions.find('button').prop('disabled', !enabled);
            $section.css('opacity', enabled ? '1' : '0.5');

            // Mise à jour de la bannière de statut
            $status.removeClass('alert-success alert-warning');
            $status.addClass(enabled ? 'alert-success' : 'alert-warning');
            $('#ciklik-status-enabled').toggle(enabled);
            $('#ciklik-status-disabled').toggle(!enabled);
        }

        // Toggle activation
        $('#ciklik_subscription_enabled_on').change(function() {
            if ($(this).is(':checked')) {
                toggleFrequencies(true);
            }
        });
        $('#ciklik_subscription_enabled_off').change(function() {
            if ($(this).is(':checked')) {
                toggleFrequencies(false);
            }
        });

        // Sélection groupée
        $('#ciklik-select-all').click(function(e) {
            e.preventDefault();
            $checkboxes.prop('checked', true);
            updateCounter();
        });
        $('#ciklik-select-none').click(function(e) {
            e.preventDefault();
            $checkboxes.prop('checked', false);
            updateCounter();
        });

        // Mise à jour compteur au clic
        $checkboxes.change(function() {
            updateCounter();
        });

        // État initial
        toggleFrequencies($('#ciklik_subscription_enabled_on').is(':checked'));
    });
</script>
