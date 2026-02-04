# Guide de mise à jour depuis 1.12.1 vers 1.13.0

Ce guide est destiné aux marchands et intégrateurs qui mettent à jour le module Ciklik depuis la version 1.12.1.

---

## Compatibilité

| PrestaShop | PHP | Statut |
|------------|-----|--------|
| 1.7.7 - 1.7.8 | 7.2 - 8.1 | Compatible |
| 8.0 - 8.2 | 8.0 - 8.4 | Compatible |
| 9.0.0 - 9.0.2 | 8.2 - 8.4 | Compatible |

**Nouveauté** : Cette version ajoute le support officiel de PrestaShop 9 et de PHP 8.3/8.4.

---

## Nouvelles langues de traduction

Les fichiers de traduction ont été enrichis et les langues suivantes sont maintenant entièrement couvertes :

- Allemand (`translations/de.php`)
- Espagnol (`translations/es.php`)
- Italien (`translations/it.php`)
- Polonais (`translations/pl.php`)

Les fichiers anglais (`en.php`) et français (`fr.php`) ont également été complétés avec toutes les nouvelles chaînes.

**Action** : Si vous avez personnalisé des fichiers de traduction, vérifiez que vos modifications sont toujours compatibles avec les nouvelles clés.

---

## Changements majeurs dans les templates

### 1. Traductions : passage aux chaînes source en anglais (partiel)

**Important** : La majorité des templates front-office utilisent maintenant l'anglais comme langue source pour les traductions. Cela concerne principalement la page "Mes abonnements" et ses modales d'actions.

**Templates concernés** (passage en anglais) :
- `account.tpl`
- `actions/changeDeliveryAddress.tpl`
- `actions/changeInterval.tpl`
- `actions/changeRebillDate.tpl`
- `actions/chooseUpsellSubscription.tpl`
- `actions/ListUpsellSubscriptionAndDelete.tpl`
- `subscription_customizations.tpl`
- `hook/displayProductActions.tpl`
- `admin/order_subscription_info.tpl`

**Avant :**
```smarty
{l s='Vos abonnements' mod='ciklik'}
{l s='Statut' mod='ciklik'}
{l s='Arrêter' mod='ciklik'}
```

**Après :**
```smarty
{l s='Your subscriptions' mod='ciklik'}
{l s='Status' mod='ciklik'}
{l s='Stop' mod='ciklik'}
```

**Templates NON concernés** (conservent le français comme langue source) :
- `hook/displayProductSubscriptionOptions.tpl` (conserve `Achat unique`, `Abonnement`, `Type d'achat`)
- `hook/displayShoppingCart.tpl` (conserve `Abonnement`)

**Action** : Si vous avez surchargé des templates concernés, vous **devez** mettre à jour les chaînes de traduction pour utiliser l'anglais comme source. Les fichiers de traduction (`translations/*.php`) contiennent les traductions dans toutes les langues.

### 2. Échappement des variables (sécurité XSS)

Toutes les variables affichées dans les templates sont maintenant échappées pour prévenir les attaques XSS.

**Avant :**
```smarty
{$subscription->display_content}
{$subscription->address->first_name}
{$product.id_product}
```

**Après :**
```smarty
{$subscription->display_content|escape:'html':'UTF-8'}
{$subscription->address->first_name|escape:'html':'UTF-8'}
{$product.id_product|intval}
```

**Règles d'échappement :**
- Chaînes de caractères : `|escape:'html':'UTF-8'`
- Nombres entiers : `|intval`
- UUID : `|escape:'html':'UTF-8'`

### 3. Compatibilité Bootstrap 5 (PrestaShop 9)

Les modales utilisent maintenant les attributs Bootstrap 4 et Bootstrap 5 en parallèle pour assurer la compatibilité avec toutes les versions de PrestaShop.

**Avant :**
```smarty
<u data-toggle="modal" data-target="#changeDeliveryAddress{$subscription->uuid}">
<button type="button" class="close" data-dismiss="modal">&times;</button>
```

**Après :**
```smarty
<u data-toggle="modal" data-target="#changeDeliveryAddress{$subscription->uuid|escape:'html':'UTF-8'}" data-bs-toggle="modal" data-bs-target="#changeDeliveryAddress{$subscription->uuid|escape:'html':'UTF-8'}">
<button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal">&times;</button>
```

