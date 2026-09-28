# Changement de point relais côté client (Mon compte)

Plan d'implémentation, livré en 1.24.0 avec les corrections des revues de code et de sécurité.
Prolonge la fonctionnalité back-office livrée en 1.23.0 (`ciklik_delivery_override`, drivers de
`DeliveryModuleManager`, recherche `CiklikRelaySearch`).

## 1. Objectif et règle d'interface

Offrir au client, page « Mes abonnements » de son compte, le changement du point relais des
prochaines livraisons, avec la même fondation que le marchand en back-office.

Règle d'interface : un seul lien de changement par abonnement. Quand l'abonnement est livré en
point relais et qu'au moins un relais peut être proposé (recherche par adresse ou relais déjà
utilisés proposables), « Changer de point relais » remplace « Changer l'adresse », au même
emplacement et dans le même aspect (texte souligné dans la couleur du texte, colonne « Livré à »
de `views/templates/front/account.tpl`). Sinon (livraison à domicile, rien à
proposer), « Changer l'adresse » reste comme avant 1.24.0 (`CiklikSubscriptionRelay::changeLinkType`).

Adresse affichée : quand une surcharge est enregistrée et qu'elle diffère de l'adresse de
l'empreinte, la colonne « Livré à » montre ce point relais (nom et adresse) sous la mention
« Adresse de la prochaine livraison », à la place de l'adresse de l'empreinte. La surcharge est lue
comme au rebill (`CiklikDeliveryOverride::get`), en base, sans appel à l'API
(`CiklikSubscriptionRelay::nextDeliveryRelay`).

Deux décisions de cadrage :

- Pas de réglage marchand dans l'interface. Changer de point relais est le comportement attendu
  d'un abonnement livré en relais ; la fonction est active dès la mise à jour du module, sans clé
  cachée pour la couper. Le seul script d'upgrade (`upgrade-1.24.0.php`) crée la table du quota de
  recherche.
- Pas de saisie de numéro de relais. Personne ne connaît son numéro : le client choisit
  uniquement parmi des relais que le serveur lui a présentés (résultats de recherche ou relais
  déjà utilisés), jamais par un identifiant tapé.

## 2. Détection « abonnement en relais » (aucune logique nouvelle)

Même résolution que le hook BO (`DisplayOrderSubscriptionInfoHookController::buildRelayOverrideVars`) :

