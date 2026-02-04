{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

 <!-- Modal -->
<div class="modal fade" id="upsellModal{$product->id_product|escape:'html':'UTF-8'}" tabindex="-1" role="dialog" aria-labelledby="upsellModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="upsellModalLabel">{l s='Add product to an existing subscription' mod='ciklik'}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
            <form id="upsellForm" method="POST" action="{$product.subcription_base_link|escape:'html':'UTF-8'}/" name="upsellForm">
                <div id="upsellFormContainer{$product->id_product|escape:'html':'UTF-8'}">

                        <div class="form-group">
                            <label for="subscription-select">{l s='Select subscription' mod='ciklik'}</label>
                            <select class="form-control" id="subscription-select" name="subscription_uuid" required>
                                {if $product.available_subscriptions|count > 0}
                                {foreach from=$product.available_subscriptions item=subscription}
                                <option value="{$subscription->uuid|escape:'html':'UTF-8'}" data-url="{$product.subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/addUpsell">{$subscription->display_content|escape:'html':'UTF-8'}</option>
                                {/foreach}
                                {else}
                                <option value="">{l s='No active subscription found' mod='ciklik'}</option>
                                {/if}
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantity">{l s='Quantity' mod='ciklik'}</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                        </div>
                        <input type="hidden" name="id_product" value="{$product.id_product|intval}">
                        {if isset($product.id_product_attribute)}
                        <input type="hidden" name="id_product_attribute" value="{$product.id_product_attribute|intval}">
                        {/if}
                        <input type="hidden" name="action" value="addUpsell">
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Cancel' mod='ciklik'}</button>
                    <button type="button" class="btn btn-primary" id="submitUpsell">{l s='Add to subscription' mod='ciklik'}</button>
                </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var ciklikUpsellMessages = {
    success: '{l s='The product has been successfully added to your subscription' mod='ciklik' js=1}'
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
    var submitBtn = this;
    submitBtn.disabled = true;

    // Collect form data
    var formData = new FormData();
    formData.append('subscription_uuid', document.getElementById('subscription-select').value);
    formData.append('quantity', document.getElementById('quantity').value);
    formData.append('id_product', '{$product.id_product|intval}');
    {if isset($product.id_product_attribute)}
        if(window.ciklik_selected_option){
            formData.append('id_product_attribute', window.ciklik_selected_option);
        }else{
            formData.append('id_product_attribute', '{$product.id_product_attribute|intval}');
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
        var modalBody = document.querySelector('#upsellModal{$product.id_product|intval} .modal-body');

        // Supprime les alertes précédentes
        var oldAlerts = modalBody.querySelectorAll('.alert');
        oldAlerts.forEach(function(alert) { alert.remove(); });

        var alertDiv = document.createElement('div');

        if (data.success) {
            alertDiv.className = 'alert alert-success';
            alertDiv.textContent = data.message || ciklikUpsellMessages.success;
            modalBody.appendChild(alertDiv);

            // Ferme la modale après 2 secondes
            setTimeout(function() {
                var modal = document.getElementById('upsellModal{$product.id_product|intval}');
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                } else if (typeof $ !== 'undefined' && $.fn.modal) {
                    $(modal).modal('hide');
                }
            }, 2000);
        } else {
            alertDiv.className = 'alert alert-danger';
            alertDiv.textContent = data.message || '{l s='An error occurred.' mod='ciklik' js=1}';
            modalBody.appendChild(alertDiv);
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        var modalBody = document.querySelector('#upsellModal{$product.id_product|intval} .modal-body');
        var alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger';
        alertDiv.textContent = '{l s='An error occurred.' mod='ciklik' js=1}';
        modalBody.appendChild(alertDiv);
        submitBtn.disabled = false;
    });
});
</script>