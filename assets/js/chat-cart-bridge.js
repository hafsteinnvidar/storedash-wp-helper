/**
 * StoreDash Chat → WooCommerce Cart Bridge
 *
 * Listens for postMessage events from the chat widget and executes
 * add-to-cart operations via the WooCommerce Store API.
 *
 * Security model:
 * - Only accepts messages from same origin (prevents cross-origin injection)
 * - Only processes 'storedash-chat:action' message type
 * - Never sends price/discount data — WooCommerce resolves pricing server-side
 * - Uses WooCommerce nonce for Store API authentication
 * - WooCommerce validates product existence, stock, and purchasability
 * - Time-window dedup prevents accidental double-adds (a dropped duplicate
 *   still reports the first request's outcome back to the widget)
 *
 * Variations with an "Any …" attribute: the Store API refuses them unless the
 * request carries a value for that attribute in `variation`. The chat only
 * knows the variation ID, so the bridge reads the parent product from the
 * Store API and fills each "Any" attribute with the product's default value,
 * or its only value. If an "Any" attribute has several values and no default,
 * the customer's choice is unknown — the bridge reports failure instead of
 * guessing a size/colour (the widget then points to the product page).
 *
 * The product/variation IDs received here are WooCommerce IDs (the chat
 * service resolves its internal catalog IDs before emitting the action).
 *
 * Nonce handling: the localized nonce is baked into the page HTML, so
 * full-page caches can serve a stale one. Every Store API response carries a
 * fresh `Nonce` header — we bootstrap from GET /cart and keep updating from
 * each response, falling back to the localized nonce.
 */
