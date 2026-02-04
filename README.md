# Module de gestion d'abonnements Ciklik pour PrestaShop

Module de paiement permettant la gestion des abonnements et paiements récurrents via la plateforme [Ciklik](https://ciklik.co).

## Compatibilité

| Composant | Versions supportées |
|-----------|---------------------|
| PrestaShop | 1.7.7 - 9.x |
| PHP | 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 |

## Installation

1. Télécharger le zip correspondant à votre version PHP depuis les [GitHub Releases](https://github.com/ciklik/prestashop/releases)
2. Dans le back-office PrestaShop, aller dans **Modules > Gestionnaire de modules**
3. Cliquer sur **Installer un module** et sélectionner le fichier zip
4. Configurer le module avec votre token API Ciklik

## Personnalisation

### Masquer les autres moyens de paiement pour les abonnements

Pour n'afficher que le paiement Ciklik quand le panier contient un abonnement, ouvrir le fichier `themes/{theme}/templates/checkout/_partials/steps/payment.tpl` et ajouter le code suivant au-dessus de `{foreach from=$module_options item="option"}` :

```smarty
{assign var="ciklikOption" value=null}
{foreach from=$payment_options item="module_options"}
    {if isset($module_options[0]) && $module_options[0].module_name == 'ciklik'}
        {assign var="ciklikOption" value=$module_options}
        {break}
    {/if}
{/foreach}

{if $ciklikOption !== null}
    {assign var="payment_options" value=[$ciklikOption]}
{/if}
```

### Personnalisation de la page produit

Pour gérer et personnaliser l'affichage des options d'abonnement sur la page d'un produit, il est nécessaire de surcharger le fichier ProductController.php.

Créer le fichier `/override/controllers/front/ProductController.php` avec le contenu suivant :

```php
class ProductController extends ProductControllerCore
{
    protected function assignAttributesGroups($product_for_template = null)
    {
        parent::assignAttributesGroups($product_for_template);

        /**
         * Ciklik START
         */
        $this->context->smarty->assign([
            'ciklik' => array_merge([
                'enabled' => false,
                'selected' => false,
            ], isset($product_for_template['ciklik']) ? $product_for_template['ciklik'] : []),
        ]);
        /*
         * Ciklik END
         */
    }
}
```

Les variables créées via le hook `actionGetProductPropertiesBefore` sont ensuite accessibles dans le fichier `{theme}/templates/catalog/_partials/product-variants.tpl` dans l'objet `ciklik` :

```php
$ciklik = [
    'enabled' => true|false,           // le module Ciklik est actif
    'selected' => true|false,          // l'achat par abonnement a été sélectionné
    'id_product' => int,
    'current_id_product_attribute' => int,
    'subscription_price' => float,
    'reference_id_product_attribute' => int,
    'subscription_reference_price' => float,
    'format_id_attribute' => int,
    'frequency_id_attribute' => int
];
```

**Important** : Le module Ciklik doit être en 1ère position d'exécution dans le hook `actionGetProductPropertiesBefore`.

### Affichage des options par case à cocher (optionnel)

1. Dans l'admin du module, activer l'option **Déléguer l'affichage des options**
2. Dans le fichier de thème `{theme}/templates/catalog/_partials/product-variants.tpl`, ajouter :

```html
<input type="checkbox" name="ciklik" value="1" {if $ciklik.selected} checked="checked"{/if}>
```

## Support

- Guide de mise à jour : voir [UPGRADE.md](UPGRADE.md)
- Support : support@ciklik.co

## Licence

Academic Free License (AFL 3.0)
