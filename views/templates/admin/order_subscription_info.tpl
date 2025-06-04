{*
* @author    Ciklik SAS <support@ciklik.co>
* @copyright Since 2017 Metrogeek SAS
* @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
*}

<div class="card">
    <h3 class="card-header">
        <img src="{$moduleLogoSrc}" alt="{$moduleDisplayName}" width="20" height="20"> {l s='Informations d\'abonnement' mod='ciklik'}
    </h3>
    <div class="card-body">
        {if $subscription_items}
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{l s='Produit' mod='ciklik'}</th>
                            <th>{l s='Fréquence' mod='ciklik'}</th>
                            <th>{l s='Quantité' mod='ciklik'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$subscription_items item=item}
                            <tr>
                                <td>{$item.name}</td>
                                <td>
                                    {if $item.frequency}
                                        {$item.frequency}
                                        {if $item.discount_percent > 0}
                                            <span class="badge badge-success">-{$item.discount_percent}%</span>
                                        {/if}
                                        {if $item.discount_price} <span class="fw-bold"> -{$item.discount_price} €</span>{/if}
                                    {else}
                                        {l s='Achat unique' mod='ciklik'}
                                    {/if}
                                </td>
                                <td>{$item.quantity}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="text-muted">{l s='Aucun produit en abonnement dans cette commande.' mod='ciklik'}</p>
        {/if}

        {if $subscription}
         
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{l s='Statut' mod='ciklik'}:</strong>
                    <span class="badge {if $subscription->active}badge-success{else}badge-danger{/if}">
                        {if $subscription->active}{l s='Actif' mod='ciklik'}{else}{l s='Inactif' mod='ciklik'}{/if}
                    </span>
                </div>
                <div class="col-md-6">
                    <strong>{l s='Prochain paiement' mod='ciklik'}:</strong>
                    {$subscription->next_billing->format('d/m/Y')}
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{l s='UUID' mod='ciklik'}:</strong>
                    <code>{$subscription->uuid}</code>
                </div>
                <div class="col-md-6">
                    <strong>{l s='Date de fin' mod='ciklik'}:</strong>
                    {if $subscription->end_date}
                        {$subscription->end_date->format('d/m/Y')}
                    {else}
                        {l s='Non définie' mod='ciklik'}
                    {/if}
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Lien Ciklik' mod='ciklik'}:</strong>
                    <div class="mt-2">
                        <a href="{$ciklik_order_url}" target="_blank" class="btn btn-link">
                            <i class="material-icons">open_in_new</i> {l s='Voir la commande sur Ciklik' mod='ciklik'}
                        </a>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Adresse de livraison' mod='ciklik'}:</strong>
                    <address class="mt-2">
                        {$subscription->address->first_name} {$subscription->address->last_name}<br>
                        {$subscription->address->address}<br>
                        {if $subscription->address->address1}{$subscription->address->address1}<br>{/if}
                        {$subscription->address->postcode} {$subscription->address->city}<br>
                        {$subscription->address->country}
                    </address>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>{l s='Empreinte' mod='ciklik'}:</strong>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm">
                            <tbody>
                                <tr>
                                    <th>{l s='Identifiant client' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_customer}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Adresse de livraison' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_address_delivery}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Adresse de facturation' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_address_invoice}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Langue' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_lang}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Devise' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_currency}</td>
                                </tr>
                                <tr>
                                    <th>{l s='Transporteur' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->id_carrier_reference}</td>
                                </tr>
                                {if $subscription->external_fingerprint->frequency_id}
                                <tr>
                                    <th>{l s='Fréquence' mod='ciklik'}</th>
                                    <td>{$subscription->external_fingerprint->frequency_id}</td>
                                </tr>
                                {/if}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {if $subscription->upsells}
                <h4 class="mt-4">{l s='Produits additionnels' mod='ciklik'}</h4>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{l s='Produit' mod='ciklik'}</th>
                                <th>{l s='Quantité' mod='ciklik'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$subscription->upsells item=upsell}
                                <tr>
                                    <td>{$upsell->name}</td>
                                    <td>{$upsell->quantity}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {/if}
        {/if}
    </div>
</div> 