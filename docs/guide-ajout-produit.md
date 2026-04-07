# Guide d'implémentation : Ajout de produit à un abonnement

**Disponible depuis** : v1.18.0

Ce guide s'adresse aux développeurs souhaitant intégrer une UI d'ajout de produit à un abonnement existant côté front-office. Le module Ciklik fournit un **endpoint backend** prêt à l'emploi mais **n'embarque volontairement pas d'interface client par défaut** : l'UX dépend trop fortement des contraintes métier (catalogue, mode subscribable, parcours client) pour qu'une seule implémentation convienne à tous.

L'objectif de ce document est de vous donner :
1. Le contrat du backend disponible
2. Plusieurs approches UX possibles avec leurs trade-offs
3. Les critères de décision
4. Des exemples de code prêts à adapter
5. Les points d'attention à anticiper

---

## 1. Backend disponible

### Endpoint front-office

```
POST /ciklik/subscription/{uuid}/addProduct
```

Cet endpoint correspond à l'action `addProduct` du contrôleur `controllers/front/subscription.php`. Il appelle en interne la méthode `Subscription::addProduct()` du client API qui frappe l'endpoint Ciklik `POST /api/v3/subscriptions/{uuid}/products` (upsert).

### Paramètres POST attendus

| Paramètre | Type | Obligatoire | Description |
|-----------|------|-------------|-------------|
| `action` | string | oui | Doit valoir `addProduct` |
| `uuid` | string | oui | UUID v4 de l'abonnement (présent aussi dans la route) |
| `id_product` | int | oui | ID du produit PrestaShop (> 0) |
| `id_product_attribute` | int | non | ID combinaison (0 si aucune) |
| `quantity` | int | non | Quantité (défaut 1, min 1) |
| `token` | string | oui | Token CSRF PrestaShop (`{$token}` dans Smarty) |

### Validations effectuées par le contrôleur

Avant de transmettre la requête à l'API Ciklik, le contrôleur applique :

1. **CSRF** — `$this->isTokenValid()`
2. **Format UUID v4** — strict, via `UuidHelper`
3. **Propriété de l'abonnement** — `validateSubscriptionOwnership()` vérifie que l'abonnement appartient au client connecté
4. **Existence du produit** — `Validate::isLoadedObject()`

### Données envoyées à l'API Ciklik

Le contrôleur construit automatiquement le payload :

| Champ API | Construction côté contrôleur |
|-----------|------------------------------|
| `external_id` | `{id_product}:{id_product_attribute}` |
| `name` | `Product::getProductName($id_product, $id_product_attribute)` (fallback : `$product->name`) |
| `quantity` | `max(1, (int) $quantity)` |
| `tax` | `(float) $product->getTaxesRate() / 100` |

### Validations côté API Ciklik (réponses 422)

L'API Ciklik rejette les requêtes dans les cas suivants :

- Abonnement inactif (`active === false`)
- Produit avec customization hash dans l'`external_id` (suffixe `_<md5>`)
- Champs requis manquants (`name`, `external_id`)
- `quantity` hors bornes (1-9999)
- `tax` hors bornes (0-1)

### Format de réponse JSON (AJAX)

**Succès**
```json
{
  "success": true,
  "message": "The product has been added to your subscription."
}
```

**Erreur**
```json
{
  "success": false,
  "message": "Description de l'erreur (traduite ou venant de l'API)"
}
```

L'endpoint retourne toujours du JSON. Côté front, parser systématiquement la réponse avec `response.json()` et vérifier `response.ok` avant.

---

## 2. Approches UX possibles

### Approche A — Bouton sur la fiche produit

**Cas d'usage** : le client navigue dans le catalogue, voit un produit, clique sur "Ajouter à mes abonnements", choisit un abonnement actif dans une modale, valide.

| | |
|---|---|
| **Avantages** | Découvrabilité maximale (le client est sur le produit). Réutilise la modal upsell existante (`chooseUpsellSubscription.tpl`). |
| **Inconvénients** | Nécessite de filtrer les abonnements actifs du client connecté. Doit gérer le cas "client non connecté" (redirection login ou bouton désactivé). |
| **Hooks utiles** | `displayProductActions` (déjà utilisé pour le sélecteur de fréquence). |
| **Composants existants à réutiliser** | `chooseUpsellSubscription.tpl` qui gère déjà le pattern "modal + sélecteur d'abonnement + form AJAX". |

### Approche B — Modal "Ajouter un produit" depuis le compte client

**Cas d'usage** : sur la page "Mes abonnements", un bouton "Ajouter un produit" ouvre une modal avec un champ de recherche autocomplete.