**Action** : Si vous avez surchargé les templates de modales, ajoutez les attributs `data-bs-*` en plus des attributs `data-*` existants.

---

## Actions requises

### Si vous avez personnalisé des templates dans votre thème

Si vous avez surchargé des templates du module Ciklik dans votre thème (dossier `themes/votre-theme/modules/ciklik/`), vous devez les mettre à jour.

#### Templates modifiés

| Template | Changements | Action requise |
|----------|-------------|----------------|
| `views/templates/front/account.tpl` | CSRF, traductions EN, échappement, compatibilité BS5 | **Mise à jour obligatoire** |
| `views/templates/front/actions/changeDeliveryAddress.tpl` | CSRF, traductions EN, échappement, compatibilité BS5 | **Mise à jour obligatoire** |
| `views/templates/front/actions/changeInterval.tpl` | CSRF, traductions EN, échappement, compatibilité BS5 | **Mise à jour obligatoire** |
| `views/templates/front/actions/changeRebillDate.tpl` | CSRF, traductions EN, échappement, compatibilité BS5 | **Mise à jour obligatoire** |
| `views/templates/front/actions/chooseUpsellSubscription.tpl` | Traductions EN, échappement, bouton désactivé pendant soumission | **Mise à jour obligatoire** |
| `views/templates/front/actions/ListUpsellSubscriptionAndDelete.tpl` | Traductions EN, échappement | **Mise à jour obligatoire** |
| `views/templates/front/subscription_customizations.tpl` | Traductions EN, échappement | **Mise à jour obligatoire** |
| `views/templates/hook/displayProductActions.tpl` | Traductions EN, échappement | **Mise à jour obligatoire** |
| `views/templates/hook/displayProductSubscriptionOptions.tpl` | Formatage des prix PS9 (remplacement Tools::displayPrice) | **Mise à jour obligatoire** |

---

### Détail des modifications par template

#### 1. `account.tpl` - Page "Mes abonnements"

**Changements :**
- Protection CSRF sur les boutons Stop/Resume (formulaires POST remplaçant les liens GET)
- Toutes les traductions en anglais source
- Échappement de toutes les variables utilisateur
- Attributs Bootstrap 5 ajoutés

**Boutons Stop/Resume - Ancien code :**
```smarty
<a href="{$subcription_base_link}/{$subscription->uuid}/stop">{l s='Arrêter' mod='ciklik'}</a>
```

**Nouveau code :**
```smarty
<form action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/stop" method="POST" style="display:inline;">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <button type="submit" class="btn btn-link" style="padding:0;">{l s='Stop' mod='ciklik'}</button>
</form>
```

**Affichage des données - Ancien code :**
```smarty
{$subscription->display_content}
{$subscription->address->first_name} {$subscription->address->last_name}
```

**Nouveau code :**
```smarty
{$subscription->display_content|escape:'html':'UTF-8'}
{$subscription->address->first_name|escape:'html':'UTF-8'} {$subscription->address->last_name|escape:'html':'UTF-8'}
```

---

#### 2. `changeDeliveryAddress.tpl` - Modal de changement d'adresse

**Changements :**
- Token CSRF dans le formulaire
- Traductions en anglais source
- Échappement des variables
- Attributs Bootstrap 5 (`data-bs-toggle`, `data-bs-target`, `data-bs-dismiss`)

**Ajouter dans le formulaire :**
```smarty
<form id="changeAddressForm-{$subscription->uuid|escape:'html':'UTF-8'}" action="{$subcription_base_link|escape:'html':'UTF-8'}/{$subscription->uuid|escape:'html':'UTF-8'}/updateaddress" method="POST">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <!-- reste du formulaire -->
</form>
```

**Traductions à mettre à jour :**
```smarty
{l s='Change address' mod='ciklik'}
{l s='Change the address for your next delivery' mod='ciklik'}
{l s='Select the address where your next order will be shipped.' mod='ciklik'}
{l s='If you want to create a new delivery address, go to your My Account area, Addresses section.' mod='ciklik'}
{l s='Address:' mod='ciklik'}
{l s='Update' mod='ciklik'}
{l s='Cancel' mod='ciklik'}
```

