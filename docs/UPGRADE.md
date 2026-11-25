# Guide de mise à jour — module Ciklik

## 1.29.0 : recherche de points relais Chronopost, changement de fréquence en back-office, configuration déclarée

### Recherche de points relais Chronopost

- Rien à faire pour garder le comportement actuel : la recherche de points relais Chronopost reste éteinte.
- Pour l'activer : configuration du module, bloc « Points relais » (affiché quand le module Chronopost est installé),
  « Recherche de points relais Chronopost » sur Oui (clé `CIKLIK_CHRONOPOST_SEARCH_ENABLED`, par boutique en
  multiboutique). Une modification de `CiklikRelaySearch.php` faite à la main pour l'activer n'est plus nécessaire.
- Les versions récentes du module Chronopost (plusieurs contrats) sont prises en charge : le contrat utilisé est celui
  de l'offre relais du transporteur de l'abonnement, sinon celui d'une autre offre relais, sinon le premier contrat
  configuré. Les anciennes versions (contrat unique) restent prises en charge. Comptes « Petits pros » : pas de
  recherche.
- La requête de recherche suit celle de la carte du module Chronopost : code produit de l'offre, commerces seulement
  pour les offres ToShop, mode de dépôt réglé dans le module sinon, service France pour la France et Monaco.
