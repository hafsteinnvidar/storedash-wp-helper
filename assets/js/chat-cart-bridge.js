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
 * - Time-window dedup prevents accidental double-adds
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

  function handleAddToCart(data) {
    var productId = parseInt(data.productId, 10);
    var quantity = Math.min(Math.max(parseInt(data.quantity, 10) || 1, 1), 10);
    var variationId = data.variationId ? parseInt(data.variationId, 10) : 0;

    if (!productId || isNaN(productId)) {
      return;
    }

    // Deduplicate — drop identical requests inside a short window
    var actionKey = productId + '-' + quantity + '-' + variationId;
    var now = Date.now();
    if (lastActions[actionKey] && now - lastActions[actionKey] < DEDUP_WINDOW_MS) {
      return;
    }
    lastActions[actionKey] = now;

    var body = {
      // WooCommerce Store API expects the variation ID as 'id' for variable products
      id: variationId || productId,
      quantity: quantity,
    };

    ensureFreshNonce().then(function () {
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
              });
          }

          // Trigger WooCommerce cart fragment refresh so the mini-cart updates
          if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('wc_fragment_refresh');
            jQuery(document.body).trigger('added_to_cart');
          }
          postResult(true, data, '');
        })
        .catch(function (err) {
          console.warn('[StoreDash Chat] Add to cart error:', err);
          postResult(false, data, '');
        });
    });
  }
})();