---

#### 3. `changeInterval.tpl` - Modal de changement de fréquence

**Changements :**
- Token CSRF dans le formulaire
- Traductions en anglais source
- Échappement des variables
- Attributs Bootstrap 5

**Traductions à mettre à jour :**
```smarty
{l s='Change frequency' mod='ciklik'}
{l s='Change my subscription frequency' mod='ciklik'}
{l s='You can select a new frequency that will apply to all products in your subscription if available.' mod='ciklik'}
{l s='The date of your next order will not change, the frequency change will take effect from the following order.' mod='ciklik'}
{l s='Choose' mod='ciklik'}
{l s='No other combination available' mod='ciklik'}
{l s='Cancel' mod='ciklik'}
```

---

#### 4. `changeRebillDate.tpl` - Modal de changement de date

**Changements :**
- Token CSRF dans le formulaire
- Traductions en anglais source
- Échappement des variables
- Attributs Bootstrap 5

**Traductions à mettre à jour :**
```smarty
{l s='Change date' mod='ciklik'}
{l s='Change my next payment date' mod='ciklik'}
{l s='You can receive your next order sooner by advancing your subscription renewal date or postpone it later if needed.' mod='ciklik'}
{l s='The selected next payment date will be the new anniversary date for your subscription.' mod='ciklik'}
{l s='New date:' mod='ciklik'}
{l s='Update' mod='ciklik'}
{l s='Cancel' mod='ciklik'}
```

---

#### 5. `chooseUpsellSubscription.tpl` - Modal d'ajout de produit

**Changements :**
- Traductions en anglais source
- Échappement des variables et IDs
- Bouton désactivé pendant la soumission AJAX
- Logique AJAX refactorisée (utilise `fetch` au lieu de jQuery)

**Traductions à mettre à jour :**
```smarty
{l s='Add product to an existing subscription' mod='ciklik'}
{l s='Select subscription' mod='ciklik'}
{l s='No active subscription found' mod='ciklik'}
{l s='Quantity' mod='ciklik'}
{l s='Cancel' mod='ciklik'}
{l s='Add to subscription' mod='ciklik'}
{l s='The product has been successfully added to your subscription' mod='ciklik' js=1}
{l s='An error occurred.' mod='ciklik' js=1}
```

**Échappement des IDs :**
```smarty
<input type="hidden" name="id_product" value="{$product.id_product|intval}">
<input type="hidden" name="id_product_attribute" value="{$product.id_product_attribute|intval}">
```

---

#### 6. `ListUpsellSubscriptionAndDelete.tpl` - Liste des upsells

**Changements :**
- Traductions en anglais source
- Échappement des variables

**Traductions à mettre à jour :**
```smarty
{l s='Non-recurring additional products' mod='ciklik'}
{l s='Products' mod='ciklik'}
{l s='Quantity' mod='ciklik'}
```

---

#### 7. `subscription_customizations.tpl` - Personnalisations

**Changements :**
- Traductions en anglais source
- Échappement des variables

**Traductions à mettre à jour :**
```smarty
{l s='Quantity' mod='ciklik'}
```

---

#### 8. `displayProductActions.tpl` - Actions sur la fiche produit

**Changements :**
- Traductions en anglais source
- Échappement des variables

**Traductions à mettre à jour :**
```smarty
{l s='Add to subscription' mod='ciklik'}
```

---

#### 9. `displayProductSubscriptionOptions.tpl` - Options d'abonnement sur la fiche produit

**Changements majeurs :**
- Remplacement des appels `{Tools::displayPrice(...)}` par des variables pré-formatées
- Les traductions restent en **français** dans ce template (`Achat unique`, `Abonnement`, `Type d'achat`)

**Ancien code :**
```smarty
{Tools::displayPrice($product.price)}
{Tools::displayPrice($frequency.discount_price)}
```

**Nouveau code :**
```smarty
{$ciklik_product_price_formatted}
{$frequency.formatted_discount_price}
```

**Variables de prix disponibles :**
- `{$ciklik_product_price_formatted}` - Prix du produit formaté
- `{$frequency.formatted_discount_price}` - Montant de la réduction formaté
- `{$frequency.formatted_discounted_price}` - Prix après réduction formaté
- `{$frequency.formatted_original_price}` - Prix original formaté

