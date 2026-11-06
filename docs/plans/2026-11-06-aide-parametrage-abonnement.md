# Aide au paramétrage des abonnements : fréquences par catégorie et « Transformer en abonnement »

Plan d'implémentation, rédigé le 2026-11-06 et révisé le même jour avec les décisions de
Francois (stock cloné à la génération seulement, pas d'action inverse en A, plafond de 1 000
déclinaisons). Aucun code écrit. Version cible : **1.28.0** (dernière version publiée : 1.27.1,
tag et `Ciklik::VERSION` de `master` ; dernier script : `upgrade-1.27.0.php`). La lecture du
code a été faite sur 1.24.0 ; les fichiers que ce plan touche n'ont pas changé d'ici 1.27.1 sur
les points décrits (vérifié par diff), l'implémentation part de `master`.
Ordre recommandé : A (petit, sans risque sur les identifiants) puis B.

Deux aides ponctuelles, pas des règles permanentes :

- **A, mode fréquences** : associer en une fois des fréquences existantes aux produits actifs
  d'une catégorie (et, par défaut, de ses sous-catégories), avec aperçu. Pas d'action inverse.
- **B, mode déclinaisons** : bouton « Transformer en abonnement » sur la fiche produit, qui
  ajoute « Type d'achat » et « Fréquence d'abonnement » aux déclinaisons existantes sans jamais
  changer leur `id_product_attribute`, clone le stock de chaque déclinaison « Une fois » sur ses
  déclinaisons abonnement au moment de la génération, puis ne change plus rien au fonctionnement
  de PrestaShop. Un bouton « Réaligner le stock » refait ce clonage à la demande.

## 1. Ce que le code fait aujourd'hui (constats de lecture)

### Mode fréquences

