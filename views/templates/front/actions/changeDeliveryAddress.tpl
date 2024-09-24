{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 <small><u data-toggle="modal" data-target="#changeDeliveryAddress"{$subscription->uuid}>
        Modifier
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="changeDeliveryAddress"{$subscription->uuid}">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">Changer l'adresse de la prochaine livraison</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <!-- Corps de la boîte modale -->
            <div class="modal-body">
                <p>Sélectionnez l’adresse à laquelle sera expédiée votre prochaine commande.</p>
                <p>
                    Si vous souhaitez créer une nouvelle adresse de livraison, rendez-vous dans votre espace Mon compte, rubrique Mes adresses.
                </p>

                <form id="changeAddressForm-{$subscription->uuid}" action="{$subcription_base_link}/{$subscription->uuid}/updateaddress" method="POST">

                    <label for="changeAddressForm">Adresse :</label>
                    <select name="changeAddressForm" id="changeAddressForm">
                        {foreach from=$addresses item=$address}
                            <option value="{$address['id_address']}">
                                {$address['address1']} <br>
                                {$address['city']}
                            </option>
                        {/foreach}

                    </select>
                    <button type="submit">Modifier</button>
                </form>
            </div>

            <!-- Pied de la boîte modale -->
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Annuler</button>
            </div>

        </div>
    </div>
</div>
