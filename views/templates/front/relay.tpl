{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Page de choix du point relais d'un abonnement (espace client).
 * Variables : relay_* (voir CiklikSubscriptionModuleFrontController::relay), token.
 * Sans JavaScript : la recherche et chaque choix sont des formulaires POST,
 * portant le jeton PrestaShop et le jeton propre au module (relay_token).
 * Pas de saisie de numéro : le client choisit parmi les relais trouvés
 * autour d'une adresse ou ceux qu'il a déjà utilisés, chaque encart portant
 * son propre bouton (relay_choice + jeton signé relay_data[...]).
 * Mise en page : classes Bootstrap du thème + views/css/relay.css.
 *}

{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='Change my pickup point' mod='ciklik'}
{/block}

{block name='page_content'}
<div class="ciklik-relay-page">

    {* 1. Point relais actuel *}
    <section class="card ciklik-relay-section" aria-labelledby="ciklik-relay-current-title">
        <header class="ciklik-relay-section__header">
            <h2 class="ciklik-relay-section__title" id="ciklik-relay-current-title">{l s='Your current pickup point' mod='ciklik'}</h2>
        </header>
        <div class="ciklik-relay-section__body">
            <dl class="ciklik-relay-facts">
                <dt>{l s='Subscription:' mod='ciklik'}</dt>
                <dd>{$relay_subscription->display_content|escape:'html':'UTF-8'}</dd>
                <dt>{l s='Carrier:' mod='ciklik'}</dt>
                <dd>{$relay_carrier_name|escape:'html':'UTF-8'}</dd>
            </dl>

            {if $relay_current}
                <div class="ciklik-relay-item ciklik-relay-item--current">
                    <div class="ciklik-relay-item__head">
                        <p class="ciklik-relay-item__name">
                            {if $relay_current.label}{$relay_current.label|escape:'html':'UTF-8'}{else}{$relay_current.relay_id|escape:'html':'UTF-8'}{/if}
                        </p>
                        <span class="badge badge-secondary text-bg-secondary ciklik-relay-item__badge">{l s='Current' mod='ciklik'}</span>
                    </div>
                    <p class="ciklik-relay-item__meta">
                        <span>{l s='Ref.' mod='ciklik'} {$relay_current.relay_id|escape:'html':'UTF-8'}</span>
                        <span>
                            {if $relay_current.source === 'override'}
                                {l s='Chosen for your next deliveries.' mod='ciklik'}
                            {else}
                                {l s='Taken from your last order.' mod='ciklik'}
                            {/if}
                        </span>
                    </p>
                </div>
            {else}
                <p class="ciklik-relay-hint">{l s='No pickup point known yet.' mod='ciklik'}</p>
            {/if}

            <p class="ciklik-relay-hint">{l s='The new pickup point will be used for your next deliveries only, for all your subscriptions shipped with this carrier. Orders already placed are not changed.' mod='ciklik'}</p>
        </div>
    </section>

    {* 2. Recherche par adresse *}
    {if $relay_search_supported}
        <section class="card ciklik-relay-section" aria-labelledby="ciklik-relay-search-title">
            <header class="ciklik-relay-section__header">
                <h2 class="ciklik-relay-section__title" id="ciklik-relay-search-title">{l s='Find another pickup point' mod='ciklik'}</h2>
            </header>
            <div class="ciklik-relay-section__body">
                <form method="post" action="{$relay_search_url|escape:'html':'UTF-8'}" class="ciklik-relay-search">
                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                    <input type="hidden" name="relay_token" value="{$relay_form_token|escape:'html':'UTF-8'}">
                    <input type="hidden" name="relay_search" value="1">
                    <div class="form-group">
                        <label class="form-label" for="ciklik-relay-zipcode">{l s='Zip code' mod='ciklik'}</label>
                        <input type="text" class="form-control" name="zipcode" id="ciklik-relay-zipcode" maxlength="12" required autocomplete="postal-code" value="{$relay_prefill.zipcode|escape:'html':'UTF-8'}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ciklik-relay-city">{l s='City' mod='ciklik'}</label>
                        <input type="text" class="form-control" name="city" id="ciklik-relay-city" maxlength="64" autocomplete="address-level2" value="{$relay_prefill.city|escape:'html':'UTF-8'}">
                    </div>
                    {* Pays de l'adresse de livraison, imposé par le serveur : affiché, jamais soumis *}
                    <div class="form-group">
                        <label class="form-label" for="ciklik-relay-country">{l s='Country' mod='ciklik'}</label>
                        <input type="text" class="form-control" id="ciklik-relay-country" readonly value="{$relay_prefill.country_iso|escape:'html':'UTF-8'}">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">{l s='Search' mod='ciklik'}</button>
                    </div>
                </form>

                {if $relay_search_error}
                    <div class="alert alert-warning" role="alert">{$relay_search_error|escape:'html':'UTF-8'}</div>
                {elseif !$relay_results}
                    <p class="ciklik-relay-hint">{l s='Enter a zip code and a city to list the pickup points nearby.' mod='ciklik'}</p>
                {/if}
            </div>
        </section>
    {/if}

    {* 3. Résultats de la recherche *}
    {if $relay_results}
        <section class="card ciklik-relay-section" aria-labelledby="ciklik-relay-results-title">
            <header class="ciklik-relay-section__header">
                <h2 class="ciklik-relay-section__title" id="ciklik-relay-results-title">{l s='Pickup points found' mod='ciklik'}</h2>
                <span class="ciklik-relay-section__count">{$relay_results|count}</span>
            </header>
            <div class="ciklik-relay-section__body">
                <ul class="ciklik-relay-list">
                    {foreach from=$relay_results item=relay}
                        {assign var='is_current' value=($relay_current && $relay_current.relay_id == $relay.relay_id)}
                        <li class="ciklik-relay-item{if $is_current} ciklik-relay-item--current{/if}">
                            <div class="ciklik-relay-item__head">
                                <p class="ciklik-relay-item__name">{$relay.name|escape:'html':'UTF-8'}</p>
                                {if $is_current}
                                    <span class="badge badge-secondary text-bg-secondary ciklik-relay-item__badge">{l s='Current' mod='ciklik'}</span>
                                {/if}
                            </div>
                            <p class="ciklik-relay-item__address">
                                {$relay.address1|escape:'html':'UTF-8'}{if $relay.address2}<br>{$relay.address2|escape:'html':'UTF-8'}{/if}
                                <br>{$relay.zipcode|escape:'html':'UTF-8'} {$relay.city|escape:'html':'UTF-8'}
                            </p>
                            <p class="ciklik-relay-item__meta">
                                {if !empty($relay.distance_label)}<span class="ciklik-relay-item__distance">{$relay.distance_label|escape:'html':'UTF-8'}</span>{/if}
                                <span>{l s='Ref.' mod='ciklik'} {$relay.relay_id|escape:'html':'UTF-8'}</span>
                            </p>
                            <form method="post" action="{$relay_save_url|escape:'html':'UTF-8'}" class="ciklik-relay-item__action">
                                <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_token" value="{$relay_form_token|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_choice" value="{$relay.relay_id|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_data[{$relay.relay_id|escape:'html':'UTF-8'}]" value="{$relay.token|escape:'html':'UTF-8'}">
                                <button type="submit" class="btn btn-primary btn-sm" aria-label="{l s='Choose this pickup point:' mod='ciklik'} {$relay.name|escape:'html':'UTF-8'}">{l s='Choose this pickup point' mod='ciklik'}</button>
                            </form>
                        </li>
                    {/foreach}
                </ul>
            </div>
        </section>
    {/if}

    {* 4. Relais déjà utilisés *}
    {if $relay_known}
        <section class="card ciklik-relay-section" aria-labelledby="ciklik-relay-known-title">
            <header class="ciklik-relay-section__header">
                <h2 class="ciklik-relay-section__title" id="ciklik-relay-known-title">{l s='Your previous pickup points' mod='ciklik'}</h2>
                <span class="ciklik-relay-section__count">{$relay_known|count}</span>
            </header>
            <div class="ciklik-relay-section__body">
                <ul class="ciklik-relay-list ciklik-relay-list--compact">
                    {foreach from=$relay_known item=relay}
                        {assign var='is_current' value=($relay_current && $relay_current.relay_id == $relay.relay_id)}
                        <li class="ciklik-relay-item{if $is_current} ciklik-relay-item--current{/if}">
                            <div class="ciklik-relay-item__head">
                                <p class="ciklik-relay-item__name">{$relay.name|escape:'html':'UTF-8'}</p>
                                {if $is_current}
                                    <span class="badge badge-secondary text-bg-secondary ciklik-relay-item__badge">{l s='Current' mod='ciklik'}</span>
                                {/if}
                            </div>
                            {if $relay.address1 || $relay.zipcode || $relay.city}
                                <p class="ciklik-relay-item__address">
                                    {if $relay.address1}{$relay.address1|escape:'html':'UTF-8'}{/if}{if $relay.address2}<br>{$relay.address2|escape:'html':'UTF-8'}{/if}
                                    {if $relay.zipcode || $relay.city}<br>{$relay.zipcode|escape:'html':'UTF-8'} {$relay.city|escape:'html':'UTF-8'}{/if}
                                </p>
                            {/if}
                            <p class="ciklik-relay-item__meta">
                                <span>{l s='Ref.' mod='ciklik'} {$relay.relay_id|escape:'html':'UTF-8'}</span>
                            </p>
                            <form method="post" action="{$relay_save_url|escape:'html':'UTF-8'}" class="ciklik-relay-item__action">
                                <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_token" value="{$relay_form_token|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_choice" value="{$relay.relay_id|escape:'html':'UTF-8'}">
                                <input type="hidden" name="relay_data[{$relay.relay_id|escape:'html':'UTF-8'}]" value="{$relay.token|escape:'html':'UTF-8'}">
                                <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="{l s='Choose this pickup point:' mod='ciklik'} {$relay.name|escape:'html':'UTF-8'}">{l s='Choose this pickup point' mod='ciklik'}</button>
                            </form>
                        </li>
                    {/foreach}
                </ul>
            </div>
        </section>
    {/if}

    {* 5. Retour *}
    <div class="ciklik-relay-footer">
        <a href="{$relay_account_url|escape:'html':'UTF-8'}" class="btn btn-secondary">{l s='Back to my subscriptions' mod='ciklik'}</a>
    </div>

</div>
{/block}
