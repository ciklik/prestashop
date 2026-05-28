# Guide de mise à jour — module Ciklik

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
