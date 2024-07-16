# Module de gestion d'abonnements Ciklik pour Prestashop

### Tips

* Pour cacher les autres méthodes de paiement, quand il y a un abonnement dans le panier ,
ouvrir le fichier `themes/{theme}/templates/checkout/_partials/steps/payment.tpl` et ajouter le code suivant en dessous de :
`{foreach from=$module_options item="option"}`

```
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

## Upgrade 1.2.0

- Variables configuration :

**CONFIG_ENABLE_ENGAGEMENT** Activer l'engagement sur tous les abonnements
**CONFIG_ENGAGEMENT_INTERVAL** Engagement en mois/jour/semaine (month/day/week)
**CONFIG_ENGAGEMENT_INTERVAL_COUNT** Combien d'*CONFIG_ENGAGEMENT_INTERVAL*
**CONFIG_ALLOW_CHANGE_NEXT_BILLING** Autoriser le changement de date du prochain paiement depuis l'espace mon compte

## Upgrade 1.1.0

- Variables configuration :

**CIKLIK_ONEOFF_ATTRIBUTE_ID** id de l'attribut "Achat en une fois"

**CIKLIK_SUBSCRIPTION_ATTRIBUTE_ID** id de l'attribut "Achat par abonnement"

**CIKLIK_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID** id de l'attribut de fréquence sélectionné lorsqu'on active l'achat par abonnement sur une page produit en front

- Hooks :

**actionGetProductPropertiesBefore**

Permet d'ajouter des variables Ciklik aux propriétés d'un produit qui seront traitées dans ProductController.

Ces variables sont placées dans le tableau product (circulant au travers des hooks via $params), sous la clé ciklik :

```php
$params['product']['ciklik'] = [
    'enabled' => true|false, // le module Ciklik est actif (false si un code VPC est actif)
    'selected' => true|false, // l'achat par abonnement a été sélectionné pour le produit affiché
    'id_product' => $params['product']['id_product'],
    'current_id_product_attribute' => int, // id de la combinaison sélectionnée
    'subscription_price' => int, // prix de la combinaison sélectionnée
    'reference_id_product_attribute' => int, // id de la combinaison sélectionnée en version "achat en une fois"
    'subscription_reference_price' => float, // prix de la combinaison sélectionnée en version "achat en une fois"
    'format_id_attribute' => int, // id de l'attribut de format du produit (ex unité, lot de 2,3,4...)
    'frequency_id_attribute' => int // id de l'attribut de fréquence d'abonnement sélectionné
];
```

IMPORTANT : le module Ciklik doit être en 1ère position d'exécution dans le hook actionGetProductPropertiesBefore

## Personnalisation de la page produit

Pour gérer et personnaliser l'affichage des options d'abonnement sur la page d'un produit, il est nécessaire de surcharger le fichier ProductController.php.

Créer le fichier /override/controllers/front/ProductController.php avec le contenu suivant :

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

Les variables créées précédemment via le hook **actionGetProductPropertiesBefore** sont ensuite accessibles dans le fichier **{nom_du_thème}/templates/catalog/_partials/product-variants.tpl** dans l'object **ciklik**.

### Optionnel : Affichage des options d'abonnement par activation d'une case à cocher

* Dans l'admin du module, activer l'option "Déléguer l'affichage des options"
* Dans le fichier de thème **{nom_du_thème}/templates/catalog/_partials/product-variants.tpl**, ajouter le bouton radio dont le nom doit obligatoirement être **ciklik** :
```html
<input type="checkbox" name="ciklik" value="1" {if $ciklik.selected} checked="checked"{/if}>
```