- La ville est facultative : avec le code postal seul, la recherche se fait autour du code postal. La ville est
  préremplie depuis l'adresse de livraison de l'abonnement ; si elle ne correspond pas au code postal saisi (aucun
  relais de ce code postal trouvé autour d'elle), le code postal l'emporte.
- Recherche depuis la fiche commande du back-office : mêmes contrôles que dans « Mes abonnements » (code postal
  obligatoire de 12 caractères au plus, ville de 64 caractères au plus, validés par PrestaShop) et même plafond, compté
  par employé (20 recherches par heure, dans le plafond de 1 000 par boutique). Le contrat Chronopost utilisé est celui
  de l'offre du transporteur de la commande consultée.
- Après activation, faire une recherche depuis « Mes abonnements » sur un abonnement livré en point relais. En cas
  d'échec, le client voit « La recherche de points relais est momentanément indisponible » et le journal de PrestaShop porte une ligne
  `CiklikRelaySearch[chronopost]` (sans identifiants) : éteindre le réglage.

### Changement de fréquence et actions du back-office

- Rien à faire. Sur la fiche commande du back-office, le bloc Ciklik propose « Modifier la fréquence » de l'abonnement,
  avec les mêmes choix que « Mes abonnements », même si l'option client est désactivée. La date de la prochaine
  commande ne change pas.
- Abonnement désactivé : le changement de fréquence est désormais masqué et refusé, en back-office comme dans « Mes
  abonnements ». Réactiver l'abonnement avant de changer sa fréquence.
- Mode fréquences, abonnement à plusieurs produits : seules les fréquences proposées pour chacun des produits sont
  proposées (auparavant, celles du premier produit) ; un autre choix est refusé. Un produit sans fréquence ne laisse
  aucun choix.
- Mode déclinaisons : chaque produit passe à sa déclinaison à la nouvelle fréquence, au prix de cette déclinaison ; le
  changement est refusé si un produit n'en a pas, ou s'il est personnalisé.
- Le mode d'un abonnement est lu dans son contenu : un abonnement créé en mode déclinaisons le reste si la boutique
  passe en mode fréquences, et inversement.
- Droits du back-office : désactiver, réactiver, changer la date du prochain paiement et changer la fréquence demandent
  le droit de modifier les commandes (Paramètres avancés > Équipe > Permissions, onglet Commandes), comme le
  changement de point relais. L'abonnement doit être celui de la commande consultée et appartenir à son client, sinon
  « Accès refusé ». Chaque action et chaque échec sont journalisés (`CiklikSubscription`, avec l'employé).
- Messages d'erreur : dans « Mes abonnements », un refus de Ciklik affiche un message générique traduit, plus le texte
  brut de l'API ; la cause (code HTTP, champs en erreur) est écrite dans le journal de PrestaShop. En back-office, le
  motif de Ciklik reste affiché à l'employé.
- Pause et réactivation dans « Mes abonnements » : le message de réussite ne s'affiche plus quand Ciklik refuse la
  demande, ne répond pas ou renvoie une réponse illisible. Le client voit « Votre abonnement n'a pas pu être suspendu »
  (ou réactivé), l'abonnement reste dans son état et la cause est journalisée. La date du prochain paiement, le report
  d'une livraison et le changement d'adresse n'annoncent plus non plus de réussite sur une réponse illisible.
- Ciklik injoignable (connexion refusée, délai dépassé) : « Mes abonnements » et ses actions (date, report, adresse,
  fréquence, produits) affichent un message traduit au lieu d'une page d'erreur, rien n'est annoncé comme fait et la
  cause est journalisée ; la fiche produit, le paiement, le retour de paiement et la fiche commande du back-office
  s'affichent sans les éléments Ciklik, l'enregistrement d'un produit aboutit (« Renvoyer à Ciklik »). Thème qui surcharge `views/templates/front/account.tpl` : `$subscriptions_unavailable` masque « Vous
  n'avez pas d'abonnement » quand la liste n'a pas pu être lue.
- Thème qui surcharge `views/templates/front/actions/changeInterval.tpl` : la liste vient désormais de
  `$subscription->frequency_choices` (clé = valeur à envoyer). Une ancienne surcharge continue de fonctionner, mais un
  choix qui ne vaut pas pour tous les produits est refusé.

### Lecture des déclinaisons et configuration déclarée à Ciklik

- Rien à faire. La mise à jour ajoute à la clé webservice du module (« Ciklik Webservice ») le droit de lecture des
  déclinaisons (ressource `combinations`, méthodes GET et HEAD). Ciklik y lit le nom et la référence d'une déclinaison
  quand un produit est ajouté à un abonnement depuis son administration. Les autres droits de la clé ne sont pas
  modifiés, y compris ceux ajoutés à la main.
- Si le droit n'a pas pu être ajouté (fichiers du module remplacés sans passer par la mise à jour, base en lecture
  seule), le journal de PrestaShop porte une ligne `Ciklik upgrade 1.29.0` ; enregistrer la page de configuration du
  module refait l'ajout (il le refait aussi si le droit a été retiré à la main). Sans ce droit, les déclinaisons sont
  proposées par leur seul identifiant.
- À la mise à jour et à chaque enregistrement de la configuration, le module déclare à Ciklik ses réglages (métadonnée
  `module_config` de `PUT /whoiam`) : mode fréquences, base de la remise de fréquence, options de « Mes abonnements »,
  engagement, versions de PrestaShop et de PHP, multiboutique, droit `combinations`. Aucun jeton, aucune clé, aucun
  texte libre n'y figure. En multiboutique, ce sont les réglages du contexte de l'enregistrement. Un échec de l'appel
  ne bloque ni la mise à jour ni l'enregistrement.
- Vérification : dans Paramètres avancés, Webservice, la clé « Ciklik Webservice » porte `combinations` en lecture.

## 1.28.0 : aide au paramétrage des abonnements

- Rien à faire : la mise à jour crée un onglet caché du back-office (`AdminCiklikProductTransform`), sans table ni hook.
- Mode fréquences : « Gestion des Fréquences » propose « Activer des fréquences par catégorie ». Aperçu, puis ajout des
  fréquences choisies aux produits actifs de la catégorie (et de ses sous-catégories, case cochée par défaut). Packs,
  produits virtuels et inactifs exclus ; rien n'est jamais retiré.
- Mode déclinaisons : sur la fiche produit, onglet Modules, « Transformer en abonnement ». Les déclinaisons existantes
  reçoivent « Achat en une fois » sans changer d'identifiant, une déclinaison abonnement est créée par fréquence (mêmes
  références, EAN, impact prix, images, fournisseurs), avec une copie ponctuelle du stock de la même combinaison. Un
  produit simple reçoit d'abord une déclinaison « Achat en une fois » qui reprend son stock. Plafond : 1 000 déclinaisons.
  Pas de remise : les prix abonnement se règlent ensuite dans l'onglet Déclinaisons.
