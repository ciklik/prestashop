/**
 * Gestion des options d'abonnement sur la page produit
 * @author Ciklik SAS
 */

(function() {
  'use strict';
  
  // Configuration globale
  let subscriptionOptions = {
    currencyCode: 'EUR',
    locale: 'fr',
    basePrice: 0,
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
    subscriptionOptions.basePrice = getCurrentPrice();
    
    // Écouter les changements de combinaison produit (PrestaShop 1.7+)
    if (typeof window.prestashop !== 'undefined') {
      window.prestashop.on('updatedProduct', handlePrestaShopPriceUpdate);
    }

    // Observer les changements dans le DOM pour les prix
    setupPriceObserver();

    // Fallback pour les changements d'attributs (anciennes versions)
    setupAttributeListeners();

    // Initialisation après un court délai pour s'assurer que le DOM est prêt
    setTimeout(function() {
      updateAllPrices(subscriptionOptions.basePrice);
    }, 100);
    
    console.log('Subscription options initialized with base price:', subscriptionOptions.basePrice);
  }

  /**
   * Fonction pour obtenir le prix actuel du produit
   */
  function getCurrentPrice() {
    // Essayer d'abord avec les éléments de prix PrestaShop
    const priceElements = [
      '.current-price:not(.frequency-option .current-price)', // Prix principal du produit
      '.product-price',
      '.price',
      '[data-field="price"]',
      '.product-price-and-shipping .price',
      '#our_price_display'
    ];
    
    for (let selector of priceElements) {
      const element = document.querySelector(selector);
      if (element) {
        const priceText = element.textContent || element.innerText || element.getAttribute('content');
        if (priceText) {
          // Extraction du prix numérique du texte
          const priceMatch = priceText.replace(/[^\d,.-]/g, '').replace(',', '.');
          const price = parseFloat(priceMatch);
          if (!isNaN(price) && price > 0) {
            return price;
          }
        }
      }
    }
    
    // Fallback avec le prix par défaut
    return subscriptionOptions.basePrice || 0;
  }

  /**
   * Met à jour tous les prix des options d'abonnement
   */
  function updateAllPrices(newBasePrice) {
    if (!newBasePrice || isNaN(newBasePrice)) {
      newBasePrice = getCurrentPrice();
    }
    
    subscriptionOptions.basePrice = newBasePrice;
    console.log('Updating prices with base price:', subscriptionOptions.basePrice);
    
    // Mise à jour des prix d'achat unique
    const singlePurchaseElements = document.querySelectorAll('.frequency-option .current-price');
    singlePurchaseElements.forEach(function(element) {
      element.textContent = formatPrice(subscriptionOptions.basePrice);
      element.setAttribute('data-base-price', subscriptionOptions.basePrice);
    });

    // Mise à jour des prix avec réduction
    const discountCards = document.querySelectorAll('.frequency-option .discount-card');
    discountCards.forEach(function(card) {
      const originalPriceElement = card.querySelector('.original-price');
      const discountedPriceElement = card.querySelector('.discounted-price');
      
      if (originalPriceElement && discountedPriceElement) {
        // Mise à jour du prix original (barré)
        originalPriceElement.textContent = formatPrice(subscriptionOptions.basePrice);
        originalPriceElement.setAttribute('data-base-price', subscriptionOptions.basePrice);
        
        // Calcul et mise à jour du prix avec réduction
        const discountPercent = parseFloat(discountedPriceElement.getAttribute('data-discount-percent')) || 0;
        const discountPrice = parseFloat(discountedPriceElement.getAttribute('data-discount-price')) || 0;
        
        let discountedPrice;
        if (discountPercent > 0) {
          discountedPrice = subscriptionOptions.basePrice * (1 - discountPercent / 100);
        } else {
          discountedPrice = subscriptionOptions.basePrice - discountPrice;
        }
        
        // S'assurer que le prix ne soit pas négatif
        discountedPrice = Math.max(0, discountedPrice);
        
        discountedPriceElement.textContent = formatPrice(discountedPrice);
        discountedPriceElement.setAttribute('data-base-price', subscriptionOptions.basePrice);
      }
    });
  }

  /**
   * Formate le prix selon les paramètres de localisation
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
   * Gère les événements de mise à jour des prix PrestaShop
   */
  function handlePrestaShopPriceUpdate(event) {
    console.log('PrestaShop updatedProduct event:', event);
    if (event && event.product_prices) {
      let newPrice = 0;
      
      // Essayer différentes propriétés de prix
      if (event.product_prices.price_amount) {
        newPrice = parseFloat(event.product_prices.price_amount);
      } else if (event.product_prices.price) {
        newPrice = parseFloat(event.product_prices.price);
      } else if (event.product_prices.regular_price) {
        newPrice = parseFloat(event.product_prices.regular_price);
      }
      
      if (!isNaN(newPrice) && newPrice > 0) {
        updateAllPrices(newPrice);
      } else {
        // Récupérer le prix depuis le DOM après mise à jour
        setTimeout(function() {
          updateAllPrices(getCurrentPrice());
        }, 100);
      }
    }
  }

  /**
   * Configure l'observateur de changements de prix dans le DOM
   */
  function setupPriceObserver() {
    const priceObserver = new MutationObserver(function(mutations) {
      let priceChanged = false;
      mutations.forEach(function(mutation) {
        if (mutation.type === 'childList' || mutation.type === 'characterData') {
          const target = mutation.target;
          if (target.classList && (
            target.classList.contains('price') || 
            target.classList.contains('product-price') ||
            target.closest('.product-price')
          )) {
            priceChanged = true;
          }
        }
      });
      
      if (priceChanged) {
        setTimeout(function() {
          const newPrice = getCurrentPrice();
          if (newPrice !== subscriptionOptions.basePrice && newPrice > 0) {
            updateAllPrices(newPrice);
          }
        }, 200);
      }
    });

    // Observer les éléments de prix
    const priceContainer = document.querySelector('.product-prices, .product-price, .price-container, #product-price, .product-detail') || document.body;
    priceObserver.observe(priceContainer, {
      childList: true,
      subtree: true,
      characterData: true
    });
  }

  /**
   * Configure les écouteurs d'événements pour les attributs produit
   */
  function setupAttributeListeners() {
    const productAttributeSelects = document.querySelectorAll('select[name*="group"], input[name*="group"], input[type="radio"][name*="group"]');
    productAttributeSelects.forEach(function(element) {
      element.addEventListener('change', function() {
        setTimeout(function() {
          const newPrice = getCurrentPrice();
          if (newPrice > 0) {
            updateAllPrices(newPrice);
          }
        }, 500);
      });
    });
  }

  /**
   * Auto-initialisation des options d'abonnement
   */
  function autoInit() {
    const container = document.querySelector('.ciklik-subscription-options');
    if (container) {
      const config = {
        currencyCode: container.getAttribute('data-currency-code') || 'EUR',
        locale: container.getAttribute('data-locale') || 'fr',
        basePrice: parseFloat(container.getAttribute('data-base-price')) || 0
      };
      
      initSubscriptionOptions(config);
    }
  }

  // Auto-initialisation au chargement du DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  // Expose les fonctions publiques
  window.CiklikSubscriptionOptions = {
    init: initSubscriptionOptions,
    updatePrices: updateAllPrices,
    getCurrentPrice: getCurrentPrice
  };

})(); 