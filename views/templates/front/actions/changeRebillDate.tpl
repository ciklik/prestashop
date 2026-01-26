{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 <small><u data-toggle="modal" data-target="#updateNextBilling{$subscription->uuid}" data-bs-toggle="modal" data-bs-target="#updateNextBilling{$subscription->uuid}">
        {l s='Modifier la date' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="updateNextBilling{$subscription->uuid}">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">{l s='Modifier la date de mon prochain paiement' mod='ciklik'}</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

            <!-- Corps de la boîte modale -->
            <div class="modal-body">
                <p>{l s='Vous pouvez recevoir plus tôt votre prochaine commande en avançant la date de renouvellement de votre abonnement ou la reporter plus tard si besoin.' mod='ciklik'}</p>
                <p>{l s='La date de prochain paiement sélectionnée sera la nouvelle date anniversaire pour votre abonnement.' mod='ciklik'}</p>
                <form id="newDateForm-{$subscription->uuid}" action="{$subcription_base_link}/{$subscription->uuid}/newdate" method="POST">
                    <label for="next_billing">{l s='Nouvelle date :' mod='ciklik'}</label>
                    <input type="date" name="next_billing" id="next_billing" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           max="<?php echo date('Y-m-d', strtotime('+6 months')); ?>" required>
                    <button type="submit">{l s='Modifier' mod='ciklik'}</button>
                </form>
            </div>

            <!-- Pied de la boîte modale -->
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">{l s='Annuler' mod='ciklik'}</button>
            </div>

        </div>
    </div>
</div>
