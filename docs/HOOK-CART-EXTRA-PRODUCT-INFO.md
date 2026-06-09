# Affichage des infos d'abonnement sur les lignes panier

Depuis la version **1.21.0**, le module rattache un rendu au hook PrestaShop core `displayCartExtraProductInfo`. Ce hook permet d'afficher, sur chaque ligne produit du récap panier (page panier + colonne de droite du checkout), le type d'achat sélectionné et la fréquence d'abonnement quand le mode fréquence est activé.

## Prérequis

| Élément | Version | Notes |
|---|---|---|
| PrestaShop | **8.2.0+** | Le hook a été introduit dans le noyau via [PR #38691](https://github.com/PrestaShop/PrestaShop/pull/38691), mergée le 2025-05-15 sur la branche `8.2.x`. |
| Thème | Classic 8.2+, Hummingbird, ou thème custom appelant le hook | Sur les versions PS antérieures ou les thèmes legacy, le hook n'est pas appelé par défaut. Voir la section [Activation sur thème legacy](#activation-sur-thème-legacy). |
| Mode du module | **Mode fréquence** activé (`CIKLIK_FREQUENCY_MODE = 1`) | En mode attributs, l'information est déjà portée par le nom de combinaison. Le rendu retourne une chaîne vide. |

## Comportement par défaut

Pour chaque ligne produit du panier, le module vérifie :

1. Le mode fréquence est-il actif ? Sinon → aucun rendu.
2. Le produit est-il *subscribable* (au moins une fréquence rattachée via `ciklik_product_frequency`) ? Sinon → aucun rendu (les produits hors offre d'abonnement restent inchangés).
3. La ligne panier porte-t-elle une fréquence (`ciklik_items_frequency`) ?
   - **Oui** : affiche `Type d'achat : Abonnement` + `Fréquence : {nom de la fréquence}`.
   - **Non** : affiche `Type d'achat : Achat unique`.

## Markup produit

Le template par défaut produit le HTML suivant :

```html
<div class="ciklik-cart-extra-product-info" data-ciklik-purchase-type="subscription">
  <div class="ciklik-cart-extra-product-info__row ciklik-cart-extra-product-info__row--purchase-type">
    <span class="ciklik-cart-extra-product-info__label">Type d'achat :</span>
    <span class="ciklik-cart-extra-product-info__value">Abonnement</span>
  </div>
  <div class="ciklik-cart-extra-product-info__row ciklik-cart-extra-product-info__row--frequency">
    <span class="ciklik-cart-extra-product-info__label">Fréquence :</span>
    <span class="ciklik-cart-extra-product-info__value">Mensuel</span>
  </div>
</div>
```

L'attribut `data-ciklik-purchase-type` vaut `subscription` ou `one_off`. Pratique pour différencier visuellement les deux états en CSS sans toucher au template.

## Personnaliser le rendu

### Surcharge du template depuis un thème

Copier le template dans le dossier du thème :

```
themes/{theme}/modules/ciklik/views/templates/hook/displayCartExtraProductInfo.tpl
```

PrestaShop résout en priorité le template du thème, puis celui du module.

### Variables Smarty disponibles

| Variable | Type | Description |
|---|---|---|
| `$purchase_type` | string | `'subscription'` ou `'one_off'` |
| `$frequency_name` | string\|null | Nom de la fréquence si abonnement, `null` sinon |
| `$id_product` | int | ID produit |
| `$id_product_attribute` | int | ID combinaison (0 si pas de combinaison) |
| `$product` | object/array | Donnée brute passée par le presenter PrestaShop (champs dépendent de la version PS) |

### Exemple — n'afficher que la fréquence (sans le type d'achat)

```smarty
{if $purchase_type === 'subscription' && $frequency_name}
  <div class="my-frequency-line">
    {l s='Renews every:' mod='ciklik'} {$frequency_name|escape:'html':'UTF-8'}
  </div>
{/if}
```

### Exemple — afficher une icône selon le type

```smarty
<div class="ciklik-cart-extra-product-info" data-ciklik-purchase-type="{$purchase_type}">
  {if $purchase_type === 'subscription'}
    <i class="material-icons">autorenew</i> {l s='Subscription' mod='ciklik'} - {$frequency_name|escape:'html':'UTF-8'}
  {else}
    <i class="material-icons">shopping_cart</i> {l s='One-off purchase' mod='ciklik'}
  {/if}
</div>
```

### Personnalisation CSS

Tous les éléments du template ont des classes BEM dédiées. Cibles principales :

```css
.ciklik-cart-extra-product-info { /* conteneur global */ }
.ciklik-cart-extra-product-info[data-ciklik-purchase-type="subscription"] { /* abonnement */ }
.ciklik-cart-extra-product-info[data-ciklik-purchase-type="one_off"] { /* achat unique */ }
.ciklik-cart-extra-product-info__row { /* une ligne (type ou fréquence) */ }
.ciklik-cart-extra-product-info__row--purchase-type { /* ligne « Type d'achat » */ }
.ciklik-cart-extra-product-info__row--frequency { /* ligne « Fréquence » */ }
.ciklik-cart-extra-product-info__label { /* libellé (Type d'achat :, Fréquence :) */ }
.ciklik-cart-extra-product-info__value { /* valeur (Abonnement, Mensuel, etc.) */ }
```

Le module n'embarque aucun CSS pour ce hook : c'est volontairement neutre pour ne pas casser l'intégration des thèmes. Ajouter le styling dans la feuille du thème.

## Activation sur thème legacy

### PrestaShop < 8.2 ou thème custom

Si la version PS est antérieure à 8.2 ou si le thème n'appelle pas le hook nativement, l'enregistrement côté module est silencieux (aucun rendu). Pour activer l'affichage, modifier le partial du thème qui rend une ligne produit dans le récap panier.

Sur un thème dérivé de Classic, le partial concerné est :

```
themes/{theme}/templates/checkout/_partials/cart-detailed-product-line.tpl
```

Ajouter l'appel au hook après le bloc description / customizations, avant la colonne quantité/prix (référence : [PrestaShop classic-theme#170](https://github.com/PrestaShop/classic-theme/pull/170)) :

```smarty
{hook h='displayCartExtraProductInfo' product=$product}
```

Sur des thèmes plus exotiques (Warehouse, Eveprest, etc.), repérer le partial qui rend une ligne du panier (souvent `cart-detailed-product-line.tpl` ou équivalent) et y insérer la même ligne.

## Désactiver l'affichage

Deux options :

1. **Désactiver le hook côté BO** : *Modules > Positions*, rechercher `displayCartExtraProductInfo`, retirer Ciklik.
2. **Surcharger le template avec un contenu vide** dans le thème (cf. ci-dessus).

## Limitations connues

- En **mode attributs**, le hook ne produit rien : l'information « abonnement / mensuel » est portée par le nom de combinaison du produit, et l'afficher en plus serait redondant.
- Si plusieurs lignes panier référencent le même `id_product` (cas inhabituel avec combinaisons différentes), la fréquence affichée est celle stockée pour la première ligne (la table `ciklik_items_frequency` est indexée par `cart_id + product_id`).
- Le module ne stylise pas le rendu : c'est à l'agence ou au marchand de l'intégrer harmonieusement au thème.

## Référence

- Hook PrestaShop : [PR core #38691](https://github.com/PrestaShop/PrestaShop/pull/38691)
- Intégration thème Classic : [classic-theme#170](https://github.com/PrestaShop/classic-theme/pull/170)
- Intégration Hummingbird : [hummingbird#699](https://github.com/PrestaShop/hummingbird/pull/699)
- Discussion : [PrestaShop#38692](https://github.com/PrestaShop/PrestaShop/discussions/38692)