1. `SubscriptionData->external_fingerprint->id_carrier_reference` (transporteur des rebills,
   pas celui d'une commande passée) ;
2. `Carrier::getCarrierByReference()` → `external_module_name` en minuscules ;
3. module dans `CiklikDeliveryOverride::SUPPORTED_MODULES` ;
4. `DeliveryModuleManager::carrierSupportsRelay($module, $carrier)` : exclut les offres domicile
   des cinq modules (Chrono13/18, DPD Predict/Classic, Colissimo domicile, MR domicile, GLS Chez Vous).

Cette chaîne est portée par `CiklikSubscriptionRelay::resolveCarrier()` et appelée depuis
`account.php` pour chaque abonnement listé ; `CiklikSubscriptionRelay::hasChoices()` décide
ensuite si le lien est affiché.

## 3. Choix par transporteur

Les sources des modules officiels ont été étudiées (miroirs publics, WSDL en ligne, extensions
officielles des mêmes éditeurs pour d'autres plateformes, appels réels aux services) pour savoir
quelle recherche de relais par adresse est réalisable côté serveur avec ce que le marchand a déjà
configuré. `CiklikRelaySearch::supportsSearch()` reste la seule source de vérité, pour le BO comme
pour le front.

| Module | Recherche par adresse | Fondement | Relais connus |
|---|---|---|---|
| `mondialrelay` | oui, WSI4 (SOAP), inchangée | enseigne + clé privée du module voisin, `ext-soap` | oui |
| `colissimo` | oui, REST `findRDVPointRetraitAcheminement`, inchangée | compte + mot de passe du module voisin | oui |
| `dpdfrance` | oui, nouvelle : `GetPudoList` du service Pickup MyPudo (HTTP GET, XML) | l'URL du service vient de `DPDFRANCE_RELAIS_MYPUDO_URL` (module installé), seul l'hôte `mypudo.pickup-services.com` est accepté ; le couple `carrier=EXA` + clé est celui de DPD France auprès de Pickup, identique chez tous les marchands et codé en dur dans les modules officiels PrestaShop 5.x, WooCommerce et Magento ; appel réel vérifié le 23/09/2026 ; adresse de livraison en France uniquement | oui |
| `chronopost` | codée mais désactivée en 1.24.0 (`CHRONOPOST_SEARCH_ENABLED = false`) : `recherchePointChronopostInter` de `PointRelaisServiceWS` (SOAP) | numéro de contrat + mot de passe Chronotrace `CHRONOPOST_GENERAL_ACCOUNT` / `CHRONOPOST_GENERAL_PASSWORD`, ceux que le module envoie au même service pour sa carte ; `ext-soap` ; la route et le parsing ont été vérifiés (erreur 1500 avec des identifiants factices), un appel réussi reste à observer avec un contrat réel avant de l'activer | back-office seulement : identifiant seul (le module ne stocke que `id_pr`), relais sans nom jamais proposés au client |
| `nkmgls` | non | module commercial fermé : le web service GLS existe (`ParcelShopSearch` legacy ou ShipIT) mais les clés de configuration où nkmgls range le login et le mot de passe ne sont pas documentées ; la seule voie propre serait d'appeler la classe interne `Nukium\GLS\Legacy\GlsController` du module, ce qui lie Ciklik à une version fermée | oui (`gls_cart_carrier`) |

Quand la recherche n'est pas disponible (GLS, Chronopost, DPD hors de France, ou credentials
absents pour les autres), la page ne propose que les relais connus du client qu'elle peut
proposer ; s'il n'y en a aucun, le lien « Changer de point relais » n'est pas affiché et l'URL
directe redirige vers « Mes abonnements » avec un message.

Résultats normalisés au même contrat pour les cinq modules : `relay_id`, `name`, `address1`,
`address2`, `zipcode`, `city`, `country_iso`, `latitude`, `longitude` (+ `name2`, `product_code`,
`network` selon le transporteur). Les parseurs DPD (`parseDpdfranceResponse`) et Chronopost
(`parseChronopostResponse`) sont purs et testés hors réseau.

## 4. Parcours

1. Page « Mes abonnements » : pour un abonnement en relais avec au moins un choix possible, lien
   « Changer de point relais » vers `/ciklik/subscription/{uuid}/relay` (route existante
   `module-ciklik-subscription`).
2. Écran de choix (`views/templates/front/relay.tpl`, `{extends file='customer/page.tpl'}`) :
   - relais courant : override du client s'il existe, sinon celui que le clonage utilisera
     (`peekLegacyRelay` avec l'adresse du fingerprint), avec sa provenance ;
   - recherche par adresse (si supportée) : formulaire POST sur la même page, code postal et ville
     préremplis avec l'adresse de livraison de l'abonnement, pays affiché en lecture seule (imposé
     par le serveur) ; résultats en liste à choix unique ;
   - relais connus du client (`getKnownRelays`, les dix derniers utilisés sur des paniers
     commandés, filtrés par `filterSelectable`) en liste à choix unique ;
   - bouton « Confirmer ce point relais » (POST `/ciklik/subscription/{uuid}/saverelay`), lien « Annuler ».
   Aucun JavaScript requis, aucune carte (Leaflet reste réservé au BO) : compatible avec tout thème.
3. Confirmation : l'override est enregistré, journalisé, puis redirection vers « Mes abonnements »
   avec le message « Votre nouveau point relais a bien été enregistré. Il sera utilisé pour vos
   prochaines livraisons. » Liste périmée (jeton de plus d'une heure) : retour à la page relais
   avec un message d'expiration, relais proposés de nouveau.

## 5. Sécurité

- Actions dans `controllers/front/subscription.php` (`relay`, `saverelay`) ; `manage.php` reste
  réservé au back-office.
- `$auth = true` : client connecté, sinon redirection `my-account`.
- Propriété : `validateSubscriptionOwnership($uuid)` existant (id_customer du fingerprint), qui
  conserve le corps de l'abonnement ; `loadRelayContext()` ne travaille que sur ce corps et refuse
  s'il est absent (aucun rechargement sans contrôle), puis revérifie l'id_customer du fingerprint.
- Méthode : `postProcess()` refuse toute méthode autre que POST, sauf l'affichage de la page relais
  en GET (`SubscriptionRequestGuard::isMethodAllowed`) ; valable pour toutes les actions
  d'abonnement, pas seulement le relais.
- CSRF : jeton du client exigé sur tout POST, actions AJAX comprises, quel que soit
  `PS_TOKEN_ENABLE` : `isTokenValid()` est surchargé et compare à temps constant
  `Tools::getToken(false)` au jeton reçu (`SubscriptionRequestGuard::isTokenValid`), là où la
  version de `FrontController` rend toujours vrai quand `PS_TOKEN_ENABLE` est désactivé. En plus,
  un jeton propre aux formulaires relais (`relay_token`, `RelaySelectionSigner::formToken`, HMAC
  de la clé serveur sous le domaine `ciklik-relay`, lié au client, à la boutique et au hash du mot
  de passe) est exigé sur les POST `relay` et `saverelay`.
- `relay_id` : `CiklikDeliveryOverride::isValidRelayId($module, $relayId)` (regex
  `[A-Za-z0-9_-]+` + longueur maximale par transporteur, reprise de `manage.php`, désormais
  partagée par le BO et le front).
- Origine du relais : jamais de payload libre ni de numéro tapé par le client. Chaque option
  sélectionnable (résultat de recherche ou relais connu) porte un jeton HMAC (`RelaySelectionSigner`,
  clé `_COOKIE_KEY_` sous le domaine `ciklik-relay|`, lié au client, au module et à la boutique,
  horodaté `iat` et refusé au-delà d'une heure) calculé côté serveur au rendu, qui ne signe que
  l'identifiant et le payload enregistrable (`CiklikSubscriptionRelay::selectionData`). À la
  confirmation, la décision pure `CiklikSubscriptionRelay::decideSelection()` vérifie le jeton,
  distingue l'expiration du refus, et reconstitue le payload depuis ce jeton. Tout autre choix est
  refusé.
- Relais proposables (`CiklikSubscriptionRelay::filterSelectable`, appliqué avant la signature, au
  test « rien à proposer » du lien et de la page, et de nouveau à la confirmation) : identifiant
  valide pour le module (`isValidRelayId`), nom renseigné (les relais connus Chronopost, réduits à
  leur numéro, ne sont pas proposés), pays du relais égal à celui de l'adresse de livraison des
  rebills quand il est connu (un relais connu sans pays, DPD ou GLS, reste proposable), payload
  conforme aux règles partagées.
- Pays de recherche : celui de l'adresse du fingerprint (pays par défaut de la boutique à défaut),
  jamais saisi ; le formulaire l'affiche en lecture seule sans le soumettre.
- Payload : `CiklikDeliveryOverride::RELAY_PAYLOAD_RULES` (longueurs et `Validate::*`) est la règle
  unique du back-office (`manage.php`, constante devenue alias) et du front (`buildPayload`, qui
  rend null et fait refuser le relais si le payload ne passe pas).
- Limitation des recherches : `RelaySearchQuota`, compteur en base (`ciklik_relay_search_quota`,
  clé client + boutique, fenêtre glissante d'une heure) : 20 appels par client et 1000 par boutique
  (`SHOP_LIMIT`, tous clients confondus, valeurs fixes).
  Lecture, décision et écriture sous un verrou nommé MySQL par boutique (`GET_LOCK`) : deux
  recherches simultanées ne dépassent plus un plafond. `consume()` rend un statut (accordé,
  plafond atteint, indisponible) : un compteur inaccessible refuse la recherche, est journalisé et
  affiché comme service indisponible. Un compteur en cookie se remettait à zéro à la déconnexion.
- Multiboutique : `id_shop` dérivé de la commande d'origine de l'abonnement (première commande
  PrestaShop liée chez Ciklik, contrôlée sur l'id_customer), à défaut boutique d'inscription du
  client puis boutique de la requête ; sans multiboutique, boutique de la requête sans appel à
  l'API. Contexte basculé (`ShopContextSwitch`) le temps des actions relais et restauré avant le
  rendu de la page ; `id_shop` inclus dans le contexte de signature.
- Relais connus : lus sur les seuls paniers commandés (jointure sur `orders`, `id_order` renseigné
  chez Mondial Relay), une ligne par relais triée sur son dernier usage (`MAX(id_cart)`),
  `LIMIT 50` relais, au plus les dix plus récents rendus.
- Transport : Mondial Relay et Chronopost appelés sur un point d'appel https imposé (le WSDL de
  Mondial Relay annonce `http://`), lecture de la réponse SOAP bornée à 8 secondes
  (`default_socket_timeout` abaissé puis restauré). Colissimo et DPD passent par
  `CarrierHttpClient`, client curl du module, et non par Guzzle : sous PrestaShop 1.7, le cœur
  charge Guzzle 5, sans `request()`. https imposé à curl, aucune redirection suivie, certificat
  vérifié, délai de 8 secondes. Colissimo sur l'API REST v2 (`.../rest/v2/pointretrait/...`, JSON),
  DPD France limité à l'hôte `mypudo.pickup-services.com`, jamais `user:pass@`. Toute erreur, y
  compris fatale (Throwable), rend un échec générique journalisé, jamais une erreur 500.
- Réponses AJAX en `application/json` avec `X-Content-Type-Options: nosniff`.
- Aucune exposition des identifiants transporteur : les appels partent du serveur, les réponses
  affichées sont les listes normalisées, les erreurs API sont génériques et les journaux ne
  contiennent que la classe de l'exception (jamais le corps de la requête).
- Journalisation : `PrestaShopLogger::addLog` (objet `CiklikDeliveryOverride`, id client) avec
  client, transporteur, relais et UUID d'abonnement, origine « customer account », comme l'audit BO.

## 6. Réutilisation et factorisation

Réutilisé tel quel : `CiklikDeliveryOverride::save/get`, `CiklikRelaySearch::supportsSearch/searchRelays`,
`DeliveryModuleManager::carrierSupportsRelay/peekLegacyRelay/getKnownRelays`.

Factorisé sans changer le comportement du BO :

- `src/Managers/CiklikSubscriptionRelay.php` (nouveau, statique) :
  - `resolveCarrier(SubscriptionData $subscription)` → `['module' => string, 'carrier' => \Carrier]` ou null ;
  - `getCurrent(int $idCustomer, string $module, int $idAddressDelivery)` → `['source', 'relay_id', 'label']` ou null ;
  - `hasChoices(int $idCustomer, string $module)` → recherche disponible ou relais connus non vides ;
  - `buildPayload(array $relay)` → payload borné aux champs acceptés par les drivers ;
  - `formatLabel(array $relay)`.
  `DisplayOrderSubscriptionInfoHookController::buildRelayOverrideVars` délègue à ce service.
- `CiklikDeliveryOverride::RELAY_ID_MAX_LENGTHS` + `isValidRelayId()` : `manage.php` s'y réfère
  (sa constante devient un alias).
- `CiklikRelaySearch` : deux drivers de plus (`dpdfrance`, `chronopost`), même contrat ; le BO
  (`relay_override.tpl`, `manage.php::handleSearchRelays`) en bénéficie sans modification.
- `CiklikDeliveryOverride::RELAY_PAYLOAD_RULES` + `cleanPayloadValue()` + `isValidPayload()` :
  règle unique du payload pour `manage.php` et `buildPayload()`.
- `CiklikSubscriptionRelay::filterSelectable()` : relais proposables (identifiant, pays, payload).
- `src/Helpers/RelaySelectionSigner.php` (pur) et `src/Helpers/RelaySearchQuota.php` (décision
  pure `decide()` + persistance `consume()`), testables.

## 7. Interface et compatibilité

- `account.php` : assigne `relay_subscriptions` (uuid → url de la page relais) pour les abonnements
  en relais avec un choix possible, et `next_delivery_relays` (uuid → relais de la surcharge) ;
  `account.tpl` inclut `actions/changePickupPoint.tpl` à la place de
  `actions/changeDeliveryAddress.tpl` dans le premier cas, et affiche le relais dans le second.
- Templates `.tpl` surchargeables par le thème (`modules/ciklik/views/templates/front/...`), classes
  Bootstrap communes 4/5 (`form-group mb-3`, `form-check`, `btn`), pas de modale.
- PrestaShop 1.7 à 9 : `Carrier::getCarrierByReference`, `redirectWithNotifications`,
  `isTokenValid`, `Shop::setContext` disponibles sur toute la plage ; pas de Symfony, pas de Twig.
- Un script d'upgrade, `upgrade-1.24.0.php`, crée la table `ciklik_relay_search_quota`
  (`SqlQueries::installRelaySearchQuotaQueries`, aussi jouée à l'installation) sans jamais
  désactiver le module en cas d'échec, comme `upgrade-1.23.0.php`. PrestaShop n'appelle pas de
  méthode `upgradeModule()` sur le module (seuls les scripts `upgrade/` sont joués) : la
  surcharge morte qui promettait de recréer les tables à chaque mise à jour est retirée.

## 8. Traductions

Six langues (`en`, `fr`, `de`, `es`, `it`, `pl`), clés MD5 sur la chaîne anglaise, préfixes :
`relay_` (page), `changepickuppoint_` (lien), `subscription_` (messages du contrôleur).

## 9. Tests

Le dépôt a une suite PHPUnit (`tests/Unit`, stubs `Db`, `Configuration`, `PrestaShopLogger`,
`Shop` et `Tools` réduits dans `tests/bootstrap.php`), sans `Carrier` ni `Context` : les décisions
sont sorties du contrôleur en fonctions pures pour être testées ici ; le chargement du contexte
(API, adresse, transporteur) et les redirections restent dans le contrôleur.

Unitaires :
- `tests/Unit/Helpers/SubscriptionRequestGuardTest.php` : GET réservé à l'affichage de la page
  relais, actions AJAX, refus d'un jeton absent, vide, altéré, d'un autre client ou non chaîne,
  quantité d'upsell de 0 à 9999 ;
- `tests/Unit/Helpers/RelaySelectionSignerTest.php` : signature/vérification, `iat` porté, jeton
  expiré ou daté dans le futur refusé, expiration distinguée du refus (`check()`), jeton signé sans
  le domaine `ciklik-relay` refusé, jeton altéré, jeton d'un autre client, module ou boutique
  refusé, blob sans horodatage refusé, jeton de formulaire lié au hash du mot de passe ;
- `tests/Unit/Helpers/RelaySearchQuotaTest.php` : décision (compteur, plafond client, plafond
  boutique, fenêtre glissante, ligne corrompue), plafond boutique réglable, persistance sous
  verrou (ordre verrou, lectures, écritures, libération), statuts accordé, plafond atteint et
  indisponible (verrou non obtenu, écriture refusée, erreur de base, journalisés) ;
- `tests/Unit/Helpers/ShopContextSwitchTest.php` : bascule du contexte boutique et restauration
  (boutique, groupe, toutes), sans multiboutique rien ;
- `tests/Unit/Managers/CiklikSubscriptionRelaySelectionTest.php` : chaîne de confirmation
  (`decideSelection`) : cas nominal, jeton absent, altéré, expiré, d'un autre client, transporteur
  ou boutique, choix différent du relais signé, pays étranger, choix absent ou invalide ;
- `tests/Unit/Managers/CiklikDeliveryOverrideTest.php` : `isValidRelayId` (regex, longueurs par
  transporteur, module inconnu), `isValidPayload` (champs, longueurs, `Validate::*`, pays),
  `cleanPayloadValue` ;
- `tests/Unit/Managers/CiklikSubscriptionRelayTest.php` : `buildPayload`, `selectionData`
  (identifiant et payload seulement), `filterSelectable` (pays forcé, identifiant, nom, payload),
  `changeLinkType` (un seul lien selon le cas), `relayToDisplay` et `nextDeliveryRelay` (surcharge
  affichée ou non),
  `hasChoices` et `selectableKnownRelays` (pays de livraison, relais proposables seulement),
  `formatLabel`, `formatDistance` (locale) ;
- `tests/Unit/Managers/DeliveryModuleManagerKnownRelaysTest.php` : limite des relais connus (dix
  plus récents, dédoublonnage avant coupe, table absente), paniers commandés seulement, tri par
  dernier usage ;
- `tests/Unit/Managers/CiklikRelaySearchTest.php` : disponibilité par transporteur et par pays
  (DPD France seulement, Chronopost désactivé), liste blanche de l'hôte MyPudo, appels https
  (SOAP et REST), recherche DPD et Colissimo sur un client HTTP simulé (options curl, paramètres,
  aucune redirection suivie, échec générique, erreur fatale convertie), délai de socket restauré,
  parseurs DPD, Chronopost et Colissimo (REST v2, identifiants refusés, faute SOAP) ;
- `tests/Unit/Helpers/CarrierHttpClientTest.php` : options curl (GET encodé, POST JSON, https
  seulement, aucune redirection, certificat vérifié, délai), refus d'une adresse non https, échec
  réseau réel en local sans fuite de la requête ;
- `tests/Unit/Helpers/UpsellEligibilityTest.php` : règle commune au bouton d'upsell et à
  `addUpsell` (fonction désactivée, produit inconnu, inactif, visible nulle part, non disponible à
  la commande, mode catalogue, groupe non autorisé, pack, cas nominal) ;
- `tests/Unit/Api/CiklikApiResponseHandlerTest.php` : refus connus de l'API traduits, texte de
  l'API réservé aux clients francophones, réponse non JSON sans TypeError ;
- `tests/Unit/Data/SubscriptionDataTest.php` : ligne personnalisée visée par une modification ;
- `tests/Unit/Php70CompatibilityTest.php` : aucun type inconnu de PHP 7.0 dans le code livré.

Scénario manuel (PS 1.7.8 et 8.x, thème Classic ; Hummingbird pour le rendu) :
1. Abonnement domicile : lien « Changer l'adresse » inchangé.
2. Abonnement Mondial Relay, Colissimo ou DPD (France) avec credentials : lien « Changer de point
   relais » seul, à la place de « Changer l'adresse » ; page avec relais courant, recherche
   préremplie, résultats, relais connus ; confirmation d'un résultat → message de succès, relais
   choisi affiché dans « Livré à » (« Adresse de la prochaine livraison »), override visible en BO
   (badge « Défini manuellement »), rebill suivant sur le nouveau relais.
3. Abonnement GLS avec des relais connus commandés : pas de recherche ; choix parmi les relais
   connus.
4. Abonnement GLS sans relais connu, Chronopost, ou DPD/Colissimo sans credentials ni historique :
   seul « Changer l'adresse » ; l'URL `/relay` redirige vers « Mes abonnements » avec un message.
5. Jeton falsifié (modifier `relay_data[...]` ou `relay_choice` dans le DOM) → refus ; page
   laissée ouverte plus d'une heure → retour à la page relais avec un message d'expiration.
6. UUID d'un autre client → « Vous n'avez pas la permission ... ».
7. Vingt et une recherches dans l'heure → message de limite, y compris après déconnexion et
   reconnexion (compteur en base).
8. Credentials MR retirés → recherche masquée, relais connus toujours proposés.
9. Recherche DPD depuis le BO (page commande) : mêmes résultats que côté client.
10. Toute action d'abonnement appelée en GET, ou en POST sans jeton : refusée.

## 10. Risques et points ouverts

- Portée de l'override : clé `(client, transporteur)`, partagée entre les abonnements d'un même
  client sur le même transporteur (limite v1 assumée en 1.23.0) ; la page l'indique.
- DPD : la clé MyPudo est celle du module officiel 5.x (dernière version publiée en source) ; le
  module 6.x n'est diffusé qu'en binaire Addons. Si DPD change de clé, la recherche tombe en
  erreur générique et la page se replie sur les relais connus.
- Chronopost : recherche désactivée en 1.24.0 tant qu'un appel réussi n'a pas été observé avec
  un contrat réel. Clés de configuration confirmées sur les versions 3.x et le guide 7.6.2 ; sur
  une boutique multi-contrats (>= 4.7.0) le premier contrat est utilisé. Les relais connus ne
  portent que l'identifiant, donc ne sont pas proposés au client :
  `rechercheDetailPointChronopost` permettrait de les enrichir plus tard.
- GLS : relais connus seulement tant que la configuration de nkmgls n'est pas lue sur une boutique
  réelle (`SELECT name FROM ps_configuration WHERE name LIKE 'GLS_%'`).
- Pas de « retour à l'automatique » côté client : le client choisit un autre relais, le marchand
  peut réinitialiser en BO.
- Relais fermé au moment du rebill : même comportement que le BO (échec à l'étiquetage).
