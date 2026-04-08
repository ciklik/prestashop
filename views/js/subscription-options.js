/**
 * Gestion des options d'abonnement sur la page produit
 *
 * Respecte le mode de calcul configuré :
 *  - "gross" : affiche le prix catalogue brut de chaque combinaison (non affecté
 *    par les règles de prix PS). Les prix de toutes les combinaisons sont
 *    pré-calculés côté serveur et sérialisés dans un script JSON adjacent au
 *    conteneur. Le JS lit ensuite cette map au chargement et à chaque
 *    changement de déclinaison, sans jamais consulter le prix principal du DOM
 *    (qui lui contient toujours les règles de prix appliquées).
 *  - "net"   : lit le prix depuis le DOM principal du produit (comportement
 *    standard PrestaShop), ce qui reflète automatiquement les règles de prix.
 *
 * @author Ciklik SAS
 */

(function() {
  'use strict';

  var subscriptionOptions = {
    currencyCode: 'EUR',
    locale: 'fr',
    basePrice: 0,
    priceMode: 'net',
    productId: 0,
    combinationPrices: {},
    initialized: false
  };

  /**
   * Initialise les options d'abonnement
   */
  function initSubscriptionOptions(config) {
    subscriptionOptions = Object.assign(subscriptionOptions, config);

    if (subscriptionOptions.initialized) {
      return;
    }
    subscriptionOptions.initialized = true;

    // En mode net, on lit le prix depuis le DOM au chargement pour être aligné
    // avec le prix principal (qui inclut les règles de prix PS).
    // En mode gross, on garde le data-base-price transmis par le serveur, qui
    // est le prix brut correct.
    if (subscriptionOptions.priceMode === 'net') {
      var domPrice = readPrimaryPriceFromDom();
      if (domPrice > 0) {
        subscriptionOptions.basePrice = domPrice;
      }
    }

    // Écouter les changements de combinaison produit (PS 1.7+)
    if (typeof window.prestashop !== 'undefined') {
      window.prestashop.on('updatedProduct', handlePrestaShopPriceUpdate);
    }

    // Fallback pour les changements d'attributs (anciens thèmes)
    setupAttributeListeners();

    // Premier rendu des prix des options d'abonnement
    updateAllPrices(subscriptionOptions.basePrice);
  }

  /**
   * Lit le prix principal du produit depuis le DOM.
   * À n'utiliser QU'en mode net (en mode gross ce prix inclut les règles de
   * prix PS, ce qui donne une valeur incorrecte pour le calcul d'abonnement).
   */
  function readPrimaryPriceFromDom() {
    var priceElements = [
      '.current-price:not(.frequency-option .current-price)',
      '.product-price',
      '.price',
      '[data-field="price"]',
      '.product-price-and-shipping .price',
      '#our_price_display'
    ];

    for (var i = 0; i < priceElements.length; i++) {
      var element = document.querySelector(priceElements[i]);
      if (!element) continue;

      var priceText = element.textContent || element.innerText || element.getAttribute('content');
      if (!priceText) continue;

      // Extraction du prix numérique du texte (gère les virgules FR et points US)
      var priceMatch = priceText.replace(/[^\d,.-]/g, '').replace(',', '.');
      var price = parseFloat(priceMatch);
      if (!isNaN(price) && price > 0) {
        return price;
      }
    }

    return 0;
  }

  /**
   * Récupère le prix brut d'une combinaison depuis la map pré-calculée serveur.
   * @param {number} idProductAttribute
   * @returns {number|null} Le prix ou null si non trouvé
   */
  function getCombinationPrice(idProductAttribute) {
    var id = parseInt(idProductAttribute, 10) || 0;
    if (subscriptionOptions.combinationPrices.hasOwnProperty(id)) {
      return parseFloat(subscriptionOptions.combinationPrices[id]);
    }
    // Fallback sur le prix sans combinaison
    if (subscriptionOptions.combinationPrices.hasOwnProperty(0)) {
      return parseFloat(subscriptionOptions.combinationPrices[0]);
    }
    return null;
  }

  /**
   * Met à jour tous les prix affichés dans les cartes d'options d'abonnement
   */
  function updateAllPrices(newBasePrice) {
    if (!newBasePrice || isNaN(newBasePrice) || newBasePrice <= 0) {
      return;
    }

    subscriptionOptions.basePrice = newBasePrice;

    // Prix d'achat unique
    var singlePurchaseElements = document.querySelectorAll('.frequency-option .current-price');
    singlePurchaseElements.forEach(function(element) {
      element.textContent = formatPrice(subscriptionOptions.basePrice);
      element.setAttribute('data-base-price', subscriptionOptions.basePrice);
    });

    // Prix avec réduction (fréquences)
    var discountCards = document.querySelectorAll('.frequency-option .discount-card');
    discountCards.forEach(function(card) {
      var originalPriceElement = card.querySelector('.original-price');
      var discountedPriceElement = card.querySelector('.discounted-price');

      if (originalPriceElement && discountedPriceElement) {
        originalPriceElement.textContent = formatPrice(subscriptionOptions.basePrice);
        originalPriceElement.setAttribute('data-base-price', subscriptionOptions.basePrice);

        var discountPercent = parseFloat(discountedPriceElement.getAttribute('data-discount-percent')) || 0;
        var discountPrice = parseFloat(discountedPriceElement.getAttribute('data-discount-price')) || 0;

        var discountedPrice;
        if (discountPercent > 0) {
          discountedPrice = subscriptionOptions.basePrice * (1 - discountPercent / 100);
        } else {
          discountedPrice = subscriptionOptions.basePrice - discountPrice;
        }
        discountedPrice = Math.max(0, discountedPrice);

        discountedPriceElement.textContent = formatPrice(discountedPrice);
        discountedPriceElement.setAttribute('data-base-price', subscriptionOptions.basePrice);
      }
    });
  }

  /**
   * Formate le prix selon la devise et la locale de la boutique
   */
  function formatPrice(price) {
    try {
      return new Intl.NumberFormat(subscriptionOptions.locale, {
        style: 'currency',
        currency: subscriptionOptions.currencyCode,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(price);
    } catch (e) {
      return price.toFixed(2) + ' ' + subscriptionOptions.currencyCode;
    }
  }

  /**
   * Gère l'événement "updatedProduct" émis par PrestaShop lors d'un changement
   * de combinaison (sélection d'une déclinaison).
   *
   * - Mode gross : lit le prix brut de la nouvelle combinaison depuis la map
   *   pré-calculée serveur.
   * - Mode net   : utilise product_prices.price_amount fourni par PS (qui
   *   reflète les règles de prix en mode net).
   */
  function handlePrestaShopPriceUpdate(event) {
    if (!event) return;

    var newPrice = 0;

    if (subscriptionOptions.priceMode === 'gross') {
      var idPa = event.id_product_attribute || (event.product && event.product.id_product_attribute) || 0;
      var grossPrice = getCombinationPrice(idPa);
      if (grossPrice !== null && grossPrice > 0) {
        newPrice = grossPrice;
      }
    } else if (event.product_prices) {
      if (event.product_prices.price_amount) {
        newPrice = parseFloat(event.product_prices.price_amount);
      } else if (event.product_prices.price) {
        newPrice = parseFloat(event.product_prices.price);
      } else if (event.product_prices.regular_price) {
        newPrice = parseFloat(event.product_prices.regular_price);
      }
    }

    if (!isNaN(newPrice) && newPrice > 0) {
      updateAllPrices(newPrice);
    }
  }

  /**
   * Fallback pour les thèmes qui n'émettent pas l'événement updatedProduct :
   * écoute directement les changements sur les inputs d'attributs.
   */
  function setupAttributeListeners() {
    var productAttributeSelects = document.querySelectorAll(
      'select[name*="group"], input[name*="group"], input[type="radio"][name*="group"]'
    );
    productAttributeSelects.forEach(function(element) {
      element.addEventListener('change', function() {
        setTimeout(function() {
          if (subscriptionOptions.priceMode === 'net') {
            var domPrice = readPrimaryPriceFromDom();
            if (domPrice > 0) {
              updateAllPrices(domPrice);
            }
          }
          // En mode gross, on se repose exclusivement sur l'événement
          // updatedProduct de PS qui nous donne id_product_attribute.
          // Si le thème ne l'émet pas, la map combinationPrices ne sera pas
          // utilisée et le prix restera sur la valeur initiale — mieux que
          // d'afficher un prix faux.
        }, 500);
      });
    });
  }

  /**
   * Parse le JSON des prix par combinaison depuis le <script> adjacent au
   * conteneur .ciklik-subscription-options
   */
  function parseCombinationPrices(container) {
    var script = container.querySelector('script.ciklik-combination-prices');
    if (!script) return {};
    try {
      return JSON.parse(script.textContent || script.innerText || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  /**
   * Auto-initialisation
   */
  function autoInit() {
    var container = document.querySelector('.ciklik-subscription-options');
    if (!container) return;

    var config = {
      currencyCode: container.getAttribute('data-currency-code') || 'EUR',
      locale: container.getAttribute('data-locale') || 'fr',
      basePrice: parseFloat(container.getAttribute('data-base-price')) || 0,
      priceMode: container.getAttribute('data-price-mode') || 'net',
      productId: parseInt(container.getAttribute('data-product-id'), 10) || 0,
      combinationPrices: parseCombinationPrices(container)
    };

    initSubscriptionOptions(config);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  // API publique
  window.CiklikSubscriptionOptions = {
    init: initSubscriptionOptions,
    updatePrices: updateAllPrices,
    getBasePrice: function() { return subscriptionOptions.basePrice; }
  };
})();
