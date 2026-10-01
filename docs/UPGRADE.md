# Guide de mise à jour — module Ciklik

## 1.25.0 : avertissement de paiement en attente

- Rien à faire : ni réglage, ni table, ni hook à enregistrer (`displayShoppingCartFooter` l'est depuis 1.23.0).
- Un client connecté dont un renouvellement a été refusé voit le montant dû et « Régler ce paiement » au panier,
  dans le moyen de paiement Ciklik et dans « Mes abonnements ». Le bouton passe par le contrôleur `pendingpayment`
  du module, qui redirige vers le lien de reprise Ciklik.
- Compatibilité déclarée jusqu'à PrestaShop 9.2 (`9.2.99`).
- `account.tpl` surchargé dans le thème : y inclure `hook/displayPendingPayment.tpl` comme le template du module.
- Journaux de débogage activés avant la 1.25.0 : purger `var/logs/ciklik-*`, qui peuvent contenir des liens de reprise en clair (masqués depuis).

## 1.24.0 : changement de point relais côté client, sécurité de Mes abonnements

### Pour la plupart des marchands : rien à faire

- La mise à jour crée la table `ciklik_relay_search_quota`, compteur des recherches de relais.
- Dans « Mes abonnements », un abonnement livré en point relais propose « Changer de point relais »
  à la place de « Changer l'adresse », qui reste pour la livraison à domicile et quand aucun relais
  ne peut être proposé. Un point relais choisi pour les prochaines livraisons s'affiche comme
  adresse de livraison de l'abonnement. La fonction est active dès la mise à jour, sans réglage.
- Recherche par adresse disponible pour Mondial Relay, Colissimo et DPD France (adresses
  françaises), y compris sous PrestaShop 1.7. Chronopost : relais connus en back-office seulement,
  recherche désactivée dans cette version.
- « Ajouter à l'abonnement » (upsell) n'est plus proposé pour un produit inactif, visible nulle
  part, non disponible à la commande, réservé à d'autres groupes de clients, ni en mode catalogue
  (les packs étaient déjà exclus) ; le serveur refuse les mêmes ajouts. Le retrait d'un upsell
  reste possible dans tous les cas.

### Si vous avez SURCHARGÉ des templates du module dans votre thème

Toutes les actions de « Mes abonnements » (arrêt, reprise, report, date, fréquence, adresse,
produits, upsell, relais) exigent désormais un **POST portant le jeton `token`**, y compris les
appels AJAX, et quel que soit le réglage « Augmenter la sécurité du front office »
(`PS_TOKEN_ENABLE`) de PrestaShop. Une URL `/ciklik/subscription/{uuid}/{action}` appelée en GET
est refusée, sauf l'affichage de la page `/relay`.

| Template surchargé | À faire |
|--------------------|---------|
| `actions/ListUpsellSubscriptionAndDelete.tpl` | ajouter `formData.append('token', '{$token\|escape:'javascript':'UTF-8'}');` avant le `fetch` |
| `actions/chooseUpsellSubscription.tpl` | ajouter `formData.append('token', '{$ciklik_token\|escape:'javascript':'UTF-8'}');` avant le `fetch` |
| `account.tpl` et les modales `actions/*.tpl` | garder `<input type="hidden" name="token" value="{$token\|escape:'html':'UTF-8'}">` dans chaque formulaire POST |
| `account.tpl` | pour un abonnement en relais, inclure `actions/changePickupPoint.tpl` à la place de `actions/changeDeliveryAddress.tpl`, et afficher `$next_delivery_relays` (voir le template du module) |

Le jeton attendu est celui que PrestaShop assigne déjà (`Tools::getToken(false)`, soit `{$token}`
dans « Mes abonnements » et `{$static_token}` ailleurs) : une surcharge qui le transmet déjà n'a
rien à changer.

## 1.20.0 — Compatibilité PHP 7.0 / PrestaShop 1.7.0 et suppression de Carbon

### Pour la quasi-totalité des marchands : rien à faire

La mise à jour est transparente : remplacement de fichiers, **aucune migration de base de données ni de configuration**. L'enregistrement des hooks s'adapte automatiquement à la version de PrestaShop.

### ⚠️ Si vous avez SURCHARGÉ des templates du module dans votre thème

Concerne typiquement le template d'abonnements / engagement
(`views/templates/front/account.tpl`) ou tout template / code personnalisé manipulant
les dates d'abonnement.

Le module **n'embarque plus la librairie Carbon**. Les dates sont désormais des
`\DateTimeImmutable` **natifs** PHP. Les objets suivants ont changé de type
(Carbon → natif) :

- `$subscription->created_at`
- `$subscription->end_date`
- la valeur retournée par `PrestaShop\Module\Ciklik\Helpers\IntervalHelper::addIntervalToDate()`

> `$subscription->next_billing` ainsi que les dates de commande et de transaction
> étaient déjà des objets natifs — elles ne changent pas.

Les méthodes **spécifiques à Carbon** ne sont plus disponibles sur ces objets et
provoquent une erreur fatale `Call to undefined method`. Remplacez-les dans vos
surcharges :

| Avant (Carbon) | Après (natif) |
|----------------|---------------|
| `->toImmutable()` | à supprimer (l'objet est déjà immutable) |
| `->isPast()` | `->getTimestamp() < $smarty.now` |
| `->isFuture()` | `->getTimestamp() > $smarty.now` |
| `->toDateString()` | `->format('Y-m-d')` |
| `->diffForHumans()`, `->copy()`, `->addX()` / `->subX()` | pas d'équivalent natif — utiliser `\DateTimeImmutable` standard / logique PHP |
| `->format('...')` | inchangé (méthode native) |

**Exemple** (extrait de `account.tpl`, déjà corrigé côté module) :

```smarty
{* Avant *}
{if IntervalHelper::addIntervalToDate($subscription->created_at->toImmutable(), $interval, $count)->isPast()}

{* Après *}
{if IntervalHelper::addIntervalToDate($subscription->created_at, $interval, $count)->getTimestamp() < $smarty.now}
```

Si vous n'avez surchargé aucun template, ignorez cette section.

### Autres changements (sans impact marchand)

- Plancher PHP abaissé à **7.0** (variante raw).
- PrestaShop pris en charge dès **1.7.0** (variante raw) / **1.7.6** (variante with-addon).
- Enregistrement des hooks recalculé selon la version (hooks de repli legacy pour
  les versions < 1.7.7) — transparent à l'installation comme à la mise à jour.
