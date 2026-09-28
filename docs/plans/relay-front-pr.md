# PR : 1.24.0, changement de point relais côté client

Branche `fix/subscription-product-quantity` (porte `feat/relay-front`), cible `master`. Pas encore poussée.

## Titre

feat: 1.24.0, changement de point relais depuis Mes abonnements et corrections des produits d'abonnement

## Description

Prolonge le changement de point relais en back-office (1.23.0) jusqu'au client : dans « Mes abonnements », quand l'abonnement est livré en point relais et qu'un relais peut être proposé, le lien « Changer de point relais » remplace « Changer l'adresse », qui reste pour la livraison à domicile et quand aucun relais ne peut être proposé. Le relais choisi pour les prochaines livraisons s'affiche comme adresse de livraison de l'abonnement, sous la mention « Adresse de la prochaine livraison ». La page de choix propose le relais courant, une recherche par adresse préremplie avec l'adresse de livraison, et les relais déjà utilisés ; la confirmation enregistre l'override utilisé aux prochains renouvellements, sans toucher aux commandes passées.

Active pour tout abonnement en relais, sans réglage, et sans saisie de numéro : le client ne choisit que parmi des relais présentés et signés par le serveur. Un script d'upgrade (`upgrade-1.24.0.php`) crée la table du quota de recherche, sans désactiver le module en cas d'échec. Plan détaillé dans `docs/plans/relay-front.md`.

Changement de point relais :
- `CiklikRelaySearch` : recherche par adresse pour DPD France (`GetPudoList` du service Pickup MyPudo, hôte `mypudo.pickup-services.com` seulement, adresse française seulement) en plus de Mondial Relay et Colissimo ; Chronopost codée mais désactivée (jamais vue fonctionner avec un contrat réel) ; GLS sans recherche (relais connus seulement)
- `controllers/front/subscription.php` : actions `relay` et `saverelay` ; décision de confirmation pure et testée (`CiklikSubscriptionRelay::decideSelection`) ; jetons de sélection HMAC ne signant que l'identifiant et le payload, liés au client, au transporteur et à la boutique, refusés après une heure (retour à la page avec un message d'expiration) ; pays imposé par l'adresse de livraison ; relais sans nom jamais proposés ; contexte boutique de l'abonnement le temps des appels transporteur, restauré avant le rendu
- `RelaySearchQuota` : 20 recherches par client et 1000 par boutique et par heure (valeurs fixes), compteur en base sous verrou nommé, statut accordé, plafond atteint ou service indisponible (journalisé)
- relais connus lus sur les seuls paniers commandés, triés par dernier usage ; lien et page décidés sur les relais réellement proposables
- transport : Mondial Relay et Chronopost en https imposé, lecture SOAP bornée à 8 secondes ; Colissimo et DPD par un client curl du module (`CarrierHttpClient`), indépendant du Guzzle 5 que PrestaShop 1.7 charge, sans suivi de redirection ; Colissimo sur l'API REST v2 (l'ancienne adresse menait au service SOAP et n'a jamais répondu) ; erreurs fatales attrapées côté client et en back-office

Sécurité de Mes abonnements (toutes actions) :
- toute action refusée hors POST, sauf l'affichage de la page relais ; jeton du client exigé sur tout POST, AJAX et upsell compris, quel que soit `PS_TOKEN_ENABLE` (`isTokenValid()` surchargé : `Tools::getToken(false)` comparé à temps constant)
- upsell : quantité de 0 à 9999, produit et déclinaison vérifiés comme pour l'ajout de produit ; règle d'éligibilité unique (`UpsellEligibility`) pour le bouton de la fiche produit et le serveur : fonction activée, produit actif, visible, commandable, accessible au client, pas un pack
- ligne personnalisée d'un abonnement non modifiable côté serveur
- réponses AJAX en `application/json` avec `nosniff`

Produits d'abonnement :
- external_id du mode attributs accepté (+/- et retrait répondaient « Produit invalide. »), produits personnalisés détectés sur `external_id_with_customizations`, retrait masqué sur le dernier produit
- refus de l'API affichés : refus connus traduits dans les six langues, texte de l'API pour un client francophone, message générique sinon ; réponse non JSON de l'API sans erreur 500

Divers : `assignThemeVariables()` sans `: void` (fatal sous PHP 7.0), `upgradeModule()` morte retirée, `tests/` et `docs/` exclus du zip de release.

## Test

1. Abonnement domicile : « Changer l'adresse » inchangé.
2. Abonnement Mondial Relay, Colissimo ou DPD France (credentials du module voisin en place) : « Changer de point relais » seul, dans l'aspect des autres liens ; après un choix différent de l'adresse de l'empreinte, ce relais s'affiche dans « Livré à » ; page avec relais courant, recherche préremplie, résultats, relais connus. Confirmer un résultat : message de succès, badge « Défini manuellement » sur la commande en BO, rebill suivant sur ce relais.
3. Abonnement GLS avec des relais déjà commandés : pas de recherche ; choix parmi les relais connus.
4. Abonnement GLS sans relais connu, Chronopost, ou DPD/Colissimo sans credentials ni historique : seul « Changer l'adresse » ; `/ciklik/subscription/{uuid}/relay` redirige vers Mes abonnements avec un message.
5. Modifier `relay_data[...]` ou `relay_choice` dans le DOM avant confirmation : refus. Page laissée ouverte plus d'une heure : message d'expiration, page relais de nouveau.
6. UUID d'un autre client : « Vous n'avez pas la permission ... ».
7. Vingt et une recherches dans l'heure : message de limite, y compris après déconnexion et reconnexion.
8. Arrêter, reprendre, sauter, date, fréquence, adresse, quantités, retrait, upsell : fonctionnent ; les mêmes URL en GET, ou en POST sans jeton, sont refusées.

Testé : PHPUnit (371 tests), `php -l` PHP 7.0 et 7.4, requêtes des relais connus et du quota exécutées sur MariaDB et MySQL 8.4 (paniers abandonnés exclus, dernier usage en tête). Recherche DPD et Colissimo exécutée dans PrestaShop 1.7.8.11 (PHP 7.4, Guzzle 5.3.4 du cœur) : 15 relais réels pour DPD, refus d'identifiants factices lu proprement pour Colissimo. Au navigateur sur presta9 (PrestaShop 9.1.5, Hummingbird, Mondial Relay, `PS_TOKEN_ENABLE` actif) : GET forgé, POST sans jeton ou avec un faux jeton, et appels AJAX sans jeton refusés ; +/-, retrait d'un produit, refus du dernier produit (message traduit), upsell ajouté depuis la fiche produit puis retiré, upsell forgé d'un produit non éligible refusé, arrêt, reprise, report, date, adresse ; un seul lien de changement (relais seul avec un choix possible, adresse seule hors relais), dans l'aspect des autres liens ; relais de la surcharge affiché dans « Livré à » quand il diffère de l'empreinte, adresse de l'empreinte sans surcharge ou avec une surcharge identique ; recherche Mondial Relay (15 relais), choix enregistré, choix falsifié refusé, retour au relais d'origine. Abonnements de test remis à l'identique. Restent PrestaShop 1.7.8 et 8.2 en boutique réelle, les transporteurs Colissimo, DPD France et GLS avec des credentials réels (un appel Colissimo réussi reste à observer), et l'expiration d'un jeton de sélection (couverte par les tests unitaires). Hors de cette version : la durée de vie du jeton de formulaire relais (M3).
