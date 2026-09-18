{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}
{* Bouton de fermeture d'une modale : Bootstrap 5 (Hummingbird) ou Bootstrap 4 (thème classique). *}
{if !empty($ciklik_bs5)}
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{l s='Close' mod='ciklik'}"></button>
{else}
    <button type="button" class="close" data-dismiss="modal" aria-label="{l s='Close' mod='ciklik'}"><span aria-hidden="true">&times;</span></button>
{/if}
