{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

{if !empty($subscription->contents) && $subscription->active}
<div class="subscription-products mt-1">
    <a class="text-muted" data-toggle="collapse" data-bs-toggle="collapse" href="#productsList{$subscription->uuid|escape:'html':'UTF-8'}" role="button" aria-expanded="false" aria-controls="productsList{$subscription->uuid|escape:'html':'UTF-8'}">
        <i class="material-icons" style="font-size: 15px;">add</i> <small>{l s='Subscription products' mod='ciklik'}</small>
    </a>
    <div class="collapse" id="productsList{$subscription->uuid|escape:'html':'UTF-8'}">
        <table class="table table-sm mt-2">
            <thead>
                <tr>
                    <th><small>{l s='Product' mod='ciklik'}</small></th>
                    <th><small>{l s='Quantity' mod='ciklik'}</small></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$subscription->contents item=content}
                    {assign var="product_info" value=":"|explode:$content.external_id}
                    {assign var="product_id" value=$product_info[0]}
                    {assign var="product_attribute_id" value=0}
                    {if count($product_info) > 1}
                        {assign var="product_attribute_id" value=$product_info[1]}
                    {/if}
                    <tr id="product-row-{$subscription->uuid|escape:'html':'UTF-8'}-{$content@index}">
                        <td>
                            <small>
                            {if $product_attribute_id|intval > 0 && Product::getProductName($product_id|intval, $product_attribute_id|intval)}
                                {Product::getProductName($product_id|intval, $product_attribute_id|intval)|escape:'html':'UTF-8'}
                            {else}
                                {Product::getProductName($product_id|intval)|escape:'html':'UTF-8'}
                            {/if}
                            </small>
                        </td>
                        <td>
                            {if !$content.is_customization}
                                <div style="display: inline-flex; align-items: center;">
                                    <button class="btn btn-link ciklik-qty-decrease"
                                            data-external-id="{$content.external_id|escape:'html':'UTF-8'}"
                                            data-row-index="{$content@index}"
                                            {if $content.quantity|intval <= 1}disabled{/if}
                                            style="padding: 0 4px;">
                                        <i class="material-icons" style="font-size: 18px;">remove</i>
                                    </button>
                                    <span class="ciklik-qty-value" id="qty-{$subscription->uuid|escape:'html':'UTF-8'}-{$content@index}" style="min-width: 24px; display: inline-block; text-align: center;">
                                        {$content.quantity|intval}
                                    </span>
                                    <button class="btn btn-link ciklik-qty-increase"
                                            data-external-id="{$content.external_id|escape:'html':'UTF-8'}"
                                            data-row-index="{$content@index}"
                                            style="padding: 0 4px;">
                                        <i class="material-icons" style="font-size: 18px;">add</i>
                                    </button>
                                </div>
                            {else}
                                <small>{$content.quantity|intval}</small>
                            {/if}
                        </td>
                        <td>
                            {if !$content.is_customization && $subscription->contents|count > 1}
                                <button class="btn btn-link ciklik-remove-product"
                                        data-external-id="{$content.external_id|escape:'html':'UTF-8'}"
                                        data-row-index="{$content@index}"
                                        style="padding: 0;">
                                    <i class="material-icons" style="font-size: 18px; color: #dc3545;">delete</i>
                                </button>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>
<script>
(function() {
    var container = document.getElementById('productsList{$subscription->uuid|escape:'javascript':'UTF-8'}');
    if (!container) return;

    var baseUrl = '{$subcription_base_link|escape:'javascript':'UTF-8'}';
    var uuid = '{$subscription->uuid|escape:'javascript':'UTF-8'}';

    function sendProductAction(action, data, onSuccess, onError) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('uuid', uuid);
        formData.append('token', '{$token|escape:'javascript':'UTF-8'}');
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        fetch(baseUrl + '/' + uuid + '/' + action, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(result) {
            if (result.success) {
                if (onSuccess) onSuccess(result);
            } else {
                if (onError) onError(result);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            if (onError) onError({ message: '{l s='An error occurred.' mod='ciklik' js=1}' });
        });
    }

    // Gestion des boutons +/-
    container.querySelectorAll('.ciklik-qty-increase, .ciklik-qty-decrease').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var externalId = this.dataset.externalId;
            var rowIndex = this.dataset.rowIndex;
            var qtySpan = document.getElementById('qty-' + uuid + '-' + rowIndex);
            var currentQty = parseInt(qtySpan.textContent.trim(), 10);
            var isIncrease = this.classList.contains('ciklik-qty-increase');
            var newQty = isIncrease ? currentQty + 1 : currentQty - 1;

            if (newQty < 1) return;

            this.disabled = true;
            var self = this;

            sendProductAction('updateProductQuantity', {
                external_id: externalId,
                quantity: newQty
            }, function() {
                qtySpan.textContent = newQty;
                self.disabled = false;
                var decreaseBtn = container.querySelector('.ciklik-qty-decrease[data-row-index="' + rowIndex + '"]');
                if (decreaseBtn) decreaseBtn.disabled = (newQty <= 1);
            }, function(result) {
                self.disabled = false;
                alert(result.message || '{l s='An error occurred.' mod='ciklik' js=1}');
            });
        });
    });

    // Gestion de la suppression de produit
    container.querySelectorAll('.ciklik-remove-product').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('{l s='Are you sure you want to remove this product from your subscription?' mod='ciklik' js=1}')) return;

            var externalId = this.dataset.externalId;
            var rowIndex = this.dataset.rowIndex;
            this.disabled = true;
            var self = this;

            sendProductAction('removeProduct', {
                external_id: externalId
            }, function() {
                var row = document.getElementById('product-row-' + uuid + '-' + rowIndex);
                if (row) row.remove();

                // Si un seul produit restant, masquer les boutons de suppression
                var remainingRows = container.querySelectorAll('tbody tr');
                if (remainingRows.length <= 1) {
                    container.querySelectorAll('.ciklik-remove-product').forEach(function(b) {
                        b.style.display = 'none';
                    });
                }
            }, function(result) {
                self.disabled = false;
                alert(result.message || '{l s='An error occurred.' mod='ciklik' js=1}');
            });
        });
    });
})();
</script>
{/if}
