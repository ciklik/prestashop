{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
 <small><u data-toggle="modal" data-target="#updateInterval{$subscription->uuid}" data-bs-toggle="modal" data-bs-target="#updateInterval{$subscription->uuid}">
        {l s='Modifier la fréquence' mod='ciklik'}
    </u></small>

<!-- La boîte modale -->
<div class="modal fade" id="updateInterval{$subscription->uuid}">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- En-tête de la boîte modale -->
            <div class="modal-header">
                <h4 class="modal-title">Modifier la fréquence de mon abonnement</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
            </div>

            <!-- Corps de la boîte modale -->
            <div class="modal-body">
                <p>Vous pouvez sélectionner une nouvelle fréquence qui s’appliquera pour tous les produits de votre abonnement si celle-ci est disponible.</p>
                <p>La date de votre prochaine commande ne sera pas modifiée, le changement de fréquence s'appliquera à compter de la commande suivante.</p>
                <form id="newIntervalForm-{$subscription->uuid}" action="{$subcription_base_link}/{$subscription->uuid}/contents" method="POST">
                <label for="interval">Choisir</label>
                {* {dump($subscription->contents)} *}
                <select name="product_combination" id="product_combination" required>
                    {if !empty($subscription->contents)}
                        {assign var=content value=$subscription->contents[0]}
                        {if !empty($content.other_combinations)}
                            {foreach from=$content.other_combinations item=combination}
                                <option value="{$combination.id_product_attribute|escape:'html':'UTF-8'}">
                                    {$combination.display_name|escape:'html':'UTF-8'}
                                </option>
                            {/foreach}
                        {/if}
                    {else}
                        <option value="">{l s='No other combinations available' mod='ciklik'}</option>
                    {/if}
                </select>
                    <button type="submit">{l s='Modifier la fréquence' mod='ciklik'}</button>
                </form>
            </div>

            <!-- Pied de la boîte modale -->
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">Annuler</button>
            </div>

        </div>
    </div>
</div>