| | |
|---|---|
| **Avantages** | Le client garde le contexte de son abonnement. Permet de filtrer aux produits éligibles. |
| **Inconvénients** | Nécessite un endpoint d'autocomplete (recherche produits). Plus complexe à développer. |
| **Composants à créer** | Endpoint `GET /module/ciklik/searchproducts?q=...`, modal Bootstrap, JS d'autocomplete débouncé. |
| **Intégration** | Ajouter le bouton à la fin de `views/templates/front/actions/subscriptionProducts.tpl`. |

### Approche C — Liste suggérée (cross-sell contextuel)

**Cas d'usage** : afficher une liste de 3-5 produits "suggérés" sous chaque abonnement, avec un bouton "Ajouter" rapide.

| | |
|---|---|
| **Avantages** | UX rapide, pas de recherche. Met en avant des produits choisis par le marchand. Conversion potentielle élevée. |
| **Inconvénients** | Nécessite une logique de sélection des suggestions (catégorie, accessoires, manuel). Statique. |
| **Critères de sélection des suggestions** | Produits de la même catégorie que ceux déjà dans l'abonnement / produits liés (accessoires PrestaShop natifs) / produits subscribable les plus vendus / configuration manuelle par le marchand (nouvelle table). |

### Approche D — Duplication d'un upsell récent

**Cas d'usage** : "Ré-ajouter le dernier produit ajouté à mon abonnement précédent" en un clic.

| | |
|---|---|
| **Avantages** | UX minimaliste. Très facile à implémenter. |
| **Inconvénients** | Cas d'usage limité, peu de découvrabilité. |
| **Implémentation** | Lire le dernier upsell de l'abonnement et appeler directement `addProduct` avec ses IDs. |

---

## 3. Critères de décision

| Question | Impact sur le choix |
|----------|---------------------|
| Quel mode utilisé (frequency / attributes) ? | Détermine la vérification "subscribable" : `SubscriptionHelper::isSubscriptionEnabled()` vs `CiklikSubscribable::isSubscribable()`. |
| Tout le catalogue ou un subset filtré ? | Détermine s'il faut un endpoint de filtrage côté serveur. |
| Recherche libre ou liste pré-calculée ? | Détermine la complexité de l'autocomplete (statique vs dynamique). |
| Modal Bootstrap ou page dédiée ? | Modal = moins de friction mais limité en taille. Page dédiée = plus de place mais rupture du parcours. |
| Suggestion contextualisée ou choix libre ? | Détermine la logique de pré-sélection (catégorie, historique, manuel). |
| Mobile first ? | Détermine si on peut se permettre une modal complexe avec recherche live. |
| Les abonnements ont-ils plusieurs fréquences distinctes ? | Si oui, il faut s'assurer que le produit ajouté est compatible avec la fréquence en cours. |

---

## 4. Exemple : approche modale dans le compte client (Approche B)

Cette section présente un exemple complet pour l'approche B, qui est la plus courante.

### 4.1 Endpoint d'autocomplete

Créer `controllers/front/searchproducts.php` :

```php
<?php

use PrestaShop\Module\Ciklik\Helpers\SubscriptionHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscribable;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSearchProductsModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $ajax = true;

    public function postProcess()
    {
        $query = trim((string) Tools::getValue('q', ''));

        if (mb_strlen($query) < 2) {
            $this->ajaxRender(json_encode(['products' => []]));

            return;
        }

        // Recherche dans le catalogue
        $results = Search::find($this->context->language->id, $query, 1, 20, 'position', 'asc');

        // Filtre : ne garder que les produits subscribable
        $useFrequencyMode = (bool) Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE);
        $filtered = [];

        foreach ($results['result'] as $p) {
            $idProduct = (int) $p['id_product'];
            $isSubscribable = $useFrequencyMode
                ? SubscriptionHelper::isSubscriptionEnabled($idProduct)
                : CiklikSubscribable::isSubscribable($idProduct);

            if ($isSubscribable) {
                $filtered[] = [
                    'id_product' => $idProduct,
                    'name' => $p['pname'],
                    'price' => Tools::displayPrice($p['price']),
                ];
            }
        }

        $this->ajaxRender(json_encode(['products' => $filtered]));
    }
}
```

Et enregistrer la route dans le hook `hookModuleRoutes` du fichier principal du module (ou via un sous-module / patch local).

### 4.2 Bouton "Ajouter un produit" dans le template

Étendre `views/templates/front/actions/subscriptionProducts.tpl` en ajoutant à la fin du `<div class="collapse">`, après le `</table>` :

