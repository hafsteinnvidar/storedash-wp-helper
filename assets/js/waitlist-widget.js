/**
 * StoreDash Waitlist Widget JavaScript
 *
 * Handles form submission and modal functionality for back-in-stock notifications.
 * Zero dependencies — no jQuery required.
 */

(function () {
	'use strict';

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function isValidEmail(email) {
		return EMAIL_RE.test(email);
	}

	function showMessage(el, message, type) {
		el.className = 'storedash-waitlist-message ' + type;
		el.textContent = message;
		el.style.display = '';
		el.classList.add('storedash-waitlist-message--visible');
	}

	function hideMessage(el) {
		el.classList.remove('storedash-waitlist-message--visible');
		el.style.display = 'none';
	}

	function openModal(modal) {
		if (!modal) return;

		// NOTE: `_ts` is deliberately NOT reset here.
		//
		// It seeds a server-side bot trap that rejects submissions arriving less
		// than 3 seconds after the form was rendered. Re-stamping it on open made
		// that window measure "time since the modal opened" instead, so a real
		// shopper with an autofilled email who clicked through quickly tripped the
		// trap — and the trap responds with a SUCCESS message while saving
		// nothing. The signup vanished silently.
		//
		// Set once at init(), from page render, and left alone.

		modal.style.display = 'block';
		void modal.offsetHeight;
		modal.classList.add('storedash-waitlist-modal--visible');
		document.body.style.overflow = 'hidden';
	}

	function closeModal(modal) {
		if (!modal) return;
		modal.classList.remove('storedash-waitlist-modal--visible');
		document.body.style.overflow = '';

		function onEnd() {
			modal.removeEventListener('transitionend', onEnd);
			if (!modal.classList.contains('storedash-waitlist-modal--visible')) {
				modal.style.display = 'none';
			}
		}
		modal.addEventListener('transitionend', onEnd);
	}

	function closeAllVisibleModals() {
		var modals = document.querySelectorAll('.storedash-waitlist-modal--visible');
		for (var i = 0; i < modals.length; i++) {
			closeModal(modals[i]);
		}
	}

	function submitForm(form) {
		var button  = form.querySelector('.storedash-waitlist-submit');
		var msgEl   = form.querySelector('.storedash-waitlist-message');
		var widget  = form.closest('.storedash-waitlist-widget');

		var productId     = form.dataset.productId   || (widget && widget.dataset.productId)   || '';
		var variationId   = form.dataset.variationId  || (widget && widget.dataset.variationId) || 0;
		var customerName  = (form.querySelector('input[name="customer_name"]')  || {}).value || '';
		var customerEmail = (form.querySelector('input[name="customer_email"]') || {}).value || '';
		var customerPhone = (form.querySelector('input[name="customer_phone"]') || {}).value || '';

		// Marketing consent (ADR-019). Absent unless the merchant enabled the
		// checkbox; never gates submission — the back-in-stock notification is
		// transactional and must send regardless of marketing preference.
		var consentEl = form.querySelector('input[name="marketing_optin"]');
		var marketingOptin = consentEl && consentEl.checked ? '1' : '';

		var successMsg    = form.dataset.successMessage  || storedashWaitlist.strings.success    || 'Thank you!';
		var submittingTxt = form.dataset.submittingText   || storedashWaitlist.strings.submitting || 'Submitting...';

		// Honeypot
		var honeypot = form.querySelector('input[name="website"]');
		if (honeypot && honeypot.value) {
			showMessage(msgEl, successMsg, 'success');
			return;
		}

		var productType = widget ? widget.dataset.productType : '';
		if (productType === 'variable' && (!variationId || variationId === '0')) {
			showMessage(msgEl, storedashWaitlist.strings.error_variation, 'error');
			return;
		}

		if (!customerEmail || !isValidEmail(customerEmail)) {
			showMessage(msgEl, storedashWaitlist.strings.error_email, 'error');
			return;
		}

		var originalText = button.dataset.originalText || button.textContent;
		button.disabled = true;
		button.textContent = submittingTxt;
		hideMessage(msgEl);

		// Forwarded verbatim — both are stamped server-side at render and the
		// hash is an HMAC over the timestamp, so altering either here only
		// guarantees rejection.
		var tsInput = form.querySelector('input[name="_ts"]');
		var tshInput = form.querySelector('input[name="_tsh"]');

		var body = new URLSearchParams({
			action: 'storedash_waitlist_submit',
			nonce: storedashWaitlist.nonce,
			product_id: productId,
			variation_id: variationId,
			customer_name: customerName,
			customer_email: customerEmail,
			customer_phone: customerPhone,
			marketing_optin: marketingOptin,
			_ts: tsInput ? tsInput.value : '',
			_tsh: tshInput ? tshInput.value : ''
		});

		fetch(storedashWaitlist.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) {
				// The handler uses real HTTP statuses (400/409/429), but the
				// body still carries the human-readable message ("You're
				// already on the waitlist", "Please try again"). Throwing on
				// !res.ok here used to discard all of them and show the
				// generic error instead — parse the JSON either way.
				return res.json();
			})
			.then(function (response) {
				if (response.success) {
					showMessage(msgEl, successMsg || response.data.message, 'success');
					form.reset();

					var modal = form.closest('.storedash-waitlist-modal');
					if (modal && modal.classList.contains('storedash-waitlist-modal--visible')) {
						setTimeout(function () { closeModal(modal); }, 2000);
					}
				} else {
					showMessage(
						msgEl,
						(response.data && response.data.message) || storedashWaitlist.strings.error_general,
						'error'
					);
				}
			})
			.catch(function () {
				showMessage(msgEl, storedashWaitlist.strings.error_general, 'error');
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = originalText;
			});
	}

	function updateWidgetVariation(widget, variationId) {
		widget.dataset.variationId = variationId;
		var forms = widget.querySelectorAll('.storedash-waitlist-form-fields');
		for (var i = 0; i < forms.length; i++) {
			forms[i].dataset.variationId = variationId;
		}
	}

	function initVariationDropdown(widget) {
		var selects = widget.querySelectorAll('.storedash-waitlist-variation-select');
		for (var i = 0; i < selects.length; i++) {
			var select = selects[i];
			if (select.options.length === 1) {
				updateWidgetVariation(widget, select.options[0].value);
			}
			select.addEventListener('change', (function (w) {
				return function (e) { updateWidgetVariation(w, e.target.value); };
			})(widget));
		}
		var forms = widget.querySelectorAll('.storedash-waitlist-form-fields');
		for (var j = 0; j < forms.length; j++) {
			forms[j].addEventListener('reset', (function (w) {
				return function () {
					var sel = w.querySelector('.storedash-waitlist-variation-select');
					if (sel && sel.options.length > 1) {
						sel.selectedIndex = 0;
						updateWidgetVariation(w, 0);
					}
				};
			})(widget));
		}
	}

	function initVariationListeners() {
		var variableWidgets = document.querySelectorAll(
			'.storedash-waitlist-widget[data-product-type="variable"]'
		);
		for (var i = 0; i < variableWidgets.length; i++) {
			initVariationDropdown(variableWidgets[i]);
		}
	}

	/**
	 * Replace the page's baked-in credentials with live ones.
	 *
	 * Full-page caching freezes the nonce (and stamp) that PHP rendered into
	 * the HTML; past the nonce lifetime every submission from that cached page
	 * would fail verification. admin-ajax.php is never page-cached, so one
	 * background request at init keeps arbitrarily old cached HTML working —
	 * and re-arms the timing trap with a fresh stamp.
	 *
	 * MUST only run at page init, never on modal open or focus: re-stamping on
	 * interaction restarts the server's bot-timing window at the exact moment
	 * a fast human is about to submit (that was a real, shipped bug).
	 *
	 * Fails soft: if the request errors, the server-rendered values stay in
	 * place — correct on any uncached page.
	 */
	function refreshCredentials() {
		fetch(storedashWaitlist.ajax_url + '?action=storedash_waitlist_refresh', {
			method: 'GET',
			credentials: 'same-origin'
		})
			.then(function (res) {
				if (!res.ok) throw new Error(res.status);
				return res.json();
			})
			.then(function (response) {
				if (!response.success || !response.data) return;

				if (response.data.nonce) {
					storedashWaitlist.nonce = response.data.nonce;
				}

				var tsInputs = document.querySelectorAll('.storedash-waitlist-form-fields input[name="_ts"]');
				var tshInputs = document.querySelectorAll('.storedash-waitlist-form-fields input[name="_tsh"]');
				for (var i = 0; i < tsInputs.length; i++) {
					if (response.data.ts) tsInputs[i].value = response.data.ts;
				}
				for (var j = 0; j < tshInputs.length; j++) {
					if (response.data.tsh) tshInputs[j].value = response.data.tsh;
				}
			})
			.catch(function () {
				/* keep server-rendered credentials */
			});
	}

	function init() {
		if (document.querySelector('.storedash-waitlist-form-fields')) {
			refreshCredentials();
		}

		var buttons = document.querySelectorAll('.storedash-waitlist-submit');
		for (var i = 0; i < buttons.length; i++) {
			buttons[i].dataset.originalText = buttons[i].textContent;
		}

		// `_ts` is stamped and signed by PHP at render time; this script must not
		// touch it. Overwriting it here previously restarted the server's
		// bot-timing window from whenever the script happened to run.

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.storedash-waitlist-trigger');
			if (trigger) {
				e.preventDefault();
				var targetId = trigger.dataset.target;
				var modal = document.getElementById(targetId);
				openModal(modal);
				return;
			}

			var closeBtn = e.target.closest('.storedash-waitlist-close');
			if (closeBtn) {
				var parentModal = closeBtn.closest('.storedash-waitlist-modal');
				closeModal(parentModal);
				return;
			}

			if (e.target.classList.contains('storedash-waitlist-modal')) {
				closeModal(e.target);
			}
		});

		document.addEventListener('submit', function (e) {
			var form = e.target.closest('.storedash-waitlist-form-fields');
			if (form) {
				e.preventDefault();
				submitForm(form);
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeAllVisibleModals();
		});

		initVariationListeners();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