- « Réaligner le stock » recopie à la demande le stock de chaque « Achat en une fois » sur ses déclinaisons abonnement.
- L'envoi du catalogue à Ciklik part par lots de 100 variantes (une seule requête en dessous de 100, comme avant).
- La transformation avance par étapes de 50 déclinaisons au plus, avec une barre de progression : chaque création
  déclenche les hooks des autres modules (environ une seconde par déclinaison avec `ps_eventbus`, PrestaShop
  Accounts). Chaque étape est entière ou nulle. Page fermée ou étape en échec : la fiche propose « Reprendre » (rien
  n'est recréé, ce qui manque se déduit des attributs présents) ou « Annuler la transformation » (retire seulement les
  déclinaisons abonnement créées et l'attribut « Achat en une fois » ajouté ; les déclinaisons d'origine restent).
  Le catalogue part vers Ciklik une seule fois, à la fin. L'état d'une transformation en cours est gardé en
  configuration (`CIKLIK_TRANSFORM_<produit>`), effacé à la fin ou à l'annulation.
- Droits : les actions du panneau demandent le droit de modifier les produits (Paramètres avancés > Équipe >
  Permissions), rien de plus.
- Multiboutique : la transformation, son annulation, le réalignement du stock et le renvoi à Ciklik demandent le
  contexte d'une seule boutique (le stock est posé dans la boutique choisie). En contexte « Toutes les boutiques » ou
  groupe, le panneau le signale et les actions sont refusées. Les déclinaisons abonnement sont créées dans la boutique
  choisie seulement : une transformation inachevée se reprend ou s'annule depuis cette boutique.
- « Renvoyer à Ciklik » : quand l'envoi d'un produit à Ciklik échoue (en tout ou partie), la fiche le signale et
  propose de renvoyer le produit. Le bouton disparaît après un envoi réussi, depuis ce bouton ou en enregistrant la
  fiche.
- Bon à savoir : « Annuler la transformation » retire toutes les déclinaisons du produit qui portent « Abonnement »,
  puisque le produit n'en avait aucune avant. Une déclinaison abonnement ajoutée à la main pendant une transformation
  inachevée est donc retirée elle aussi. Terminer ou annuler la transformation avant d'ajouter des déclinaisons à la
  main.
- Ciklik injoignable depuis la boutique (réseau, adresse de l'API erronée) pendant un renouvellement ou une mise à jour
  de paiement : la boutique répond 503 avec `{"error": "The shop could not reach Ciklik"}` au lieu d'une erreur
  fatale, le journal PrestaShop en donne la cause, et Ciklik réessaie plus tard.

## 1.27.1 : messages de connexion plus clairs, journal de débogage allégé

- Rien à faire. À l'enregistrement du jeton d'API, « Connexion réussie » ne s'affiche plus que si Ciklik a accepté la
  déclaration de la boutique ; sinon un message d'erreur traduit s'affiche et ni le jeton ni l'hôte ne sont enregistrés :
  - adresse de boutique différente de celle déjà enregistrée chez Ciklik : « L'adresse de votre boutique a changé. Pour
    des raisons de sécurité, Ciklik doit valider ce changement : contactez le support Ciklik. » Une fois le changement
    validé par Ciklik, enregistrer à nouveau le jeton ;
  - autre échec (jeton refusé, Ciklik injoignable) : « La connexion à Ciklik a échoué. Vérifiez votre jeton d'API puis
    réessayez ; si le problème persiste, contactez le support Ciklik. »
- Vider le champ du jeton reste possible.
- Journal de débogage allégé.
- Compatibilité inchangée : PrestaShop 1.7.0 à 9.2.

## 1.27.0 : email d'un client changé dans la boutique

- Rien à faire : la mise à jour enregistre deux hooks (`actionObjectCustomerUpdateBefore`, `actionObjectCustomerUpdateAfter`).
- Quand un client qui a déjà commandé par Ciklik change d'adresse email (espace client, back-office, import CSV,
  webservice), le module le signale tout de suite à Ciklik. Seul l'identifiant du client part, jamais l'adresse : Ciklik
  relit le client dans la boutique par le webservice, comme à une commande, puis emails Ciklik, Klaviyo et webhooks
  suivent la nouvelle adresse (en général dans la minute). Le webservice de la boutique doit donc rester joignable,
  comme pour les commandes et les renouvellements.