```smarty
<div class="mt-3 text-right">
    <button type="button"
            class="btn btn-link"
            data-toggle="modal"
            data-bs-toggle="modal"
            data-target="#addProductModal{$subscription->uuid|escape:'html':'UTF-8'}"
            data-bs-target="#addProductModal{$subscription->uuid|escape:'html':'UTF-8'}">
        <i class="material-icons" style="font-size: 18px;">add_circle</i>
        <small>{l s='Add a product' mod='ciklik'}</small>
    </button>
</div>

{include file="module:ciklik/views/templates/front/actions/addProductModal.tpl" subscription=$subscription}
```

> **Note Bootstrap 4/5** : doubler les attributs `data-toggle` / `data-bs-toggle` permet de couvrir PS 1.7/8 (BS4) et PS 9 (BS5).

### 4.3 Modal `addProductModal.tpl`

Créer `views/templates/front/actions/addProductModal.tpl` :

```smarty
{**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *}

<div class="modal fade" id="addProductModal{$subscription->uuid|escape:'html':'UTF-8'}" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{l s='Add a product to your subscription' mod='ciklik'}</h5>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="text"
                       class="form-control ciklik-product-search"
                       data-subscription-uuid="{$subscription->uuid|escape:'html':'UTF-8'}"
                       placeholder="{l s='Search for a product...' mod='ciklik'}"
                       autocomplete="off">
                <ul class="list-group mt-2 ciklik-product-results" style="max-height: 300px; overflow-y: auto;"></ul>
                <div class="ciklik-product-feedback mt-2"></div>
            </div>
        </div>
    </div>
</div>
```

### 4.4 JavaScript d'autocomplete et soumission

À ajouter dans le bloc `<script>` de `subscriptionProducts.tpl` (ou dans un fichier JS dédié chargé via `actionFrontControllerSetMedia`) :

```javascript
document.querySelectorAll('.ciklik-product-search').forEach(function(input) {
    var debounceTimer;
    var modalBody = input.closest('.modal-body');
    var resultsContainer = modalBody.querySelector('.ciklik-product-results');
    var feedback = modalBody.querySelector('.ciklik-product-feedback');
    var uuid = input.dataset.subscriptionUuid;

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var query = input.value.trim();

        if (query.length < 2) {
            resultsContainer.innerHTML = '';

            return;
        }

        debounceTimer = setTimeout(function() {
            fetch('/module/ciklik/searchproducts?q=' + encodeURIComponent(query))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultsContainer.innerHTML = '';
                    data.products.forEach(function(p) {
                        var li = document.createElement('li');
                        li.className = 'list-group-item';
                        li.style.cursor = 'pointer';
                        li.textContent = p.name + ' — ' + p.price;
                        li.addEventListener('click', function() {
                            addProductToSubscription(uuid, p.id_product, feedback);
                        });
                        resultsContainer.appendChild(li);
                    });
                });
        }, 300);
    });
});

function addProductToSubscription(uuid, productId, feedback) {
    var formData = new FormData();
    formData.append('action', 'addProduct');
    formData.append('uuid', uuid);
    formData.append('id_product', productId);
    formData.append('quantity', 1);
    formData.append('token', '{$token|escape:'javascript':'UTF-8'}');

    fetch('{$subcription_base_link|escape:'javascript':'UTF-8'}/' + uuid + '/addProduct', {
        method: 'POST',
        body: formData
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(result) {
        if (result.success) {
            feedback.innerHTML = '<div class="alert alert-success">' + result.message + '</div>';
            setTimeout(function() { window.location.reload(); }, 1500);
        } else {
            feedback.innerHTML = '<div class="alert alert-danger">' + result.message + '</div>';
        }
    })
    .catch(function() {
        feedback.innerHTML = '<div class="alert alert-danger">{l s='An error occurred.' mod='ciklik' js=1}</div>';
    });
}
```

---

## 5. Points d'attention

### 5.1 Filtrer les produits éligibles (obligatoire, dans les 2 modes)

**Important** : l'API Ciklik **ne vérifie pas** la subscribabilité d'un produit. Elle crée même automatiquement le produit en base s'il n'existe pas (cf. OpenAPI spec). Les seuls rejets côté API sont :
- Produits dont l'`external_id` contient un customization hash (`_<md5>`)
- Abonnement inactif
- Champs requis manquants

**La responsabilité de filtrer les produits subscribable est donc entièrement côté module**, et la vigilance est **symétrique** dans les deux modes. Sans ce filtrage, un client peut techniquement ajouter n'importe quel produit à son abonnement, ce qui crée une incohérence métier (produit sans fréquence associée).

À vérifier avant tout appel à `addProduct` :

