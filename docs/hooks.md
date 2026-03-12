# Hooks custom Ciklik

## actionCiklikCartBeforeRebill

**Disponible depuis** : v1.16.0

**Déclenchement** : Exécuté dans `CartGateway::post()` lors de la création d'un panier de rebill (renouvellement d'abonnement). Le hook est appelé après l'ajout des produits, des upsells et la gestion des points relais, mais avant la construction de la réponse API.

### Paramètres

| Paramètre | Type | Description |
|-----------|------|-------------|
| `cart` | `Cart` | Le panier PrestaShop créé pour le rebill. Objet mutable — les modifications sont prises en compte dans la réponse. |
| `cartFingerprintData` | `CartFingerprintData` | Données du fingerprint d'abonnement (client, adresses, fréquence, upsells). Contexte en lecture seule. |

### Propriétés utiles de CartFingerprintData

| Propriété | Type | Description |
|-----------|------|-------------|
| `id_customer` | int | ID client PrestaShop |
| `id_address_delivery` | int | ID adresse de livraison |
| `id_address_invoice` | int | ID adresse de facturation |
| `id_lang` | int | ID langue |
| `id_currency` | int | ID devise |
| `id_carrier_reference` | int | Référence du transporteur |
| `frequency_id` | int\|null | ID fréquence (mode fréquence) |
| `upsells` | array | Produits additionnels |

### Cas d'usage

- Ajout automatique de codes promo fidélité lors du rebill
- Application de remises spécifiques selon l'ancienneté de l'abonnement
- Modification du transporteur selon des règles métier
- Ajout de produits gratuits (échantillons)
- Logging ou tracking personnalisé

### Exemple : Module qui ajoute un code promo fidélité

```php
// Dans le fichier principal de votre module (monmodule.php)

public function install()
{
    return parent::install()
        && $this->registerHook('actionCiklikCartBeforeRebill');
}

/**
 * Ajoute un code promo fidélité au panier de rebill
 *
 * @param array $params Paramètres du hook
 */
public function hookActionCiklikCartBeforeRebill(array $params)
{
    /** @var Cart $cart */
    $cart = $params['cart'];

    /** @var \PrestaShop\Module\Ciklik\Data\CartFingerprintData $fingerprint */
    $fingerprint = $params['cartFingerprintData'];

    // Exemple : appliquer le code promo "FIDELITE10" si le client a plus de 3 commandes
    $orderCount = Order::getCustomerNbOrders($cart->id_customer);

    if ($orderCount >= 3) {
        $idCartRule = (int) CartRule::getIdByCode('FIDELITE10');
        if ($idCartRule) {
            $cart->addCartRule($idCartRule);
        }
    }
}
```

### Ordre d'exécution dans le flux de rebill

1. Création du panier (`Cart::add()`)
2. Configuration du transporteur (`setDeliveryOption()`)
3. Ajout des produits (variants + customizations)
4. Ajout des upsells
5. Gestion des points relais (`DeliveryModuleManager::handleDeliveryModule()`)
6. **→ `actionCiklikCartBeforeRebill` ←**
7. Construction et envoi de la réponse (`cartResponse()`)

### Notes importantes

- Le panier est un objet PHP passé par référence. Toute modification sur `$cart` est immédiatement visible dans la réponse API.
- Si vous modifiez le panier (ajout de cart rules, modification de quantités), PrestaShop persiste généralement ces changements automatiquement. Sinon, appelez `$cart->update()`.
- La réponse API inclut les totaux recalculés **après** l'exécution du hook, donc les modifications sont reflétées dans les montants envoyés à la plateforme Ciklik.
- Ce hook est de type "action" (préfixe `action`). Il ne retourne pas de contenu HTML.
- Compatible PrestaShop 1.7.7 à 9.x.