- Réglage global `CIKLIK_FREQUENCY_MODE` (`Ciklik::CONFIG_USE_FREQUENCY_MODE`).
- Tables (`SqlQueries::installFrequencyModeDatabase`) : `ciklik_frequency` (`name`, `interval`,
  `interval_count`, `discount_percent`, `discount_price`) et `ciklik_product_frequency`
  (`id_product`, `id_frequency`, clé primaire composite, **pas d'`id_shop`**).
- « Abonnement activé » pour un produit = au moins une ligne dans `ciklik_product_frequency`
  (`SubscriptionHelper::isSubscriptionEnabled`). Pas d'autre drapeau.
- La remise d'une fréquence s'applique au panier : `hookActionCartUpdateQuantityBefore` (mode
  fréquences seulement) crée un prix spécifique par panier via
  `CiklikSpecificPrice::createForFrequency()` ; la fiche front l'affiche en calculant le prix
  remisé dans `renderProductSubscriptionOptions()`.
- Fiche produit BO : `hookDisplayAdminProductsExtra` rend `product_subscription_tab.tpl`
  (cases par fréquence) ; `saveProductFrequencies()` supprime puis réinsère les lignes du produit,
  seulement si `ciklik_subscription_enabled` est posté.
- Page BO « Gestion des Fréquences » : `AdminCiklikFrequenciesController` (onglet du menu Ciklik,
  actif seulement en mode fréquences). Liste et formulaire d'une fréquence, suppression
  interdite. **C'est l'endroit naturel de la fonction A.**
- Pas de synchronisation catalogue vers Ciklik dans ce mode.

### Mode déclinaisons

- Groupes créés à l'installation (`RelatedEntitiesManager::install`) : « Type d'achat »
  (`CIKLIK_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID`, attributs `CIKLIK_ONEOFF_ATTRIBUTE_ID` « Achat en une
  fois » et `CIKLIK_SUBSCRIPTION_ATTRIBUTE_ID` « Abonnement ») et « Fréquence d'abonnement »
  (`CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID`). Chaque attribut de fréquence est relié à une ligne
  de `ciklik_frequencies` (`id_attribute`, `interval`, `interval_count`), saisie dans le
  formulaire de l'attribut (`hookDisplayAttributeForm`, `hookActionAttributeSave`).
- `CIKLIK_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID` : fréquence proposée par défaut (Mensuel à
  l'installation). Sur la fiche front, `Ciklik::getCiklikProductAttributes()` part de la
  déclinaison « Une fois » et cherche la déclinaison soeur portant **cette** fréquence
  (`CiklikCombination::getOne`) ; si elle n'existe pas, le sélecteur d'abonnement disparaît.
  Conséquence pour B : la fréquence par défaut doit faire partie des fréquences créées.
- **Pas de remise en mode déclinaisons.** `ciklik_frequencies` n'a aucune colonne de remise ;
  les remises sont portées par les fréquences du mode fréquences (`ciklik_frequency`). En mode
  déclinaisons, le prix d'une déclinaison abonnement est uniquement son impact prix PrestaShop
  (`product_attribute.price`, HT), réglé à la main par le marchand. Le module se contente d'afficher un pourcentage calculé (`discount_percentage` =
  écart entre le prix « Une fois » sans réduction et le prix de la déclinaison abonnement, arrondi
  à l'entier inférieur) et d'envoyer à Ciklik prix produit + impact.
- Synchronisation catalogue : `CiklikSubscribable::handle($idProduct)` (appelé à l'enregistrement
  de la fiche par `actionAfterUpdateProductFormHandler` en 1.7.8+ et `actionObjectProductUpdateAfter`
  en 1.7.x) crée la ligne `ciklik_subscribables` puis `CiklikSubscribableVariant::pushToCiklik()`
  envoie **en une seule requête** toutes les déclinaisons portant une fréquence
  (`external_id` = `id_product_attribute`, `ref` = référence de la déclinaison, sinon
  `idProduit-idDéclinaison`).
- Côté application Ciklik (`POST /products`, `CreateProductService::bulk`) : traitement
  synchrone, une écriture et une synchro des fréquences par produit, sans limite de taille de
  lot ; produit retrouvé par `tenant_id` + `exodus_id` (= `external_id`), donc **une même
  référence sur plusieurs déclinaisons ne crée pas de collision** (aucun index unique sur `ref`).
  Limite de débit `throttle:api` : 200 requêtes par minute et par IP. Client HTTP du module :
  délai d'attente de 10 secondes (`CiklikApiClient::$timeout`).
- Stock : le module ne touche jamais au stock. Chaque déclinaison a son propre stock
  PrestaShop ; l'ERP du marchand le synchronise par SKU.

### Socle commun

- Hooks enregistrés par `Installer::getHooksToRegister()` ; versions via `PsVersionCapabilities`.
- Contrôleurs admin en `ModuleAdminController` legacy, `l()` redéfini pour PS9 ; onglets
  installés par `Installer::installAdminTabs()` (rejoué à chaque enregistrement de la
  configuration et par `upgrade-1.17.0.php`).
- Traductions : anglais source, clés `<{ciklik}prestashop>fichier_md5`, six fichiers
  (`en`, `fr`, `de`, `es`, `it`, `pl`).
- Tests : PHPUnit (`tests/Unit`, `tests/bootstrap.php` avec bouchons PrestaShop), test de
  compatibilité PHP 7.0 (`Php70CompatibilityTest`), lane CI `php -l` 7.0 à 8.4.

## 2. Fonction A : fréquences par catégorie (mode fréquences)

Remises : en mode fréquences, la remise est portée par la fréquence elle-même. L'activation par
catégorie n'associe que des fréquences existantes et ne demande donc aucune saisie de remise.

### 2.1 Emplacement

Pas de nouvel onglet. Un second panneau sous la liste de « Gestion des Fréquences »
(`AdminCiklikFrequenciesController`), intitulé « Activer des fréquences par catégorie ». La page
n'existe qu'en mode fréquences, ce qui borne la fonction au bon mode sans test supplémentaire.

### 2.2 Parcours

1. Le marchand choisit **une catégorie** (liste déroulante indentée de l'arbre, construite sur
   `category` trié par `nleft`, libellés dans la langue de l'employé), laisse cochée ou décoche
   « Inclure les sous-catégories » (cochée par défaut), et coche **une ou plusieurs fréquences**
   existantes.
2. Bouton « Prévisualiser » : affichage, sans écriture,
   - nombre de produits trouvés ;
   - nombre exclu, par motif (packs, produits virtuels, produits inactifs) ;
   - nombre de produits qui recevront au moins une fréquence, et nombre de lignes ajoutées ;
   - nombre de produits qui avaient déjà toutes les fréquences choisies (inchangés) ;
   - les 20 premiers noms de produits concernés, avec « et N autres ».
3. Bouton « Ajouter ces fréquences » (actif seulement après l'aperçu, confirmation JS) :
   insertion des lignes manquantes. On ajoute, on ne retire rien.

**Pas d'action inverse.** Des abonnements en cours peuvent dépendre de ces fréquences ; un retrait
se fait produit par produit sur la fiche, comme aujourd'hui.

Le formulaire d'application renvoie la sélection (catégorie, sous-catégories, fréquences) et une
empreinte du résultat de l'aperçu (nombre de produits et de lignes). Si le recalcul au moment
d'appliquer donne un autre résultat (catalogue modifié entre-temps), on n'écrit rien et on
réaffiche l'aperçu.

### 2.3 Sélection des produits

```sql
-- sous-arbre par intervalle imbriqué, valable de 1.7 à 9
-- (sans sous-catégories : c.id_category = root.id_category et cd.id_category = root.id_category)
SELECT DISTINCT p.id_product
FROM ps_product p
JOIN ps_category root ON root.id_category = :id_category
LEFT JOIN ps_category_product cp ON cp.id_product = p.id_product
LEFT JOIN ps_category c  ON c.id_category  = cp.id_category
LEFT JOIN ps_category cd ON cd.id_category = p.id_category_default
WHERE ( (c.nleft  >= root.nleft AND c.nright  <= root.nright)
     OR (cd.nleft >= root.nleft AND cd.nright <= root.nright) )
  AND p.cache_is_pack = 0
  AND p.is_virtual = 0
  AND p.active = 1
```

- Catégorie principale **ou** associée : `category_product` couvre l'association, la jointure sur
  `id_category_default` rattrape les produits dont la catégorie principale n'a pas de ligne dans
  `category_product` (cas d'imports).
- Exclusions par `cache_is_pack` et `is_virtual`, présents en 1.7, 8 et 9 (la colonne
  `product_type` n'existe qu'à partir de 8, on ne s'en sert pas).
- **Produits inactifs exclus** : on ne les touche pas. Multiboutique : `active` est lu dans
  `product_shop` de la boutique du contexte quand le multiboutique est actif (sinon `product`).
- `ciklik_product_frequency` n'a pas d'`id_shop` : l'association vaut pour toutes les boutiques,
  comme la case de la fiche produit aujourd'hui.

### 2.4 Écriture

- `INSERT IGNORE INTO ciklik_product_frequency (id_product, id_frequency) VALUES ...` par lots de
  500 couples ; la clé primaire composite rend l'opération idempotente.
- Identifiants de fréquences vérifiés contre `ciklik_frequency` avant écriture (une fréquence
  inconnue est refusée), identifiants castés en entier.
- Tout le traitement dans une transaction InnoDB pour qu'un échec en milieu de lot ne laisse pas
  un état partiel.
- Journal : une ligne `PrestaShopLogger` (niveau 1) par application : employé, catégorie,
  sous-catégories oui ou non, fréquences, nombre de lignes. Pas de table d'historique.
- Aucun appel à l'API Ciklik (mode fréquences sans synchronisation catalogue).

### 2.5 Fichiers touchés (A)

| Fichier | Changement |
|---|---|
| `src/Managers/CiklikCategoryFrequencyAssigner.php` (nouveau) | requêtes de sélection, aperçu, ajout |
| `src/Helpers/CategoryFrequencyPlan.php` (nouveau, pur) | calcul de l'aperçu à partir des ensembles (produits trouvés, exclus par motif, couples existants, couples visés) ; testable sans base |
| `controllers/admin/AdminCiklikFrequenciesController.php` | `renderList()` ajoute le panneau ; `postProcess()` gère `submitCiklikCategoryPreview` et `submitCiklikCategoryApply` ; contrôle `$this->access('edit')` |
| `views/templates/admin/frequencies_by_category.tpl` (nouveau) | formulaire, aperçu, bouton |
| `translations/*.php` (6 fichiers) | nouvelles chaînes, préfixes `adminciklikfrequenciescontroller_` et `frequencies_by_category_` |
| `tests/Unit/Helpers/CategoryFrequencyPlanTest.php` (nouveau) | voir section 5 |

Ni schéma, ni hook, ni script d'upgrade pour A.

## 3. Fonction B : « Transformer en abonnement » (mode déclinaisons)

### 3.1 Emplacement et parcours

Sur la fiche produit, dans le bloc du module rendu par `hookDisplayAdminProductsExtra`, qui
aujourd'hui sort immédiatement hors mode fréquences : on ajoute la branche « mode déclinaisons ».
Onglet « Modules » de la fiche en 1.7.8 et de l'ancienne fiche 8.x, onglet « Options / Modules »
de la nouvelle fiche 8.1+ et 9.

Le panneau affiche, selon l'état du produit :

- **Produit déjà en abonnement** (au moins une déclinaison porte un attribut « Fréquence
  d'abonnement » ou « Abonnement ») : pas de bouton de transformation (compléter un produit déjà
  transformé n'est pas prévu), seulement le bouton « Réaligner le stock » (3.6).
- **Produit non éligible** (pack, virtuel, gestion de stock avancée 1.7 `depends_on_stock = 1`,
  réglages du module incomplets : groupes ou attributs Ciklik absents) : raison affichée, pas de
  bouton.
- **Produit éligible** : liste à cocher des fréquences existantes (attributs du groupe
  « Fréquence d'abonnement » reliés à `ciklik_frequencies`), la fréquence par défaut
  (`CIKLIK_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID`) cochée et verrouillée ; puis « Prévisualiser ».
  Aucune saisie de prix ni de remise : le mode déclinaisons n'a pas de remise, le prix se règle
  ensuite dans PrestaShop (3.5).

Aperçu (sans écriture) :

- « 10 déclinaisons existantes recevront Type d'achat = Une fois » (ou « Le produit n'a pas de
  déclinaison : une déclinaison Une fois sera créée à partir du produit, stock 42 repris ») ;
- « 30 déclinaisons abonnement seront créées (10 × 3 fréquences), total 40 » ;
- « Le stock de chaque déclinaison Une fois sera copié sur ses déclinaisons abonnement ; la
  quantité totale du produit affichée par PrestaShop passera de 120 à 480 (somme des
  déclinaisons) » ;
- refus si le total dépasse **1 000 déclinaisons** pour le produit.

Puis « Transformer » (confirmation JS). L'action passe par un contrôleur admin dédié et non par
l'enregistrement de la fiche, pour ne pas mêler la transformation au formulaire produit. À la fin,
rechargement complet de la page produit (le formulaire de déclinaisons en mémoire est périmé ;
en 1.7.8, un enregistrement sans rechargement renverrait des déclinaisons obsolètes).

### 3.2 Contrôleur et sécurité

- `controllers/admin/AdminCiklikProductTransformController.php` (nouveau), onglet caché
  (`id_parent = -1`), actions AJAX `preview`, `transform` et `realign_stock` en POST, jeton admin
  (`getAdminLink`), contrôle `$this->access('edit')` et droit d'édition sur `AdminProducts`.
  Onglet créé par `installAdminTabs()` ; pour les boutiques existantes, un `upgrade-1.28.0.php`
  minimal qui rappelle `installAdminTabs()` (même principe que 1.17.0), sans table ni hook.
  Alternative écartée : des actions AJAX dans `AdminConfigureCiklikController`, dont le
  `postProcess()` réinstalle les onglets et vide le cache à chaque requête.
- Verrou par produit : `GET_LOCK('ciklik_transform_<id_shop>_<id_product>', 0)` ; second clic ou
  second onglet refusé tant que le premier n'a pas fini.
- Réponse JSON : succès (nombre de déclinaisons créées, résultat de la synchro Ciklik) ou erreur
  traduite ; jamais de message d'exception brut non échappé (règle PS9 du dépôt).

### 3.3 Algorithme de transformation

Préconditions recalculées au moment de transformer (pas seulement à l'aperçu) : mode
déclinaisons actif, configuration complète, produit éligible, aucune déclinaison avec attribut
Ciklik, fréquences choisies toutes reliées à `ciklik_frequencies`, fréquence par défaut incluse,
total sous 1 000.

**Cas 1, le produit a des déclinaisons.** Pour chaque déclinaison existante D :

1. Ajouter l'attribut « Une fois » à D par insertion directe dans
   `product_attribute_combination` (`id_attribute` = `CIKLIK_ONEOFF_ATTRIBUTE_ID`,
   `id_product_attribute` = D). **Jamais** `Combination::setAttributes()` sur D, jamais de
   suppression ni de recréation de D : son `id_product_attribute`, référencé par les abonnements
   et paniers, ne change pas.
2. Pour chaque fréquence F choisie, créer une déclinaison S(D, F) :
   - `new Combination()` avec tous les champs de D recopiés de façon générique depuis
     `Combination::$definition['fields']` (ce qui absorbe les différences de version : `upc`,
     `isbn`, `mpn` en 8+, `location` en 1.7, `low_stock_threshold`, `available_date`, prix
     d'achat `wholesale_price`, etc.), sauf `id_product` et `default_on` ;
   - impact prix recopié de D tel quel (voir 3.5) ;
   - références identiques à D, sans suffixe : `reference`, `ean13`, `isbn`, `upc`, `mpn` ;
   - attributs : ceux de D + « Abonnement » + attribut de F, via `setAttributes()` sur la
     **nouvelle** déclinaison seulement ;
   - images : `setImages()` avec les images de D (`product_attribute_image`) ;
   - références fournisseur et prix d'achat fournisseur : recopie des lignes `product_supplier`
     de D (`product_supplier_reference`, `product_supplier_price_te`, `id_currency`) ;
   - stock : clonage ponctuel (3.6).
3. La déclinaison par défaut ne bouge pas : elle était une des D, elle devient donc une « Une
   fois ».

**Cas 2, produit simple (aucune déclinaison).**

1. Créer la déclinaison U « Une fois » : attribut « Une fois » seul, impact prix 0, impact poids
   0, impact prix unitaire 0, `default_on = 1` (`Product::setDefaultAttribute`).
2. Référence et codes : U reprend `reference`, `ean13`, `isbn`, `upc`, `mpn` du produit et ses
   références fournisseur. Le produit **garde** les siens (PrestaShop les conserve au niveau
   produit). Pas de suffixe.
3. Stock : avant création, lire la quantité du produit
   (`StockAvailable::getQuantityAvailableByProduct(id, 0)`) ; après création de U, poser cette
   quantité sur U. PrestaShop recalcule alors la ligne produit (`id_product_attribute = 0`) comme
   somme des déclinaisons. Le stock vendable « Une fois » est donc exactement l'ancien stock du
   produit simple. `out_of_stock` (« Accepter les commandes ») et `minimal_quantity` restent au
   niveau produit et s'appliquent à toutes les déclinaisons.
4. Images : toutes les images du produit associées à U.
5. Puis étape 2 du cas 1 avec D = U.
6. Effet de bord annoncé dans l'aperçu : les paniers en cours qui contiennent le produit sans
   déclinaison ne correspondent plus à une déclinaison vendable ; PrestaShop les signale au
   client. Aucune commande ni abonnement existant n'est touché (un produit simple ne peut pas
   être en abonnement en mode déclinaisons).

**Fin commune.**

- `Search::indexation(false, $idProduct)` pour réindexer les attributs ; vidage des caches prix
  (`Product::flushPriceCache()` quand la méthode existe).
- Validation de la transaction (3.4), puis synchro Ciklik (3.7). Un échec de l'API **n'annule
  pas** la transformation (elle est saine côté boutique) : message « Transformation faite, la
  synchronisation avec Ciklik a échoué : enregistrez la fiche pour la relancer » et journal.

### 3.4 Transaction et reprise propre

- `START TRANSACTION` / `COMMIT` / `ROLLBACK` sur `Db::getInstance()` (l'API Db de PrestaShop
  n'a pas de méthode de transaction en 1.7 ; les tables PrestaShop sont en InnoDB par défaut).
  Toutes les écritures (ObjectModel compris) passent par cette même connexion.
- Filet de sécurité si le `ROLLBACK` ne suffit pas (table en MyISAM sur une vieille boutique,
  commit implicite par un module tiers branché sur un hook objet) : un journal en mémoire des
  écritures (lignes `product_attribute_combination` insérées sur D, ids des déclinaisons créées,
  stock posé) et une compensation qui supprime **uniquement** ce qui a été créé
  (`Combination::delete()` sur les nouvelles, suppression des lignes « Une fois » ajoutées, retour
  du stock et de la déclinaison par défaut en cas 2). Les D d'origine ne sont jamais supprimées,
  même en compensation.
- Vérification préalable du moteur des tables concernées (`SHOW TABLE STATUS`) : si l'une n'est
  pas InnoDB, l'aperçu le signale et la transformation s'appuie sur la compensation seule.
- Pas d'appel réseau pendant la transaction (la synchro Ciklik vient après le `COMMIT`).

### 3.5 Prix

- Le mode déclinaisons n'a pas de remise : les déclinaisons abonnement sont créées au même prix
  que « Une fois » (S(D, F) reçoit le même impact prix que D), et le marchand règle les impacts
  prix à la main dans PrestaShop. Le module affiche ensuite le pourcentage calculé, inchangé.
- Prix spécifiques ciblés sur D (promotions « Une fois ») : **non recopiés**. Les prix spécifiques
  au niveau produit (`id_product_attribute = 0`) s'appliquent déjà à toutes les déclinaisons,
  abonnement comprises, comme aujourd'hui pour un produit paramétré à la main.
- La déclinaison par défaut reste « Une fois ».
- Le prix envoyé à Ciklik reste celui de `pushToCiklik` (prix produit + impact, HT) : inchangé.

### 3.6 Stock : clonage ponctuel, pas de synchronisation

**Règle.** Le stock de la déclinaison « Une fois » fait référence. Au moment de la génération,
la quantité de chaque déclinaison « Une fois » est **copiée** sur ses déclinaisons abonnement de
la **même combinaison** (le stock de « M, Une fois » va sur « M, Abonnement, Mensuel », « M,
Abonnement, Tous les 2 mois », etc., jamais sur une autre taille). Ensuite, rien : le module ne
s'accroche à aucun mouvement de stock et ne change rien au fonctionnement de PrestaShop. C'est
l'ERP du marchand qui synchronise ensuite le stock par SKU, d'où la même référence partout.

**Mise en oeuvre.**

- « Même combinaison » = mêmes attributs hors des deux groupes Ciklik. À la génération, la
  correspondance est connue (S est créée à partir de D) ; pour le réalignement, elle est
  recalculée par cette signature d'attributs.
- `StockAvailable::setQuantity(id_product, S, quantité de D)` dans la boutique ou le groupe de
  boutiques du contexte (le partage de stock par groupe de boutiques est respecté par
  `StockAvailable`). PrestaShop recalcule la quantité totale du produit (somme des déclinaisons),
  qui est donc multipliée par 1 + le nombre de fréquences ; c'est annoncé dans l'aperçu.
- Seule `quantity` est posée ; `physical_quantity` et `reserved_quantity` restent gérées par le
  cœur.

**Bouton « Réaligner le stock ».** Sur la fiche d'un produit qui a des déclinaisons abonnement
(transformé par B ou paramétré à la main), il refait le même clonage à la demande, même règle,
même combinaison seulement. Aperçu avant écriture : nombre de déclinaisons abonnement dont la
quantité va changer, avec l'ancienne et la nouvelle valeur pour les 20 premières. Une déclinaison
abonnement sans déclinaison « Une fois » de même combinaison est laissée telle quelle et listée.
Aucun historique des réalignements n'est journalisé.

Aucune table, aucun hook de stock, aucun script de mise à jour pour le stock.

### 3.7 Synchronisation du catalogue vers Ciklik

- Volume : jusqu'à 1 000 déclinaisons par produit, dont au plus 1 000 moins le nombre de « Une
  fois » portent une fréquence et partent vers Ciklik.
- Problème actuel : `pushToCiklik()` envoie tout en **une seule requête** ; côté application,
  chaque produit est écrit puis ses fréquences synchronisées de façon synchrone. Avec plusieurs
  centaines de variantes, la réponse risque de dépasser le délai de 10 secondes du client HTTP,
  et ce à **chaque enregistrement de la fiche**, pas seulement après la transformation.
- Changement : `pushToCiklik()` découpe l'envoi en lots de 100 variantes (constante), une requête
  par lot, à la suite. Pour 1 000 variantes : 10 requêtes, loin de la limite de 200 requêtes par
  minute ; pas de pause entre les lots nécessaire. Un lot en échec est journalisé avec ses
  `external_id`, les lots suivants partent quand même, et le message final indique le nombre de
  variantes non synchronisées (relance par enregistrement de la fiche, les envois sont
  idempotents grâce à `external_id`).
- Le découpage vaut pour tous les produits du mode déclinaisons : un produit de moins de 100
  variantes garde exactement le comportement actuel (une requête).
- Contrainte côté application à rappeler : `interval_count` est limité à 28 et `interval` à
  `month`, `week`, `day` ; une fréquence hors bornes fait échouer tout son lot. L'aperçu de B
  refuse une fréquence choisie hors de ces bornes.

### 3.8 Fichiers touchés (B)

| Fichier | Changement |
|---|---|
| `src/Helpers/SubscriptionTransformPlan.php` (nouveau, pur) | gardes, nombre de déclinaisons, correspondance D vers S, plafond, bornes de fréquence |
| `src/Managers/CiklikSubscriptionTransformer.php` (nouveau) | lecture du produit, transaction, création, clonage du stock, compensation, synchro |
| `src/Managers/CiklikStockRealigner.php` (nouveau) | signature de combinaison, aperçu et clonage à la demande |
| `src/Managers/CiklikSubscribableVariant.php` | envoi par lots de 100 |
| `controllers/admin/AdminCiklikProductTransformController.php` (nouveau) | actions `preview`, `transform`, `realign_stock` |
| `ciklik.php` | `hookDisplayAdminProductsExtra` : branche mode déclinaisons |
| `src/Install/Installer.php` | onglet caché dans `installAdminTabs()` |
| `upgrade/upgrade-1.28.0.php` (nouveau, minimal) | rappelle `installAdminTabs()` pour créer l'onglet caché |
| `views/templates/admin/product_transform_panel.tpl` (nouveau) | panneau, aperçus, boutons |
| `views/js/admin-product-transform.js` (nouveau) | appels AJAX, confirmation, rechargement ; jQuery seulement (présent en 1.7, 8, 9) |
| `translations/*.php` (6 fichiers) | nouvelles chaînes |
| `tests/Unit/...` | voir section 5 |

## 4. Compatibilité PrestaShop 1.7.8, 8, 9

| Point | 1.7.8 | 8.x | 9.x |
|---|---|---|---|
| `displayAdminProductsExtra` | onglet Modules de la fiche | ancienne fiche et fiche v2 (8.1+) | fiche v2 seulement |
| Classe attribut | `Attribute` | `ProductAttribute` | `ProductAttribute` (déjà géré par `CiklikCombination::getAttributeInstance`) |
| `Combination` ObjectModel, `setAttributes`, `setImages` | oui | oui | oui, à confirmer sur le conteneur 9 |
| Champs de `Combination` | `upc`, `isbn`, `location` | `+ mpn`, `location` déplacé côté stock | comme 8 ; recopie générique par `$definition` |
| `StockAvailable::setQuantity` | oui | oui | oui |
| Gestion de stock avancée | possible (`depends_on_stock`) : produit refusé | supprimée | supprimée |
| PHP | 7.1 à 7.4 | 7.2 à 8.1 | 8.1+ |

Le code reste en syntaxe PHP 7.0 (règle du dépôt) : pas de `?Type`, pas de `: void`, pas de
`fn`, pas de propriétés typées. Les listes déroulantes et cases du BO restent en HTML/Bootstrap
simple, lisibles en Bootstrap 3 (1.7 legacy) comme en Bootstrap 4/5 (8, 9).

## 5. Tests

### Unitaires (PHPUnit, sans base)

- `CategoryFrequencyPlanTest` : produits trouvés avec et sans sous-catégories, packs, virtuels et
  inactifs exclus, couples déjà présents non recomptés, catégorie sans produit, fréquence inconnue
  refusée.
- `SubscriptionTransformPlanTest` : 10 × 3 = 30 créations ; produit simple = 1 + F ; refus si
  attribut Ciklik présent, si fréquence par défaut absente, si 1 000 dépassé, si pack ou virtuel,
  si fréquence hors bornes Ciklik ; correspondance D vers S pour le clonage.
- `CiklikStockRealignerTest` (partie pure) : signature de combinaison, une taille ne reçoit jamais
  le stock d'une autre, déclinaison abonnement orpheline laissée telle quelle.
- `CiklikSubscribableVariantTest` : découpage en lots (99, 100, 101, 1 000 variantes), un lot en
  échec n'empêche pas les suivants.
- `Php70CompatibilityTest` couvre les nouveaux fichiers ; lane CI `php -l` 7.0 à 8.4.

### Sur la boutique de test Docker du dépôt (`make dev`, `make dev8`, `make dev9`)

Sauvegarde de la base avant chaque passe (`make backup`). Pour chacune des trois versions :

- **A** : catégorie avec sous-catégories, un pack, un produit virtuel, un produit inactif, un
  produit déjà associé à une des fréquences ; aperçu avec et sans sous-catégories, puis ajout ;
  vérification en base et sur la fiche front ; rejouer l'ajout (rien ne change).
- **B, produit à déclinaisons** (10 tailles, stocks différents) : relever les
  `id_product_attribute` avant ; transformer avec 3 fréquences ; vérifier que les 10 ids n'ont
  pas changé, 30 créées, références, EAN, références fournisseur et prix d'achat identiques,
  images, impact prix égal à celui de « Une fois », déclinaison par défaut, stock de chaque
  taille copié sur ses seules déclinaisons abonnement, fiche front (bascule Une fois /
  Abonnement), synchro Ciklik (environnement de test) avec `external_id` corrects.
- **B, produit simple** : stock 42 avant, déclinaison « Une fois » à 42 après, 3 déclinaisons
  abonnement à 42, référence produit conservée.
- **B, gros produit** : 250 déclinaisons × 3 fréquences (1 000 au total) : temps de
  transformation, 8 requêtes vers Ciklik, aucune expiration ; 1 001 refusé.
- **B, garde-fous** : second clic refusé, produit déjà transformé sans bouton de transformation,
  échec provoqué en milieu de création (exception injectée en mode debug) : aucune déclinaison
  orpheline, ids d'origine intacts.
- **Réaligner le stock** : après une vente « Une fois » et une souscription, aperçu puis
  réalignement ; aucune autre taille touchée ; vérifier ensuite qu'une commande ne déclenche
  aucune recopie (comportement PrestaShop d'origine).
- Multiboutique activé sur une des trois versions au moins (stock partagé par groupe de
  boutiques et stock séparé).

## 6. Lots

Pas de push ni de PR sans demande. Revue code, sécurité et passe de simplification à chaque lot.

1. **A1** : `CategoryFrequencyPlan` et tests, `CiklikCategoryFrequencyAssigner`.
2. **A2** : panneau dans « Gestion des Fréquences », traductions, vérification Docker 1.7.8 / 8 / 9.
3. **B1** : `SubscriptionTransformPlan` et tests ; envoi par lots dans `pushToCiklik` et tests.
4. **B2** : transformeur, transaction et compensation, clonage du stock, contrôleur caché,
   panneau et JS, upgrade 1.28.0 minimal, synchro Ciklik.
5. **B3** : « Réaligner le stock », tests unitaires et scénarios Docker.
6. **B4** : traductions complètes, page Ciklik Academy « Transformer un produit en abonnement »
   et mise à jour de la rubrique paramétrage (à rédiger avec Francois), release.

## 7. Prévision de stock du module

Réévaluée sans stock partagé : **rien à changer pour ce chantier.** `StockForecastAggregator`
compare les besoins de chaque déclinaison abonnement au stock PrestaShop de cette même
déclinaison, ce qui reste cohérent avec le fonctionnement de PrestaShop qu'on ne modifie pas.
Limite déjà présente aujourd'hui pour tout produit paramétré à la main et dont l'ERP pose le même
stock sur plusieurs déclinaisons d'un même SKU : les besoins des fréquences soeurs ne sont pas
additionnés. Ce n'est pas introduit par B.

## 8. Risques

| Risque | Effet | Parade |
|---|---|---|
| Recréation d'une déclinaison existante | « Bad parameter : id » au rebill, cause n°1 des échecs | jamais de suppression ni de `setAttributes` sur une D ; test qui compare les ids avant et après |
| Fréquence par défaut non créée | sélecteur d'abonnement absent sur la fiche | fréquence par défaut obligatoire et verrouillée |
| Déclinaisons abonnement au prix « Une fois » | pas de prix abonnement tant que le marchand n'a pas réglé les impacts | dit dans l'aperçu et la doc |
| Quantité totale du produit multipliée | listes BO, alertes de stock bas au niveau produit | annoncé dans l'aperçu |
| Stock qui dérive après la génération (sans ERP) | une déclinaison abonnement peut se vendre ou se bloquer indépendamment de « Une fois » | comportement PrestaShop normal, choix assumé ; bouton « Réaligner le stock » |
| Envoi Ciklik volumineux | expiration à 10 secondes, à chaque enregistrement de la fiche | envoi par lots de 100 |
| Fréquence hors bornes côté Ciklik (`interval_count` > 28) | lot entier refusé | refus dans l'aperçu de B |
| Échec de l'API Ciklik après transformation | catalogue Ciklik incomplet | transformation conservée, message clair, relance par enregistrement de la fiche |
| Transaction non effective (MyISAM, commit implicite) | état partiel | compensation qui ne supprime que les créations |
| Paniers ouverts d'un produit simple transformé | ligne sans déclinaison invalide | annoncé dans l'aperçu, effet limité aux paniers en cours |

## 9. Décisions de Francois (2026-11-06)

- Stock : pas de stock partagé ni de synchronisation continue ; clonage ponctuel à la génération,
  même combinaison seulement ; bouton « Réaligner le stock » qui refait ce clonage.
- Quantité totale multipliée, annoncée dans l'aperçu : accepté.
- Remises : en mode fréquences, portées par la fréquence (rien à saisir en A) ; en mode
  déclinaisons, aucune remise, déclinaisons abonnement au prix « Une fois », impacts réglés à la
  main dans PrestaShop.
- Promos ciblées sur « Une fois » : non recopiées.
- Même EAN et même référence, sans suffixe et sans avertissement.
- Références fournisseur et prix d'achat : recopiés.
- Plafond : 1 000 déclinaisons par produit.
- A : produits inactifs exclus ; pas d'action inverse ; sous-catégories en case cochée par défaut.
- Compléter un produit déjà transformé : non.
- Prévision de stock : pas de lot séparé (section 7).
- Envoi vers Ciklik par lots de 100 pour tous les produits du mode déclinaisons.
- « Réaligner le stock » aussi sur les produits paramétrés à la main, sans historique.
- Script de mise à jour minimal pour l'onglet caché, version 1.28.0 (prochaine mineure après
  1.27.1).

## 10. Questions ouvertes pour Francois

Aucune.