| Mode | Méthode à appeler | Source de vérité |
|------|-------------------|------------------|
| **Frequency** | `SubscriptionHelper::isSubscriptionEnabled((int) $idProduct)` | Table `ciklik_product_frequency` |
| **Attributes** | `CiklikSubscribable::isSubscribable((int) $idProduct)` | Table `ciklik_subscribables` |

Pour choisir dynamiquement selon la config :

```php
$useFrequencyMode = (bool) Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE);
$isSubscribable = $useFrequencyMode
    ? SubscriptionHelper::isSubscriptionEnabled($idProduct)
    : CiklikSubscribable::isSubscribable($idProduct);

if (!$isSubscribable) {
    // Ne pas proposer / rejeter l'ajout
}
```

> **Note** : le contrôleur front `addProduct` du module fait cette vérification depuis la v1.18.0. Si vous appelez directement `Subscription::addProduct()` depuis un autre contrôleur ou un service, **vous devez refaire ce check vous-même**.

### 5.2 Calcul de la TVA

Le contrôleur backend utilise `$product->getTaxesRate()` qui retourne le taux **par défaut** (sans contexte d'adresse). Pour un calcul plus précis selon l'adresse de livraison de l'abonnement, il faut soit :

1. Modifier le contrôleur pour qu'il récupère l'adresse depuis le fingerprint :

```php
$subscriptionApi = new Subscription($this->context->link);
$subData = $subscriptionApi->getOne($uuid);
$fingerprint = CartFingerprintData::extractDatas($subData['body']['external_fingerprint']);
$address = new Address((int) $fingerprint->id_address_delivery);
$taxRate = (float) $product->getTaxesRate($address) / 100;
```

2. Ou laisser l'API Ciklik recalculer la TVA elle-même (le champ `tax` est optionnel — si absent, l'API reprend la TVA du premier produit existant de l'abonnement, cf. OpenAPI spec).

### 5.3 Cohérence avec la fréquence en cours

Si l'abonnement contient déjà un produit avec une fréquence donnée, l'ajout d'un produit avec une fréquence différente peut créer une incohérence côté Ciklik. L'API ne vérifie pas cette cohérence : c'est au front de filtrer les produits compatibles.

**Recommandation** : récupérer la fréquence en cours via `SubscriptionData::external_fingerprint->frequency_id` et ne proposer que des produits ayant cette fréquence dans `ciklik_product_frequency`.

### 5.4 Mode attributes : choix de la combinaison

En plus du check `CiklikSubscribable::isSubscribable()` vu en 5.1 (qui vérifie qu'un produit est subscribable au niveau produit), le mode attributes ajoute une subtilité supplémentaire : la fréquence est portée par la **combinaison** (`id_product_attribute` → `ciklik_frequencies.id_attribute`), pas par le produit.

Donc dans ce mode, il faut aussi s'assurer que la **combinaison choisie** correspond à une combinaison subscribable (ayant une fréquence associée dans `ciklik_frequencies`). Trois stratégies possibles :

1. **Demander au client de choisir la combinaison** (UI avec sélecteur de déclinaison)
2. **Utiliser la combinaison par défaut** du produit (`Product::getDefaultAttribute($idProduct)`) puis vérifier qu'elle est subscribable
3. **Filtrer côté serveur** pour ne proposer que les combinaisons subscribable via `CiklikCombination::getSubscribableCombinations()` (ou équivalent selon votre implémentation)

En **mode frequency**, il n'y a pas cette subtilité : la fréquence s'applique à l'abonnement entier, donc n'importe quelle combinaison du produit fonctionne tant que le produit est dans `ciklik_product_frequency`.

### 5.5 Erreurs côté API à afficher

Le contrôleur propage déjà les messages d'erreur de l'API. Pensez à les afficher au client via le champ `message` de la réponse JSON. Exemples typiques :

| Code | Message API | Action côté front |
|------|-------------|-------------------|
| 422 | "The subscription is not active" | Désactiver la fonctionnalité pour les abos pausés |
| 422 | "Product with customization hash not allowed" | Filtrer en amont |
| 422 | "Validation failed: name is required" | Bug : revoir la construction du payload |
| 401 | "Unauthorized" | Token API expiré côté Ciklik |

### 5.6 Sécurité

Le contrôleur applique déjà :
- Vérification CSRF (`isTokenValid()`)
- Vérification de propriété de l'abonnement
- Validation du format du produit

**Ne pas contourner** ces vérifications dans une implémentation custom. Si vous créez un nouveau contrôleur (ex : autocomplete), n'oubliez pas `$this->auth = true` et la validation des entrées.


