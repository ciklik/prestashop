{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div>
    <a class="text-muted" data-toggle="collapse" href="#upsellList{$subscription->uuid|escape:'html':'UTF-8'}" role="button" aria-expanded="false" aria-controls="upsellList{$subscription->uuid|escape:'html':'UTF-8'}">
        <i class="material-icons" style="font-size: 15px;">add</i> <small>{l s='Non-recurring additional products' mod='ciklik'}</small>
    </a>
    <div class="collapse" id="upsellList{$subscription->uuid|escape:'html':'UTF-8'}">
        <table class="table table-sm mt-2">
            <thead>
                <tr>
                    <th><small>{l s='Products' mod='ciklik'}</small></th>
                    <th><small>{l s='Quantity' mod='ciklik'}</small></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$subscription->upsells item=upsell}
                    <tr id="upsell-row-{$upsell.product_id|intval}-{$upsell.product_attribute_id|intval}">
                        <td><small>{$upsell.display_name|escape:'html':'UTF-8'}</small></td>
                        <td><small>{$upsell.quantity|intval}</small></td>
                        <td>
                            <button class="btn btn-link delete-upsell"
                                    data-subscription-uuid="{$subscription->uuid|escape:'html':'UTF-8'}"
                                    data-product-id="{$upsell.product_id|intval}"
                                    data-product-attribute-id="{$upsell.product_attribute_id|intval}">
                                <i class="material-icons">delete</i>
                            </button>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    <script>
        document.querySelectorAll('.delete-upsell').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const uuid = this.dataset.subscriptionUuid;
                const productId = this.dataset.productId;
                const attributeId = this.dataset.productAttributeId;

                const formData = new FormData();
                formData.append('id_product', productId);
                formData.append('id_product_attribute', attributeId);
                formData.append('quantity', 0);
                formData.append('action', 'addUpsell');
                formData.append('uuid', uuid);

                fetch('{$subcription_base_link|escape:'javascript':'UTF-8'}/' + uuid + '/addUpsell', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('upsell-row-' + productId + '-' + attributeId).remove();
                    }
                })
                .catch(error => console.error('Error:', error));
            });
        });
    </script>
</div>
