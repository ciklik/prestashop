{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 <small><u data-toggle="modal" data-target="#updateNextBilling{$subscription->uuid|escape:'html':'UTF-8'}" data-bs-toggle="modal" data-bs-target="#updateNextBilling{$subscription->uuid|escape:'html':'UTF-8'}">
        {l s='Change date' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="updateNextBilling{$subscription->uuid|escape:'html':'UTF-8'}">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">{l s='Change my next payment date' mod='ciklik'}</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

            <!-- Corps de la boîte modale -->
            <div class="modal-body">
                <p>{l s='You can receive your next order sooner by advancing your subscription renewal date or postpone it later if needed.' mod='ciklik'}</p>
                <p>{l s='The selected next payment date will be the new anniversary date for your subscription.' mod='ciklik'}</p>
                <form id="newDateForm-{$subscription->uuid|escape:'html':'UTF-8'}" action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/newdate" method="POST">
                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                    <label for="next_billing">{l s='New date:' mod='ciklik'}</label>
                    <input type="date" name="next_billing" id="next_billing" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           max="<?php echo date('Y-m-d', strtotime('+6 months')); ?>" required>
                    <button type="submit">{l s='Update' mod='ciklik'}</button>
                </form>
            </div>

            <!-- Pied de la boîte modale -->
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">{l s='Cancel' mod='ciklik'}</button>
            </div>

        </div>
    </div>
</div>
