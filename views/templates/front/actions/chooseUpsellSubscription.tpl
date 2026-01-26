{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

 <!-- Modal -->
<div class="modal fade" id="upsellModal{$product->id_product}" tabindex="-1" role="dialog" aria-labelledby="upsellModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="upsellModalLabel">{l s='Ajouter le produit à un abonnement existant' mod='ciklik'}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
            <form id="upsellForm" method="POST" action="{$product.subcription_base_link}/" name="upsellForm">
                <div id="upsellFormContainer{$product->id_product}">
                    
                        <div class="form-group">
                            <label for="subscription-select">{l s='Sélectionner l\'abonnement' mod='ciklik'}</label>
                            <select class="form-control" id="subscription-select" name="subscription_uuid" required>
                                {if $product.available_subscriptions|count > 0}
                                {foreach from=$product.available_subscriptions item=subscription}
                                <option value="{$subscription->uuid}" data-url="{$product.subcription_base_link}/{$subscription->uuid}/addUpsell">{$subscription->display_content}</option>
                                {/foreach}
                                {else}
                                <option value="">{l s='Aucun abonnement actif trouvé' mod='ciklik'}</option>
                                {/if}
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantity">{l s='Quantité' mod='ciklik'}</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                        </div>
                        <input type="hidden" name="id_product" value="{$product.id_product}">
                        {if isset($product.id_product_attribute)}
                        <input type="hidden" name="id_product_attribute" value="{$product.id_product_attribute}">
                        {/if}
                        <input type="hidden" name="action" value="addUpsell">
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Annuler' mod='ciklik'}</button>
                    <button type="button" class="btn btn-primary" id="submitUpsell">{l s='Ajouter à l\'abonnnement' mod='ciklik'}</button>
                </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var ciklikUpsellMessages = {
    success: '{l s='Le produit a bien été ajouté à votre abonnement' mod='ciklik' js=1}'
};

document.getElementById('subscription-select').addEventListener('change', function() {
    var selectedOption = this.options[this.selectedIndex];
    window.selectedUrl = selectedOption.dataset.url;
});

// Set initial URL
window.addEventListener('load', function() {
    var select = document.getElementById('subscription-select');
    var selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.dataset.url) {
        window.selectedUrl = selectedOption.dataset.url;
    }
});

document.addEventListener('DOMContentLoaded', function() {
  if (typeof prestashop !== 'undefined') {
    prestashop.on("updatedProduct", function(event) {
      if(event.id_product_attribute){
        window.ciklik_selected_option = event.id_product_attribute;
      }
    });
  }
});

document.getElementById('submitUpsell').addEventListener('click', function() {
    // Collect form data
    var formData = new FormData();
    formData.append('subscription_uuid', document.getElementById('subscription-select').value);
    formData.append('quantity', document.getElementById('quantity').value);
    formData.append('id_product', '{$product.id_product}');
    {if isset($product.id_product_attribute)}
        if(window.ciklik_selected_option){
            console.log(window.ciklik_selected_option);
            formData.append('id_product_attribute', window.ciklik_selected_option);
        }else{
            console.log('no selected option');
            formData.append('id_product_attribute', '{$product.id_product_attribute}');
        }
    {/if}
    formData.append('action', 'addUpsell');

    // Send AJAX request
    fetch(window.selectedUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log(data);
        // Show success message in modal
        var modalBody = document.querySelector('#upsellModal{$product.id_product} .modal-body');
        var successAlert = document.createElement('div');
        successAlert.className = 'alert alert-success';
        successAlert.innerHTML = ciklikUpsellMessages.success;
        modalBody.appendChild(successAlert);

        // Remove the alert after 3 seconds
        setTimeout(function() {
            successAlert.remove();
        }, 5000);


        // Handle success
        //$('#upsellModal{$product->id_product}').modal('hide');
    })
    .catch(error => {
        console.error('Error:', error);
    });
});
</script>