{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div>
    <a class="text-muted" data-toggle="collapse" href="#upsellList{$subscription->uuid}" role="button" aria-expanded="false" aria-controls="upsellList{$subscription->uuid}">
        <i class="material-icons" style="font-size: 15px;">add</i> <small>{l s='Produits additionnels non récurrents' mod='ciklik'}</small>
    </a>
    <div class="collapse" id="upsellList{$subscription->uuid}">
        <table class="table table-sm mt-2">
            <thead>
                <tr>
                    <th><small>{l s='Produits' mod='ciklik'}</small></th>
                    <th><small>{l s='Quantité' mod='ciklik'}</small></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$subscription->upsells item=upsell}
                    <tr id="upsell-row-{$upsell.product_id}-{$upsell.product_attribute_id}">
                        <td><small>{$upsell.display_name}</small></td>
                        <td><small>{$upsell.quantity}</small></td>
                        <td>
                            <button class="btn btn-link delete-upsell" 
                                    data-subscription-uuid="{$subscription->uuid}"
                                    data-product-id="{$upsell.product_id}"
                                    data-product-attribute-id="{$upsell.product_attribute_id}">
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

                fetch('{$subcription_base_link}/' + uuid + '/addUpsell', {
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