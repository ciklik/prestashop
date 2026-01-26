{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
<small><u data-toggle="modal" data-target="#changeDeliveryAddress{$subscription->uuid}" data-bs-toggle="modal" data-bs-target="#changeDeliveryAddress{$subscription->uuid}" data-bs-toggle="modal">
        {l s='Changer l\'adresse' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="changeDeliveryAddress{$subscription->uuid}">
<div class="modal-dialog">
    <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">{l s='Changer l\'adresse de la prochaine livraison' mod='ciklik'}</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

        <!-- Corps de la boîte modale -->
        <div class="modal-body">
            <p>{l s='Sélectionnez l\'adresse à laquelle sera expédiée votre prochaine commande.' mod='ciklik'}</p>
            <p>
                {l s='Si vous souhaitez créer une nouvelle adresse de livraison, rendez-vous dans votre espace Mon compte, rubrique Mes adresses.' mod='ciklik'}
            </p>

            <form id="changeAddressForm-{$subscription->uuid}" action="{$subcription_base_link}/{$subscription->uuid}/updateaddress" method="POST">

                <label for="changeAddressForm">{l s='Adresse :' mod='ciklik'}</label>
                <select name="changeAddressForm" id="changeAddressForm">
                    {foreach from=$addresses item=$address}
                        <option value="{$address['id_address']}">
                            {$address['address1']} <br>
                            {$address['city']}
                        </option>
                    {/foreach}

                </select>
                <button type="submit" class="btn btn-primary">{l s='Modifier' mod='ciklik'}</button>
            </form>
        </div>

        <!-- Pied de la boîte modale -->
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal" data-bs-dismiss="modal">{l s='Annuler' mod='ciklik'}</button>
        </div>

    </div>
</div>
</div>