**Important** : Cette modification est **obligatoire pour PrestaShop 9** (où `Tools::displayPrice()` n'existe plus). Le module pré-calcule et formate désormais les prix via `PriceHelper::formatPrice()` côté PHP.

---

### Templates back-office (détail)

Ces templates sont rarement surchargés. Les modifications sont recommandées mais pas obligatoires si vous n'avez pas personnalisé ces fichiers.

#### 10. `order_subscription_info.tpl` - Infos abonnement (BO commande)

**Changements :**
- Traductions en anglais source
- Échappement XSS sur toutes les variables
- Nouveau tableau avec les informations détaillées de l'abonnement (fingerprint, upsells, etc.)

Ce template back-office est rarement surchargé. Si vous l'avez personnalisé, référez-vous au fichier source du module pour les nouvelles chaînes de traduction.

---

#### 11. `displayAdminOrderRefunds.tpl` - Bloc remboursement (BO commande)

**Changements :**
- Token CSRF pour les requêtes AJAX de remboursement

**Ajouter dans le formulaire :**
```smarty
<input type="hidden" name="ajax_token" value="{$ajaxToken|escape:'htmlall':'UTF-8'}"/>
```

**Ajouter dans la requête AJAX :**
```javascript
ajax_token: $form.find('[name=ajax_token]').val()
```

---

## Vérification rapide

Pour vérifier si vous avez des surcharges de templates à mettre à jour :

```bash
# Depuis la racine de votre PrestaShop

# Templates front (fréquemment surchargés)
ls themes/*/modules/ciklik/views/templates/front/
ls themes/*/modules/ciklik/views/templates/hook/

# Templates admin (rarement surchargés)
ls override/modules/ciklik/views/templates/admin/
ls override/modules/ciklik/views/templates/hook/displayAdminOrderRefunds.tpl
```

Si ces dossiers/fichiers existent et contiennent des fichiers, vous devez les mettre à jour.

---

## Améliorations de sécurité

Cette version inclut plusieurs améliorations de sécurité :

1. **Protection CSRF** : Toutes les actions de modification d'abonnement (arrêt, reprise, changement d'adresse, de fréquence, de date) sont maintenant protégées contre les attaques CSRF via des tokens dans les formulaires POST. Les remboursements admin utilisent également un token CSRF dédié.

2. **Authentification requise** : Le controller `subscription.php` exige maintenant une authentification (`$auth = true`). Les utilisateurs non connectés sont redirigés vers la page de connexion.

3. **Validation de propriété** : Le module vérifie désormais que l'abonnement appartient bien au client connecté avant d'effectuer toute action, en comparant l'ID client du fingerprint avec le client en session.

4. **Échappement XSS** : Toutes les variables affichées dans les templates sont maintenant échappées. Les messages d'exception provenant de sources externes (API) sont échappés via `Tools::htmlentitiesUTF8()`.

5. **Typage renforcé** : Les paramètres numériques (ID produit, ID adresse, quantité, ID commande) sont maintenant explicitement convertis en entiers avec `(int)`.

6. **Validation des UUIDs** : Les identifiants d'abonnement sont validés via `UuidHelper` (format UUID v4) avant utilisation. Les clients API valident également le format des identifiants avant d'envoyer des requêtes.

7. **Sanitisation SQL** : Utilisation systématique de `pSQL()` pour les valeurs insérées en base et de `(int)` pour les identifiants dans les clauses WHERE.

8. **Comparaison à temps constant** : L'authentification gateway utilise `hash_equals()` pour éviter les timing attacks.

Ces améliorations sont transparentes pour les utilisateurs finaux mais renforcent la sécurité globale.

---

## Notes de mise à jour

- Aucune migration de base de données n'est requise
- Les abonnements existants ne sont pas affectés
- Les fichiers JavaScript (`subscription.js` et `subscription-options.js`) ont été ajoutés/modifiés pour gérer les options d'abonnement sur la page produit (mode fréquence). Si vous avez surchargé ces fichiers, référez-vous aux sources du module
- La plage des valeurs d'intervalle (mode attributs) passe de 1-12 à 1-32, permettant de définir des fréquences plus longues

---