(function () {
  'use strict';

  var config = window.storedashChatBridge;
  if (!config || !config.nonce) {
    return;
  }

  var STORE_API_BASE = config.storeApiBase || '/wp-json/wc/store/v1';
  var DEDUP_WINDOW_MS = 5000;

  var currentNonce = config.nonce;
  // actionKey -> { at: timestamp, outcome: Promise<boolean> }
  var lastActions = {};

  window.addEventListener('message', function (event) {
    // Security: only accept messages from same origin
    if (event.origin !== window.location.origin) {
      return;
    }

    var data = event.data;
    if (!data || data.type !== 'storedash-chat:action') {
      return;
    }

    if (data.action === 'add-to-cart') {
      handleAddToCart(data);
    }
  });

  function rememberNonce(response) {
    var fresh = response.headers.get('Nonce');
    if (fresh) {
      currentNonce = fresh;
    }
  }

  /**
   * The localized nonce may be stale (page caching). Fetch the cart once to
   * obtain a fresh nonce and establish a Woo session for guests.
   */
  function ensureFreshNonce() {
    return fetch(STORE_API_BASE + '/cart', {
      method: 'GET',
      credentials: 'same-origin',
    })
      .then(function (response) {
        rememberNonce(response);
      })
      .catch(function () {
        // Keep whatever nonce we have; the POST will surface real errors.
      });
  }

  function postResult(success, data, message) {
    try {
      window.postMessage(
        {
          type: 'storedash-chat:action-result',
          action: 'add-to-cart',
          success: !!success,
          productId: data.productId,
          productName: data.productName,
          message: message || '',
        },
        window.location.origin
      );
    } catch (e) {
      // Non-fatal — the item may still have been added.
    }
  }

  function isAnyValue(value) {
    return value === null || value === undefined || value === '';
  }

  /**
   * Resolve the `variation` array for a variation that has "Any" attributes.
   * Resolves to [] when nothing needs sending (or the product can't be read —
   * the add then behaves exactly as before), or null when an "Any" attribute
   * is ambiguous (several values, no default).
   */
  function resolveAnyAttributes(productId, variationId) {
    return fetch(STORE_API_BASE + '/products/' + productId, {
      method: 'GET',
      credentials: 'same-origin',
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (product) {
        if (!product || !product.variations || !product.attributes) {
          return [];
        }
        var variation = null;
        for (var i = 0; i < product.variations.length; i++) {
          if (parseInt(product.variations[i].id, 10) === variationId) {
            variation = product.variations[i];
            break;
          }
        }
        if (!variation || !variation.attributes) {
          return [];
        }

        var result = [];
        for (var j = 0; j < variation.attributes.length; j++) {
          var attr = variation.attributes[j];
          if (!isAnyValue(attr.value)) {
            continue; // Fixed value — the Store API fills it from the variation.
          }
          var parent = null;
          for (var k = 0; k < product.attributes.length; k++) {
            if (product.attributes[k].name === attr.name) {
              parent = product.attributes[k];
              break;
            }
          }
          var terms = (parent && parent.terms) || [];
          var chosen = null;
          for (var m = 0; m < terms.length; m++) {
            if (terms[m].default) {
              chosen = terms[m];
              break;
            }
          }
          if (!chosen && terms.length === 1) {
            chosen = terms[0];
          }
          if (!chosen) {
            return null; // Ambiguous — don't guess the customer's choice.
          }
          result.push({
            // Taxonomy slug (pa_size) for global attributes, label otherwise —
            // both are accepted by the Store API's variation parser.
            attribute: parent.taxonomy || parent.name,
            value: chosen.slug,
          });
        }
        return result;
      })
      .catch(function () {
        return [];
      });
  }

  function handleAddToCart(data) {
    var productId = parseInt(data.productId, 10);
    var quantity = Math.min(Math.max(parseInt(data.quantity, 10) || 1, 1), 10);
    var variationId = data.variationId ? parseInt(data.variationId, 10) : 0;

    if (!productId || isNaN(productId)) {
      return;
    }

    // Deduplicate — an identical request inside a short window is not sent
    // again, but the widget still gets a result: the first request's outcome.
    var actionKey = productId + '-' + quantity + '-' + variationId;
    var now = Date.now();
    var previous = lastActions[actionKey];
    if (previous && now - previous.at < DEDUP_WINDOW_MS) {
      previous.outcome.then(function (success) {
        postResult(success, data, '');
      });
      return;
    }

    var outcome = addToCart(data, productId, quantity, variationId);
    lastActions[actionKey] = { at: now, outcome: outcome };
  }

  /** Resolves to true when the item landed in the cart. Never rejects. */
  function addToCart(data, productId, quantity, variationId) {
    var body = {
      // WooCommerce Store API expects the variation ID as 'id' for variable products
      id: variationId || productId,
      quantity: quantity,
    };

    var attributesReady = variationId
      ? resolveAnyAttributes(productId, variationId)
      : Promise.resolve([]);

    return Promise.all([ensureFreshNonce(), attributesReady]).then(function (results) {
      var variation = results[1];
      if (variation === null) {
        console.warn('[StoreDash Chat] Add to cart skipped: variation has an "Any" attribute with several values.');
        postResult(false, data, '');
        return false;
      }
      if (variation.length) {
        body.variation = variation;
      }

      return fetch(STORE_API_BASE + '/cart/add-item', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Nonce': currentNonce,
        },
        body: JSON.stringify(body),
      })
        .then(function (response) {
          rememberNonce(response);

          if (!response.ok) {
            return response
              .json()
              .catch(function () {
                return {};
              })
              .then(function (err) {
                console.warn('[StoreDash Chat] Add to cart failed:', err.message || err.code || response.status);
                postResult(false, data, err.message || '');
                return false;
              });
          }

          // Trigger WooCommerce cart fragment refresh so the mini-cart updates
          if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('wc_fragment_refresh');
            jQuery(document.body).trigger('added_to_cart');
          }
          postResult(true, data, '');
          return true;
        })
        .catch(function (err) {
          console.warn('[StoreDash Chat] Add to cart error:', err);
          postResult(false, data, '');
          return false;
        });
    });
  }
})();
