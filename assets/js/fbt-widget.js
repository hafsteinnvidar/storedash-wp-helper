/**
 * FBT (Frequently Bought Together) widget — hook-mode behaviour.
 *
 * Binds to the markup produced by FBT_Renderer:
 *   - recomputes the "extras" and grand-total rows on checkbox change
 *     (server-rendered numbers stay correct without a round-trip), and
 *   - drives the standalone "Add selected to cart" button through the
 *     `storedash_fbt_add_bundle` admin-ajax action.
 *
 * The initialisation guard (`_storedashFBTInitialized`) is deliberately the
 * SAME flag the storedash-essentials fbt-widget.js sets: if both plugins are
 * active and both scripts load on a page, whichever runs first owns the
 * container and the second is a no-op — never a double-binding.
 *
 * Price formatting mirrors the essentials widget: WooCommerce's accounting.js
 * + woocommerce_price_format when present, a manual fallback otherwise.
 */

(function () {
	'use strict';

	var config = window.storedashFbt || {};

	function formatPrice(amount) {
		var fmt = typeof woocommerce_price_format !== 'undefined' ? woocommerce_price_format : null;

		if (typeof accounting !== 'undefined' && fmt) {
			try {
				return accounting.formatMoney(amount, {
					symbol: fmt.currency_symbol || '',
					decimal: fmt.decimal_separator || '.',
					thousand: fmt.thousand_separator || ',',
					precision: parseInt(fmt.decimals, 10) || 0,
					format: fmt.price_format || '%v %s'
				});
			} catch (e) {
				/* fall through to manual formatting */
			}
		}

		var precision = fmt && fmt.decimals !== undefined ? parseInt(fmt.decimals, 10) : 0;
		var thousandSep = (fmt && fmt.thousand_separator) || ',';
		var symbol = (fmt && fmt.currency_symbol) || '';
		var pattern = (fmt && fmt.price_format) || '%v %s';

		var formatted = amount.toFixed(precision).replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);

		return pattern.replace('%v', formatted).replace('%s', symbol).trim();
	}

	function FBTWidget(element) {
		if (!element) return;

		this.element = element;
		this.productId = parseInt(element.dataset.productId, 10) || 0;
		this.discount = parseFloat(element.dataset.discount) || 0;

		element._fbtWidgetInstance = this;

		this.bindEvents();
		this.updateTotal();
	}

	FBTWidget.prototype.bindEvents = function () {
		var self = this;

		this.element.addEventListener('change', function (e) {
			if (e.target.classList.contains('storedash-fbt-checkbox')) {
				self.updateTotal();
			}
		});

		var button = this.element.querySelector('.storedash-fbt-add-button');
		if (button) {
			button.addEventListener('click', function () {
				self.addToCart(button);
			});
		}
	};

	/**
	 * Selected companions as [{ id, qty }] — variation id when present.
	 * Public API, same contract as the essentials widget's
	 * getSelectedProducts(): the essentials Add-to-Cart widget calls it when
	 * it owns the container.
	 */
	FBTWidget.prototype.getSelectedProducts = function () {
		var selected = [];

		this.element
			.querySelectorAll('.storedash-fbt-checkbox:checked')
			.forEach(function (checkbox) {
				var item = checkbox.closest('.storedash-fbt-compact-item');
				if (!item) return;

				var variationId = parseInt(item.dataset.variationId, 10) || 0;
				var productId = parseInt(item.dataset.productId, 10) || 0;

				selected.push({
					productId: variationId > 0 ? variationId : productId,
					quantity: parseInt(item.dataset.quantity, 10) || 1
				});
			});

		return selected;
	};

	FBTWidget.prototype.getDiscount = function () {
		return this.discount;
	};

	FBTWidget.prototype.updateTotal = function () {
		var itemsTotalEl = this.element.querySelector('.storedash-fbt-items-total');
		var grandTotalEl = this.element.querySelector('.storedash-fbt-total-price');

		if (!itemsTotalEl && !grandTotalEl) return;

		var mainPrice = grandTotalEl ? parseFloat(grandTotalEl.dataset.mainPrice) || 0 : 0;
		var multiplier = this.discount > 0 ? (100 - this.discount) / 100 : 1;
		var itemsTotal = 0;

		this.element
			.querySelectorAll('.storedash-fbt-checkbox:checked')
			.forEach(function (checkbox) {
				var item = checkbox.closest('.storedash-fbt-compact-item');
				if (item) {
					itemsTotal += parseFloat(item.dataset.price) || 0;
				}
			});

		var itemsDiscounted = itemsTotal * multiplier;
		var hasItems = itemsTotal > 0.009;

		if (itemsTotalEl) {
			if (this.discount > 0 && hasItems) {
				itemsTotalEl.innerHTML =
					'<del>' + formatPrice(itemsTotal) + '</del> ' + formatPrice(itemsDiscounted);
			} else {
				itemsTotalEl.textContent = formatPrice(itemsTotal);
			}

			var itemsRow = this.element.querySelector('.storedash-fbt-items-total-row');
			if (itemsRow) itemsRow.classList.toggle('storedash-fbt-hidden', !hasItems);
		}

		if (grandTotalEl) {
			grandTotalEl.textContent = formatPrice(mainPrice + itemsDiscounted);

			var totalRow = this.element.querySelector('.storedash-fbt-total');
			if (totalRow) totalRow.classList.toggle('storedash-fbt-hidden', !hasItems);
		}
	};

	FBTWidget.prototype.addToCart = function (button) {
		var self = this;
		var messageEl = this.element.querySelector('.storedash-fbt-message');

		var items = this.getSelectedProducts().map(function (item) {
			return { id: item.productId, qty: item.quantity };
		});

		button.disabled = true;
		button.textContent = button.dataset.addingLabel || button.dataset.label || '…';
		if (messageEl) messageEl.style.display = 'none';

		var body = new URLSearchParams();
		body.set('action', 'storedash_fbt_add_bundle');
		body.set('nonce', config.nonce || '');
		body.set('product_id', String(this.productId));
		body.set('items', JSON.stringify(items));

		fetch(config.ajax_url || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || '');
				}

				self.showMessage(messageEl, messageEl ? messageEl.dataset.addedMessage : '', true);

				// Let the theme refresh mini-cart fragments where it can.
				if (window.jQuery) {
					window
						.jQuery(document.body)
						.trigger('wc_fragment_refresh')
						.trigger('added_to_cart', [{}, json.data.cart_hash || '', window.jQuery(button)]);
				}
			})
			.catch(function (error) {
				var fallback = (config.strings && config.strings.error_general) || 'Error';
				self.showMessage(messageEl, error.message || fallback, false);
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = button.dataset.label || '';
			});
	};

	FBTWidget.prototype.showMessage = function (messageEl, text, success) {
		if (!messageEl || !text) return;

		messageEl.textContent = text;
		messageEl.classList.toggle('storedash-fbt-message--success', success);
		messageEl.classList.toggle('storedash-fbt-message--error', !success);
		messageEl.style.display = '';
	};

	function initAll() {
		document
			.querySelectorAll('.storedash-fbt-widget-container')
			.forEach(function (container) {
				if (!container._storedashFBTInitialized) {
					container._storedashFBTInitialized = true;
					new FBTWidget(container);
				}
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
