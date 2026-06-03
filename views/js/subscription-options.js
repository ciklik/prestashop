/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

/**
 * Gestion des options d'abonnement sur la page produit
 *
 * Deux prix distincts sont pilotés côté JS :
 *  - displayPrice : prix « Achat unique » affiché — toujours le prix panier
 *    (avec les règles de prix PrestaShop appliquées). Mis à jour depuis
 *    product_prices.price_amount sur l'événement updatedProduct, ou depuis le
 *    DOM principal en fallback.
 *  - subscriptionBasePrice : base de calcul de la réduction d'abonnement —
 *    soit gross (catalogue brut, sans règles de prix PS), soit net (= prix
 *    panier), selon l'option « Frequency discount calculation base ». En mode
 *    gross, la valeur de chaque combinaison est lue dans la map pré-calculée
 *    serveur (le DOM principal contient toujours le prix net, donc inutilisable
 *    comme source en gross).
 */

(function() {
  'use strict';

  var subscriptionOptions = {
    currencyCode: 'EUR',
    locale: 'fr',
    displayPrice: 0,
    subscriptionBasePrice: 0,
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

    // En mode net, on aligne le prix d'affichage sur le DOM principal au chargement
    // (au cas où des modules tiers l'auraient modifié après le rendu serveur).
    // La base d'abonnement reste identique au prix d'affichage en mode net.
    // En mode gross, on garde les valeurs serveur — la map combinationPrices est la
    // seule source fiable pour la base d'abonnement.
    if (subscriptionOptions.priceMode === 'net') {
      var domPrice = readPrimaryPriceFromDom();
      if (domPrice > 0) {
        subscriptionOptions.displayPrice = domPrice;
        subscriptionOptions.subscriptionBasePrice = domPrice;
      }
    }

    // Écouter les changements de combinaison produit (PS 1.7+)
    if (typeof window.prestashop !== 'undefined') {
      window.prestashop.on('updatedProduct', handlePrestaShopPriceUpdate);
    }

    // Fallback pour les changements d'attributs (anciens thèmes)
    setupAttributeListeners();

    // Écouter les changements sur les radios « Achat unique » / « Abonnement … »
    // pour propager la sélection au prix principal du thème.
    setupRadioListeners();

    // Premier rendu
    updateAllPrices(subscriptionOptions.displayPrice, subscriptionOptions.subscriptionBasePrice);
  }

  /**
   * Lit le prix principal du produit depuis le DOM (prix panier, toujours net).
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
   * Récupère la base de calcul abonnement d'une combinaison depuis la map serveur.
   * @param {number} idProductAttribute
   * @returns {number|null} Le prix ou null si non trouvé
   */
  function getCombinationPrice(idProductAttribute) {
    var id = parseInt(idProductAttribute, 10) || 0;
    if (subscriptionOptions.combinationPrices.hasOwnProperty(id)) {
      return parseFloat(subscriptionOptions.combinationPrices[id]);
    }
    if (subscriptionOptions.combinationPrices.hasOwnProperty(0)) {
      return parseFloat(subscriptionOptions.combinationPrices[0]);
    }
    return null;
  }

  /**
   * Met à jour tous les prix affichés.
   *
   * @param {number} newDisplay  Prix « Achat unique » (panier).
   * @param {number} newSubBase  Base de calcul abonnement (gross ou net).
   *                             Si non fourni, prend la valeur de newDisplay.
   */
  function updateAllPrices(newDisplay, newSubBase) {
    if (!newDisplay || isNaN(newDisplay) || newDisplay <= 0) {
      return;
    }
    if (typeof newSubBase === 'undefined' || !newSubBase || isNaN(newSubBase) || newSubBase <= 0) {
      newSubBase = newDisplay;
    }

    subscriptionOptions.displayPrice = newDisplay;
    subscriptionOptions.subscriptionBasePrice = newSubBase;

    // « Achat unique » : prix panier.
    var singlePurchaseElements = document.querySelectorAll('.frequency-option .current-price');
    singlePurchaseElements.forEach(function(element) {
      element.textContent = formatPrice(newDisplay);
      element.setAttribute('data-base-price', newDisplay);
    });

    // Options d'abonnement avec réduction : base = newSubBase.
    var discountCards = document.querySelectorAll('.frequency-option .discount-card');
    discountCards.forEach(function(card) {
      var originalPriceElement = card.querySelector('.original-price');
      var discountedPriceElement = card.querySelector('.discounted-price');

      if (originalPriceElement && discountedPriceElement) {
        originalPriceElement.textContent = formatPrice(newSubBase);
        originalPriceElement.setAttribute('data-base-price', newSubBase);

        var discountPercent = parseFloat(discountedPriceElement.getAttribute('data-discount-percent')) || 0;
        var discountPrice = parseFloat(discountedPriceElement.getAttribute('data-discount-price')) || 0;

        var discountedPrice;
        if (discountPercent > 0) {
          discountedPrice = newSubBase * (1 - discountPercent / 100);
        } else {
          discountedPrice = newSubBase - discountPrice;
        }
        discountedPrice = Math.max(0, discountedPrice);

        discountedPriceElement.textContent = formatPrice(discountedPrice);
        discountedPriceElement.setAttribute('data-base-price', newSubBase);
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
   * Gère l'événement « updatedProduct » émis par PrestaShop lors d'un changement
   * de combinaison.
   *
   * - displayPrice (achat unique) : toujours product_prices.price_amount fourni
   *   par PS, qui reflète les règles de prix.
   * - subscriptionBasePrice : depuis la map serveur en mode gross (le DOM
   *   contient toujours le net), sinon = displayPrice.
   */
  function handlePrestaShopPriceUpdate(event) {
    if (!event) return;

    var newDisplay = 0;
    if (event.product_prices) {
      if (event.product_prices.price_amount) {
        newDisplay = parseFloat(event.product_prices.price_amount);
      } else if (event.product_prices.price) {
        newDisplay = parseFloat(event.product_prices.price);
      } else if (event.product_prices.regular_price) {
        newDisplay = parseFloat(event.product_prices.regular_price);
      }
    }

    var newSubBase;
    if (subscriptionOptions.priceMode === 'gross') {
      var idPa = event.id_product_attribute || (event.product && event.product.id_product_attribute) || 0;
      var grossPrice = getCombinationPrice(idPa);
      newSubBase = (grossPrice !== null && grossPrice > 0) ? grossPrice : newDisplay;
    } else {
      newSubBase = newDisplay;
    }

    if (!isNaN(newDisplay) && newDisplay > 0) {
      updateAllPrices(newDisplay, newSubBase);
    }
  }

  /**
   * Fallback pour les thèmes qui n'émettent pas l'événement updatedProduct :
   * écoute directement les changements sur les inputs d'attributs.
   *
   * En mode gross, on s'appuie exclusivement sur updatedProduct (qui donne
   * id_product_attribute). Sans cet événement, la base d'abonnement reste sur
   * sa valeur initiale — préférable à un prix faux.
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
              updateAllPrices(domPrice, domPrice);
            }
          }
        }, 500);
      });
    });
  }

  /**
   * Écoute les changements de sélection sur les radios « Achat unique » /
   * « Abonnement … » et propage le prix de l'option sélectionnée :
   *  - (a) en best-effort, écriture du prix dans les sélecteurs « prix principal »
   *    standards du thème (mêmes sélecteurs que readPrimaryPriceFromDom),
   *  - (b) émission d'un événement DOM `ciklik:option-selected` qu'un thème
   *    custom peut écouter pour appliquer sa propre logique de mise à jour.
   */
  function setupRadioListeners() {
    var radios = document.querySelectorAll('input[name="ciklik_frequency"]');
    radios.forEach(function(radio) {
      radio.addEventListener('change', function() {
        if (radio.checked) {
          handleOptionSelected(radio);
        }
      });
    });
  }

  function handleOptionSelected(radio) {
    var card = radio.closest('.frequency-card');
    if (!card) return;

    // Pour une carte avec réduction, le prix à montrer est .discounted-price ;
    // sinon (achat unique ou fréquence sans réduction), c'est .current-price.
    var priceElement = card.querySelector('.discounted-price') || card.querySelector('.current-price');
    if (!priceElement) return;

    var formattedPrice = (priceElement.textContent || '').trim();
    if (!formattedPrice) return;

    // (a) best-effort : on écrit dans les sélecteurs prix principal du thème.
    writePrimaryPriceToDom(formattedPrice);

    // (b) événement custom pour les thèmes qui veulent piloter leur propre rendu.
    if (typeof window.CustomEvent === 'function') {
      var detail = {
        frequencyId: parseInt(radio.value, 10) || 0,
        formattedPrice: formattedPrice,
        radio: radio,
        card: card
      };
      document.dispatchEvent(new CustomEvent('ciklik:option-selected', { detail: detail, bubbles: true }));
    }
  }

  /**
   * Écrit le prix formaté dans les sélecteurs « prix principal » connus du thème.
   * Best-effort : on saute les éléments à structure HTML complexe (children) pour
   * ne pas casser leur markup. Les thèmes custom doivent utiliser l'événement
   * `ciklik:option-selected` pour piloter eux-mêmes le rendu.
   */
  function writePrimaryPriceToDom(formattedPrice) {
    var priceSelectors = [
      '.current-price:not(.frequency-option .current-price)',
      '.product-price-and-shipping .price',
      '.product-prices .product-price',
      '.product-price',
      '[data-field="price"]',
      '#our_price_display'
    ];

    for (var i = 0; i < priceSelectors.length; i++) {
      var elements = document.querySelectorAll(priceSelectors[i]);
      elements.forEach(function(el) {
        // Ne pas écrire dans notre propre bloc (boucle).
        if (el.closest('.ciklik-subscription-options')) return;
        // Ne pas écraser un markup complexe (préservation du HTML thème).
        if (el.children.length > 0) return;
        el.textContent = formattedPrice;
      });
    }
  }

  /**
   * Parse le JSON des bases d'abonnement par combinaison depuis le <script>
   * adjacent au conteneur .ciklik-subscription-options
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

    var displayBase = parseFloat(container.getAttribute('data-display-base-price')) || 0;
    var subBase = parseFloat(container.getAttribute('data-subscription-base-price')) || displayBase;

    var config = {
      currencyCode: container.getAttribute('data-currency-code') || 'EUR',
      locale: container.getAttribute('data-locale') || 'fr',
      displayPrice: displayBase,
      subscriptionBasePrice: subBase,
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

  // API publique. updatePrices accepte un ou deux arguments (rétrocompat :
  // un seul argument force display = subscription base).
  window.CiklikSubscriptionOptions = {
    init: initSubscriptionOptions,
    updatePrices: function(newDisplay, newSubBase) {
      return updateAllPrices(newDisplay, newSubBase);
    },
    getDisplayPrice: function() { return subscriptionOptions.displayPrice; },
    getSubscriptionBasePrice: function() { return subscriptionOptions.subscriptionBasePrice; },
    // Conservé pour compatibilité — retourne le prix d'affichage.
    getBasePrice: function() { return subscriptionOptions.displayPrice; }
  };
})();
