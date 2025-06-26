<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use Carrier;
use Cart;
use Ciklik;
use Configuration;
use Context;
use Customer;
use Db;
use DbQuery;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;
use PrestaShop\Module\Ciklik\Managers\CiklikItemFrequency;
use PrestaShop\Module\Ciklik\Managers\DeliveryModuleManager;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomization;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartGateway extends AbstractGateway implements EntityGateway
{
    public function get()
    {
        $filters = Tools::getValue('filter');

        if (!array_key_exists('id', $filters)) {
            (new Response())->setBody(['error' => 'Missing parameter : id'])->sendBadRequest();
        }

        preg_match('/\[(?P<id>\d+)\]/', $filters['id'], $matches);

        if (!array_key_exists('id', $matches)) {
            (new Response())->setBody(['error' => 'Bad parameter : id'])->sendBadRequest();
        }

        $cart = new Cart((int) $matches['id']);

        if (!$cart->id) {
            (new Response())->setBody(['error' => 'Cart not found'])->sendNotFound();
        }

        $this->cartResponse($cart);
    }

    public function post()
    {        
        $cartFingerprint = Tools::getValue('fingerprint', null);

        if (is_null($cartFingerprint)) {
            (new Response())->setBody(['error' => 'Missing parameter : fingerprint'])->sendBadRequest();
        }

        $cartFingerprintData = CartFingerprintData::extractDatas($cartFingerprint);

        $customer = new Customer($cartFingerprintData->id_customer);

        if (!$customer->id) {
            (new Response())->setBody(['error' => 'Customer not found'])->sendNotFound();
        }

        $carrier = Carrier::getCarrierByReference($cartFingerprintData->id_carrier_reference);

        if ($carrier === false) {
            $carrier = new Carrier((int) Configuration::get('PS_CARRIER_DEFAULT'));
        }

        $cart = new Cart();
        $cart->id_customer = $cartFingerprintData->id_customer;
        $cart->id_address_delivery = $cartFingerprintData->id_address_delivery;
        $cart->id_address_invoice = $cartFingerprintData->id_address_invoice;
        $cart->id_lang = $cartFingerprintData->id_lang;
        $cart->id_currency = $cartFingerprintData->id_currency;
        $cart->recyclable = 0;
        $cart->gift = 0;
        $cart->secure_key = $customer->secure_key;
        $cart->add();
        

        /*
         * On force l'id_carrier.
         * Sans delivery_option, le transporteur le moins cher
         * sera ajouté et remplacera l'id_carrier.
         * L'id_carrier doit être modifié après setDeliveryOption
         * puisque la fonction réinitialise sa valeur à 0.
         */
        $delivery_option = [];
        $delivery_option[$cart->id_address_delivery] = sprintf('%d,', $carrier->id);
        $cart->setDeliveryOption($delivery_option);
        $cart->id_carrier = $carrier->id;
        $cart->update();

        $variants = Tools::getValue('products', null);


        if (is_null($variants)) {
            (new Response())->setBody(['error' => 'Missing parameter : products'])->sendBadRequest();
        }

        if (!is_array($variants)) {
            (new Response())->setBody(['error' => 'Bad parameter : products'])->sendBadRequest();
        }
        

        foreach ($variants as $variant) {
            $parts = explode(':', $variant);
            
            // Format: id_product_attribute:quantity
            if (count($parts) === 2) {
                list($id_variant, $quantity) = $parts;
                $query = new DbQuery();
                $query->select('`id_product`');
                $query->from('product_attribute');
                $query->where('`id_product_attribute` = "' . (int) $id_variant . '"');
                $id_product = Db::getInstance()->getValue($query);
            }
            // Format: id_product:id_product_attribute:quantity
            else if (count($parts) === 3) {
                list($id_product, $id_variant, $quantity) = $parts;
            }

            if (!$id_product && count($parts) === 2) {
                (new Response())->setBody(['error' => "Product not found for variant {$id_variant}"])->sendNotFound();
            }

            $cart->updateQty($quantity, (int) $id_product, (int) $id_variant);
        }

        $cart->update();

        // Appliquer les customizations après l'ajout des produits
        if (!empty($cartFingerprintData->customizations)) {
            $customizationResult = CiklikCustomization::applyCustomizationsToCart($cart, $cartFingerprintData->customizations);
            
            if (!$customizationResult) {
                PrestaShopLogger::addLog(
                    'CartGateway::post - Échec application customizations pour le panier ' . $cart->id,
                    3,
                    null,
                    'CartGateway',
                    $cart->id,
                    true
                );
                // On continue même si les customizations échouent pour ne pas bloquer le processus
            } else {
                PrestaShopLogger::addLog(
                    'CartGateway::post - Customizations appliquées avec succès au panier ' . $cart->id,
                    1,
                    null,
                    'CartGateway',
                    $cart->id,
                    true
                );
            }
        }

        // Récupération des produits additionnels (upsells) depuis les paramètres de la requête
        $upsells = Tools::getValue('upsells', null);

        // Si des upsells sont présents dans la requête
        if(!is_null($upsells) && !empty($upsells)) {
            foreach($upsells as $product) {
                // Décomposition de la chaîne "id_product:id_product_attribute:quantity"
                list($id_product, $id_product_attribute, $quantity) = explode(':', $product);
                // Si pas d'attribut de produit, on met 0 par défaut
                $id_product_attribute = empty($id_product_attribute) ? 0 : $id_product_attribute;
                
                // Vérification de l'existence du produit dans la base
                $query = new DbQuery();
                $query->select('COUNT(*)');
                $query->from('product');
                $query->where('`id_product` = ' . (int)$id_product);
                
                // Si le produit existe
                if (Db::getInstance()->getValue($query)) {
                    // Si un attribut de produit est spécifié, on vérifie son existence
                    if ($id_product_attribute > 0) {
                        $query = new DbQuery();
                        $query->select('COUNT(*)');
                        $query->from('product_attribute');
                        $query->where('`id_product` = ' . (int)$id_product);
                        $query->where('`id_product_attribute` = ' . (int)$id_product_attribute);
    
                        // Si la combinaison n'existe pas, on renvoie une erreur 404
                        if (!Db::getInstance()->getValue($query)) {
                            (new Response())->setBody(['error' => "Product combination {$id_product_attribute} not found"])->sendNotFound();
                        }
                    }

                    // Mise à jour de la quantité du produit dans le panier
                    $cart->updateQty($quantity, (int) $id_product, (int) $id_product_attribute);
                }
                            
            }
        }

        // Gestion des points relais
        DeliveryModuleManager::handleDeliveryModule($cart);

        $this->cartResponse($cart, false, $cartFingerprintData->upsells);
    }

    private function cartResponse(Cart $cart, $withLinks = true, $upsells = [])
    {
        $items = [];

        /*
         * Ajout du customer dans le contexte
         * Pour gérer les cart_rules.
         * Sans customer, pas de discount
         */
        $context = Context::getContext();
        $customer = new Customer($cart->id_customer);
        $context->updateCustomer($customer);

        $summary = $cart->getRawSummaryDetails((int) Configuration::get('PS_LANG_DEFAULT'));

        $ciklik_frequency = Tools::getValue('ciklik_frequency', null);

        foreach ($summary['products'] as $product) {
        
            if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE) && $withLinks === true) {
                // Récupère la fréquence depuis la personnalisation
                // si on est dans un contexte de panier utilisateur (en ligne)
                $frequencyItem = CiklikItemFrequency::getByCartAndProduct((int) $cart->id, (int) $product['id_product']);
                $frequency = CiklikFrequency::getFrequencyById((int) $frequencyItem['frequency_id']);
                
            }
            if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE) && $withLinks === false && $ciklik_frequency) {
                // Récupère la fréquence depuis la personnalisation
                // si on est dans un contexte de panier rebill
                $frequency = CiklikFrequency::getFrequencyById((int) $ciklik_frequency);
                
            }

            if (!Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
                $frequency = CiklikFrequency::getByIdProductAttribute((int) $product['id_product_attribute']);
            }

            $items[] = [
                'type' => 'product',
                'external_id' => $this->getExternalId($product),
                'ref' => $product['reference'],
                'price' => $product['price_with_reduction_without_tax'] ?? $product['price_without_reduction_without_tax'],
                'tax_rate' => (float) $product['rate'] ? (float) $product['rate'] / 100 : 0,
                'quantity' => $product['cart_quantity'],
                'interval' => $frequency['interval'] ?? null,
                'interval_count' => $frequency['interval_count'] ?? null,
                'name' => $product['name'],
            ];
        }

        if ($summary['total_shipping_tax_exc'] > 0) {
            $transporter_tax_rate = (float) ($summary['total_shipping'] - $summary['total_shipping_tax_exc']) / $summary['total_shipping_tax_exc'];
        } else {
            $transporter_tax_rate = 0;
        }
        $items[] = [
            'type' => 'transport',
            'ref' => $summary['carrier']->name,
            'external_id' => $summary['carrier']->id_reference,
            'price' => (float) $summary['total_shipping_tax_exc'],
            'tax_rate' => $transporter_tax_rate,
        ];

        if (array_key_exists('discounts', $summary)) {
            foreach ($summary['discounts'] as $discount) {
                $items[] = [
                    'type' => 'reduction',
                    'ref' => $discount['name'] ?? $discount['code'],
                    'price' => (float) $discount['value_real'],
                    'tax_rate' => 0,
                ];
            }
        }

        $context = Context::getContext();

        $body = [
            'id' => $cart->id,
            'total_ttc' => $summary['total_price'],
            'items' => $items,
            'relay_options' => [],
            'fingerprint' => CartFingerprintData::fromCart($cart, $upsells, isset($frequency['id_frequency']) ? (int) $frequency['id_frequency'] : null)->encodeDatas(),
            'use_frequency_mode' => (bool) Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)
        ];

        if ($withLinks) {
            $body['return_url'] = $context->link->getModuleLink('ciklik', 'validation', [], true);
            $body['cancel_url'] = $context->link->getPageLink('order', true, (int) $context->language->id, ['step' => 1]);
        }

        (new Response())
            ->setBody([$body])
            ->send();
    }

    /**
     * Récupère l'ID externe au format approprié selon la configuration
     * 
     * @param array $product Les données du produit
     * @return string L'ID externe formaté
     */
    private function getExternalId(array $product)
    {
        if (!Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            return $product['id_product_attribute'] ?? $product['id_product'];
        }

        return $product['id_product'] . ':' . ($product['id_product_attribute'] ?? 0);
    }
}
