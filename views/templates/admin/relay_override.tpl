{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Bloc BO commande : point relais des prochains prélèvements.
 * Variables : ciklik_relay_* (voir DisplayOrderSubscriptionInfoHookController),
 * manageActionUrl / manageAjaxToken (assignées par le bloc principal).
 *}
<div class="card mt-2" id="ciklik-relay-card">
    <div class="card-header">
        <h3 class="card-header-title">
            <i class="material-icons">place</i>
            {l s='Pickup point for next payments' mod='ciklik'} — {$ciklik_relay_carrier_name|escape:'html':'UTF-8'}
        </h3>
    </div>
    <div class="card-body">
        <div class="alert alert-success ciklik-relay-success" style="display:none;"></div>
        <div class="alert alert-danger ciklik-relay-danger" style="display:none;"></div>

        <p>
            {if $ciklik_relay_current}
                <strong>{$ciklik_relay_current.relay_id|escape:'html':'UTF-8'}</strong>
                {if $ciklik_relay_current.label} — {$ciklik_relay_current.label|escape:'html':'UTF-8'}{/if}
                {if $ciklik_relay_current.source === 'override'}
                    <span class="badge badge-info">{l s='Set manually' mod='ciklik'}</span>
                {else}
                    <span class="badge badge-secondary">{l s='Automatic (from last paid order)' mod='ciklik'}</span>
                {/if}
            {else}
                <span class="text-muted">{l s='No pickup point found for this customer yet.' mod='ciklik'}</span>
            {/if}
        </p>
        <p class="text-muted small">
            {l s='The pickup point below will be used for future recurring orders only. Past orders are never modified. Please make sure the pickup point is open and can accept parcels.' mod='ciklik'}
        </p>
        <div class="alert alert-warning">
            {l s='This pickup point applies to every active subscription of this customer shipped with this carrier, not only the current order.' mod='ciklik'}
        </div>

        {if $ciklik_relay_search_supported}
            <link rel="stylesheet" href="{$ciklik_relay_assets_path|escape:'html':'UTF-8'}views/css/leaflet/leaflet.css">
            <script src="{$ciklik_relay_assets_path|escape:'html':'UTF-8'}views/js/leaflet/leaflet.js"></script>

            <div class="row align-items-end">
                <div class="form-group col-md-3">
                    <label for="ciklik-relay-search-zipcode">{l s='Zip code' mod='ciklik'}</label>
                    <input type="text" id="ciklik-relay-search-zipcode" class="form-control" value="{$ciklik_relay_prefill.zipcode|escape:'html':'UTF-8'}">
                </div>
                <div class="form-group col-md-3">
                    <label for="ciklik-relay-search-city">{l s='City' mod='ciklik'}</label>
                    <input type="text" id="ciklik-relay-search-city" class="form-control" value="{$ciklik_relay_prefill.city|escape:'html':'UTF-8'}">
                </div>
                <div class="form-group col-md-1">
                    <label for="ciklik-relay-search-country">{l s='Country' mod='ciklik'}</label>
                    <input type="text" id="ciklik-relay-search-country" class="form-control" value="FR" maxlength="2">
                </div>
                <div class="form-group col-md-5">
                    <button type="button" class="btn btn-secondary" id="ciklik-relay-search-btn">
                        <i class="material-icons">search</i> {l s='Search pickup points' mod='ciklik'}
                    </button>
                </div>
            </div>

            <div class="row" id="ciklik-relay-results-wrapper" style="display:none;">
                <div class="col-md-5">
                    <div class="list-group" id="ciklik-relay-results" style="max-height:300px;overflow-y:auto;"></div>
                </div>
                <div class="col-md-7">
                    <div id="ciklik-relay-map" style="height:300px;"></div>
                </div>
            </div>
            <hr>
        {/if}

        {if $ciklik_relay_known}
            <div class="form-group">
                <label for="ciklik-relay-known">{l s='Pickup points already used by this customer' mod='ciklik'}</label>
                <select id="ciklik-relay-known" class="form-control">
                    <option value="">{l s='-- Select a known pickup point --' mod='ciklik'}</option>
                    {foreach from=$ciklik_relay_known item=relay}
                        <option value="{$relay.relay_id|escape:'html':'UTF-8'}" data-relay="{$relay|json_encode|escape:'html':'UTF-8'}">
                            {$relay.relay_id|escape:'html':'UTF-8'}{if $relay.name} — {$relay.name|escape:'html':'UTF-8'}{/if}{if $relay.city} ({$relay.city|escape:'html':'UTF-8'}){/if}
                        </option>
                    {/foreach}
                </select>
            </div>
        {/if}

        <div class="row">
            <div class="form-group col-md-3">
                <label for="ciklik-relay-id">{l s='Pickup point ID' mod='ciklik'} *</label>
                <input type="text" id="ciklik-relay-id" class="form-control" value="">
            </div>
            <div class="form-group col-md-5">
                <label for="ciklik-relay-name">{l s='Name' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-name" class="form-control" value="">
            </div>
            <div class="form-group col-md-4">
                <label for="ciklik-relay-phone">{l s='Mobile phone (notifications)' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-phone" class="form-control" value="">
            </div>
        </div>
        <div class="row">
            <div class="form-group col-md-4">
                <label for="ciklik-relay-address1">{l s='Address' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-address1" class="form-control" value="">
            </div>
            <div class="form-group col-md-3">
                <label for="ciklik-relay-address2">{l s='Address (2)' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-address2" class="form-control" value="">
            </div>
            <div class="form-group col-md-2">
                <label for="ciklik-relay-zipcode">{l s='Zip code' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-zipcode" class="form-control" value="">
            </div>
            <div class="form-group col-md-2">
                <label for="ciklik-relay-city">{l s='City' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-city" class="form-control" value="">
            </div>
            <div class="form-group col-md-1">
                <label for="ciklik-relay-country">{l s='Country' mod='ciklik'}</label>
                <input type="text" id="ciklik-relay-country" class="form-control" value="" placeholder="FR" maxlength="2">
            </div>
        </div>
        <input type="hidden" id="ciklik-relay-name2" value="">
        <input type="hidden" id="ciklik-relay-product-code" value="">
        <input type="hidden" id="ciklik-relay-network" value="">
        <input type="hidden" id="ciklik-relay-working-day" value="">

        <button type="button" class="btn btn-primary" id="ciklik-relay-save">
            <i class="material-icons">save</i> {l s='Save pickup point' mod='ciklik'}
        </button>
        {if $ciklik_relay_has_override}
            <button type="button" class="btn btn-outline-secondary" id="ciklik-relay-reset">
                <i class="material-icons">restore</i> {l s='Back to automatic mode' mod='ciklik'}
            </button>
        {/if}
    </div>
</div>

<script type="text/javascript">
$(function () {
    var relayManageUrl = '{$manageActionUrl|escape:"javascript":"UTF-8"}';
    var relayAjaxToken = '{$manageAjaxToken|escape:"javascript":"UTF-8"}';
    var relayOrderId = {$ciklik_relay_order_id|default:0|intval};
    var relayCarrierModule = '{$ciklik_relay_module|escape:"javascript":"UTF-8"}';

    function relayShowError(msg) {
        $('.ciklik-relay-success').hide();
        $('.ciklik-relay-danger').text(msg).show();
    }

    function relayFillForm(relay) {
        $('#ciklik-relay-id').val(relay.relay_id || '');
        $('#ciklik-relay-name').val(relay.name || '');
        $('#ciklik-relay-name2').val(relay.name2 || '');
        $('#ciklik-relay-address1').val(relay.address1 || '');
        $('#ciklik-relay-address2').val(relay.address2 || '');
        $('#ciklik-relay-zipcode').val(relay.zipcode || '');
        $('#ciklik-relay-city').val(relay.city || '');
        $('#ciklik-relay-country').val(relay.country_iso || '');
        $('#ciklik-relay-product-code').val(relay.product_code || '');
        $('#ciklik-relay-network').val(relay.network || '');
        $('#ciklik-relay-working-day').val(relay.parcel_shop_working_day || '');
    }

    $('#ciklik-relay-known').on('change', function () {
        var raw = $(this).find('option:selected').attr('data-relay');
        if (raw) {
            relayFillForm(JSON.parse(raw));
        }
    });

    function relayPost(data, $btn) {
        $btn.attr('disabled', true);
        data.ajax = true;
        data.ajax_token = relayAjaxToken;
        data.id_order = relayOrderId;
        data.carrier_module = relayCarrierModule;

        $.ajax({ type: 'POST', url: relayManageUrl, dataType: 'json', data: data })
            .done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    relayShowError(resp && resp.message ? resp.message : 'Error');
                    $btn.attr('disabled', false);
                }
            })
            .fail(function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error';
                relayShowError(msg);
                $btn.attr('disabled', false);
            });
    }

    $('#ciklik-relay-save').on('click', function () {
        var relayId = $.trim($('#ciklik-relay-id').val());
        if (!relayId) {
            relayShowError('{l s="Pickup point ID is required" mod="ciklik" js=1}');
            return;
        }
        relayPost({
            action: 'saveRelayOverride',
            relay_id: relayId,
            relay_name: $('#ciklik-relay-name').val(),
            relay_name2: $('#ciklik-relay-name2').val(),
            relay_address1: $('#ciklik-relay-address1').val(),
            relay_address2: $('#ciklik-relay-address2').val(),
            relay_zipcode: $('#ciklik-relay-zipcode').val(),
            relay_city: $('#ciklik-relay-city').val(),
            relay_country_iso: $('#ciklik-relay-country').val(),
            relay_phone: $('#ciklik-relay-phone').val(),
            relay_product_code: $('#ciklik-relay-product-code').val(),
            relay_network: $('#ciklik-relay-network').val(),
            relay_parcel_shop_working_day: $('#ciklik-relay-working-day').val()
        }, $(this));
    });

    $('#ciklik-relay-reset').on('click', function () {
        relayPost({ action: 'resetRelayOverride' }, $(this));
    });

    {if $ciklik_relay_search_supported}
    var relayMap = null;
    var relayMarkers = [];

    function relaySelectResult(relay, $item) {
        relayFillForm(relay);
        $('#ciklik-relay-results .list-group-item').removeClass('active');
        if ($item) {
            $item.addClass('active');
        }
    }

    function relayRenderResults(results) {
        var $list = $('#ciklik-relay-results');
        $list.empty();
        $('#ciklik-relay-results-wrapper').show();

        results.forEach(function (relay) {
            var $item = $('<a href="#" class="list-group-item list-group-item-action"></a>')
                .text(relay.relay_id + ' — ' + (relay.name || '') + ' (' + (relay.zipcode || '') + ' ' + (relay.city || '') + ')')
                .on('click', function (e) {
                    e.preventDefault();
                    relaySelectResult(relay, $(this));
                });
            $list.append($item);
        });

        // Carte Leaflet : optionnelle, la liste reste fonctionnelle sans elle
        if (typeof L === 'undefined') {
            $('#ciklik-relay-map').hide();
            return;
        }

        if (!relayMap) {
            relayMap = L.map('ciklik-relay-map');
            L.tileLayer('https://tile.openstreetmap.org/{ldelim}z{rdelim}/{ldelim}x{rdelim}/{ldelim}y{rdelim}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(relayMap);
        }

        relayMarkers.forEach(function (marker) { relayMap.removeLayer(marker); });
        relayMarkers = [];

        var bounds = [];
        results.forEach(function (relay, index) {
            if (!relay.latitude || !relay.longitude) {
                return;
            }
            var marker = L.marker([relay.latitude, relay.longitude]).addTo(relayMap);
            // bindPopup interprète les chaînes comme du HTML : passer un nœud
            // texte pour neutraliser tout contenu venant de l'API transporteur
            var popupContent = document.createElement('div');
            popupContent.textContent = relay.name || relay.relay_id;
            marker.bindPopup(popupContent);
            marker.on('click', function () {
                relaySelectResult(relay, $('#ciklik-relay-results .list-group-item').eq(index));
            });
            relayMarkers.push(marker);
            bounds.push([relay.latitude, relay.longitude]);
        });

        if (bounds.length) {
            relayMap.fitBounds(bounds, { padding: [20, 20] });
        } else {
            // Aucun résultat géolocalisé : vue par défaut pour éviter une carte
            // sans centre (la liste reste le canal principal de sélection)
            relayMap.setView([46.6, 2.4], 5);
        }
        // Le conteneur vient d'être affiché : recalcul de la taille de la carte
        setTimeout(function () { relayMap.invalidateSize(); }, 100);
    }

    $('#ciklik-relay-search-btn').on('click', function () {
        var $btn = $(this);
        var zipcode = $.trim($('#ciklik-relay-search-zipcode').val());
        if (!zipcode) {
            relayShowError('{l s="Zip code is required" mod="ciklik" js=1}');
            return;
        }
        $btn.attr('disabled', true);
        $('.ciklik-relay-danger').hide();

        $.ajax({
            type: 'POST',
            url: relayManageUrl,
            dataType: 'json',
            data: {
                ajax: true,
                action: 'searchRelays',
                ajax_token: relayAjaxToken,
                id_order: relayOrderId,
                carrier_module: relayCarrierModule,
                zipcode: zipcode,
                city: $('#ciklik-relay-search-city').val(),
                country_iso: $('#ciklik-relay-search-country').val()
            }
        })
        .done(function (resp) {
            $btn.attr('disabled', false);
            if (resp && resp.success && resp.results && resp.results.length) {
                relayRenderResults(resp.results);
            } else {
                relayShowError('{l s="No pickup point found around this address" mod="ciklik" js=1}');
            }
        })
        .fail(function (xhr) {
            $btn.attr('disabled', false);
            var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error';
            relayShowError(msg);
        });
    });
    {/if}
});
</script>