- Avant, Ciklik ne reprenait l'adresse qu'à la commande ou au renouvellement suivant, ce qui reste le filet de secours.
- En multiboutique, le signal part avec la clé de la boutique de la dernière commande Ciklik du client.
- Jamais bloquant : appel limité à 2 secondes, aucune erreur montrée au client ; un échec est noté dans le journal de
  PrestaShop (Paramètres avancés > Journaux) et Ciklik reprend l'adresse à la commande suivante. Après un échec, ou
  6 secondes d'appels cumulés, les autres clients de la même requête (import) ne sont plus signalés.
- Ciklik garde ses règles : une adresse invalide, ou déjà portée par un autre client du site, n'est pas reprise.
- Non couvert : un module tiers qui écrit l'email directement en base, sans passer par l'objet Customer ; Ciklik le
  reprend alors à la commande suivante.
- Nécessite la plateforme Ciklik à jour (route `PUT customers/email`) ; sans elle, l'appel est refusé et journalisé, rien d'autre.
- Compatibilité inchangée : PrestaShop 1.7.0 à 9.2.

## 1.26.0 : paiement PayPal en attente, option PayPal

- Rien à faire : la mise à jour crée l'état de commande « En attente de paiement (Ciklik) » (non payé, non validé, sans
  facture ni mail ; jamais supprimé) et annonce à Ciklik que le module traite la confirmation de paiement.
- Une commande réglée par prélèvement bancaire PayPal, pas encore encaissé, est créée dans cet état. Quand Ciklik reçoit la
  réponse de PayPal, il appelle `ciklik/gateway/payments` (même authentification que les autres requêtes) : paiement
  encaissé, la commande passe à l'état payé configuré (le paiement est ajouté avec sa référence de transaction, une seule
  fois) ; paiement refusé, elle passe à « Erreur de paiement », sans annulation ni remise en stock (l'abonnement Ciklik reste
  actif, à vous de régulariser). Une commande dont l'état a déjà été changé n'est jamais modifiée.
- Si la création de l'état échoue, les commandes sont créées comme avant et l'erreur est dans le journal de PrestaShop
  (Paramètres avancés > Journaux).
- La confirmation et le détail de commande affichent « paiement en cours de confirmation » tant que la commande est dans cet état.
- Option de paiement PayPal : nouveau gabarit `paymentOptionPaypalVault.tpl` (compte PayPal, prochains prélèvements,
  moyen modifiable) et logo PayPal. Surcharge possible depuis le thème.
- Protection : pour un paiement en attente, `validation` refuse une commande Ciklik liée à un autre panier que le panier
  courant ; un panier modifié après le paiement donne une commande en « Erreur de paiement ».
- L'état de création d'abonnement (option du back-office) ne remplace plus jamais « Erreur de paiement ».
- L'option PayPal n'est plus proposée pour un panier à 0 € (PayPal refuse un paiement nul).
- Compatibilité inchangée : PrestaShop 1.7.0 à 9.2.
- Gabarits `displayPaymentReturn.tpl` ou `displayOrderDetail.tpl` surchargés dans le thème : reprendre le paragraphe `payment_pending`.

## 1.25.0 : avertissement de paiement en attente

- Rien à faire : ni réglage, ni table, ni hook à enregistrer (`displayShoppingCartFooter` l'est depuis 1.23.0).
- Un client connecté dont un renouvellement a été refusé voit le montant dû et « Régler ce paiement » au panier,
  dans le moyen de paiement Ciklik et dans « Mes abonnements ». Le bouton passe par le contrôleur `pendingpayment`
  du module, qui redirige vers le lien de reprise Ciklik.
- Compatibilité déclarée jusqu'à PrestaShop 9.2 (`9.2.99`).
- `account.tpl` surchargé dans le thème : y inclure `hook/displayPendingPayment.tpl` comme le template du module.
- Journaux de débogage activés avant la 1.25.0 : purger `var/logs/ciklik-*`, qui peuvent contenir des liens de reprise en clair (masqués depuis).
- Correctif : au renouvellement, le relais repris pour Colissimo, DPD, GLS et Chronopost est celui de la commande précédente livrée à la même adresse (et non plus de la dernière commande du client).

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
