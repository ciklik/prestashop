{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Remplace le lien « Changer l'adresse » quand l'abonnement est livré en point
 * relais et qu'un relais peut être proposé : même emplacement, vers la page
 * de choix du relais. Même aspect que les autres liens de la page (texte
 * souligné dans la couleur du texte) : la couleur des liens du thème est
 * neutralisée en ligne, survol compris, sur Classic comme sur Hummingbird.
 *}
<small><a href="{$relay_change_url|escape:'html':'UTF-8'}" class="ciklik-change-pickup-point" style="color: inherit;"><u>{l s='Change pickup point' mod='ciklik'}</u></a></small>
