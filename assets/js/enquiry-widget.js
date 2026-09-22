/**
 * StoreDash Enquiry Widget JavaScript
 *
 * Handles form submission and modal functionality for product enquiries.
 * Zero dependencies — no jQuery required.
 */

(function () {
	'use strict';

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/**
	 * Whether the nonce refresh has already been requested this page view.
	 */
	var refreshed = false;

	function isValidEmail(email) {
		return EMAIL_RE.test(email);
	}

	function showMessage(el, message, type) {
		el.className = 'storedash-enquiry-message ' + type;
		el.textContent = message;
		el.style.display = '';
		el.classList.add('storedash-enquiry-message--visible');
	}

	function hideMessage(el) {
		el.classList.remove('storedash-enquiry-message--visible');
		el.style.display = 'none';
	}

	/**
	 * Replace the page's baked-in nonce with a live one.
	 *
	 * Full-page caching freezes the nonce PHP rendered into the HTML; past the
	 * nonce lifetime every submission from that cached page fails verification.
	 * admin-ajax.php is never page-cached, so one background request repairs
	 * arbitrarily old cached HTML.
	 *
	 * Two things this deliberately does NOT do:
	 *
	 *   - It never touches `_ts`/`_tsh`. Those are stamped and HMAC-signed by
	 *     PHP at render. Re-stamping them at interaction time would restart the
	 *     server's bot-timing window at the exact moment a fast human is about
	 *     to submit — that was a real, shipped bug that silently discarded a
	 *     waitlist signup while showing a success message. The server endpoint
	 *     returns only a nonce, so there is nothing here to misuse.
	 *
	 *   - It never runs at page init. The enquiry form is on every product page,
	 *     so an init-time call would be one uncached PHP request per product
	 *     pageview. Firing on first interaction instead means only visitors who
	 *     actually engage cost anything.
	 *
	 * Fails soft: if the request errors, the server-rendered nonce stays in
	 * place — correct on any uncached page.
	 */
	function refreshNonce() {
		if (refreshed) return;
		refreshed = true;

		fetch(storedashEnquiry.ajax_url + '?action=storedash_enquiry_refresh', {
			method: 'GET',
			credentials: 'same-origin'
		})
			.then(function (res) {
				if (!res.ok) throw new Error(res.status);
				return res.json();
			})
			.then(function (response) {
				if (response && response.success && response.data && response.data.nonce) {
					storedashEnquiry.nonce = response.data.nonce;
				}
			})
			.catch(function () {
				/* keep the server-rendered nonce */
			});
	}

	function openModal(modal) {
		if (!modal) return;

		// NOTE: `_ts` is deliberately NOT reset here — see refreshNonce().
		// It seeds a server-side trap that rejects submissions arriving less
		// than a few seconds after the form was RENDERED. Re-stamping it on
		// open makes that window measure "time since the modal opened", so a
		// shopper with an autofilled email who clicks through quickly trips it.

		modal.style.display = 'block';
		// Force reflow so the transition actually fires
		void modal.offsetHeight;
		modal.classList.add('storedash-enquiry-modal--visible');
		document.body.style.overflow = 'hidden';
	}

	function closeModal(modal) {
		if (!modal) return;
		modal.classList.remove('storedash-enquiry-modal--visible');
		document.body.style.overflow = '';

		function onEnd() {
			modal.removeEventListener('transitionend', onEnd);
			if (!modal.classList.contains('storedash-enquiry-modal--visible')) {
				modal.style.display = 'none';
			}
		}
		modal.addEventListener('transitionend', onEnd);
	}

	function closeAllVisibleModals() {
		var modals = document.querySelectorAll('.storedash-enquiry-modal--visible');
		for (var i = 0; i < modals.length; i++) {
			closeModal(modals[i]);
		}
	}

	function submitForm(form) {
		var button = form.querySelector('.storedash-enquiry-submit');
		var msgEl  = form.querySelector('.storedash-enquiry-message');
		var widget = form.closest('.storedash-enquiry-widget');

		var productId     = form.dataset.productId   || (widget && widget.dataset.productId)   || '';
		var variationId   = form.dataset.variationId || (widget && widget.dataset.variationId) || 0;
		var customerName  = (form.querySelector('input[name="customer_name"]')  || {}).value || '';
		var customerEmail = (form.querySelector('input[name="customer_email"]') || {}).value || '';
		var customerPhone = (form.querySelector('input[name="customer_phone"]') || {}).value || '';
		var message       = (form.querySelector('textarea[name="message"]')     || {}).value || '';

		// Marketing consent (ADR-019). Absent unless the merchant enabled the
		// checkbox; never gates submission — the merchant's reply is
		// transactional and must send regardless of marketing preference.
		var consentEl = form.querySelector('input[name="marketing_optin"]');
		var marketingOptin = consentEl && consentEl.checked ? '1' : '';

		var successMsg    = form.dataset.successMessage || storedashEnquiry.strings.success    || 'Thank you!';
		var submittingTxt = form.dataset.submittingText  || storedashEnquiry.strings.submitting || 'Sending...';

		// Honeypot
		var honeypot = form.querySelector('input[name="website"]');
		if (honeypot && honeypot.value) {
			showMessage(msgEl, successMsg, 'success');
			return;
		}

		if (!customerEmail || !isValidEmail(customerEmail)) {
			showMessage(msgEl, storedashEnquiry.strings.error_email, 'error');
			return;
		}

		// Matches the server's rule exactly. A stricter client-side minimum
		// rejects with a message ("Please enter a message") that makes no sense
		// to someone who plainly did.
		if (!message.trim()) {
			showMessage(msgEl, storedashEnquiry.strings.error_message, 'error');
			return;
		}

		var originalText = button.dataset.originalText || button.textContent;
		button.disabled = true;
		button.textContent = submittingTxt;
		hideMessage(msgEl);

		// Forwarded verbatim — both are stamped server-side at render and the
		// hash is an HMAC over the timestamp, so altering either here only
		// guarantees rejection.
		var tsInput  = form.querySelector('input[name="_ts"]');
		var tshInput = form.querySelector('input[name="_tsh"]');

		var body = new URLSearchParams({
			action: 'storedash_enquiry_submit',
			nonce: storedashEnquiry.nonce,
			product_id: productId,
			variation_id: variationId,
			customer_name: customerName,
			customer_email: customerEmail,
			customer_phone: customerPhone,
			message: message,
			marketing_optin: marketingOptin,
			_ts: tsInput ? tsInput.value : '',
			_tsh: tshInput ? tshInput.value : ''
		});

		fetch(storedashEnquiry.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) {
				// The handler uses real HTTP statuses (400/404/429), but the
				// body still carries the human-readable message ("Messages
				// cannot contain multiple links", "Please try again").
				// Throwing on !res.ok discards all of them and shows the
				// generic error instead — parse the JSON either way.
				return res.json();
			})
			.then(function (response) {
				if (response.success) {
					showMessage(msgEl, successMsg || response.data.message, 'success');
					form.reset();

					var modal = form.closest('.storedash-enquiry-modal');
					if (modal && modal.classList.contains('storedash-enquiry-modal--visible')) {
						setTimeout(function () { closeModal(modal); }, 2000);
					}
				} else {
					showMessage(
						msgEl,
						(response.data && response.data.message) || storedashEnquiry.strings.error_general,
						'error'
					);
				}
			})
			.catch(function () {
				showMessage(msgEl, storedashEnquiry.strings.error_general, 'error');
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = originalText;
			});
	}

	function init() {
		// Store original button text
		var buttons = document.querySelectorAll('.storedash-enquiry-submit');
		for (var i = 0; i < buttons.length; i++) {
			buttons[i].dataset.originalText = buttons[i].textContent;
		}

		// `_ts` is stamped and signed by PHP at render time; this script must
		// not touch it. Writing it here previously restarted the server's
		// bot-timing window from whenever the script happened to run.

		// All events delegated on document so dynamically-added widgets work
		document.addEventListener('click', function (e) {
			// Modal trigger
			var trigger = e.target.closest('.storedash-enquiry-trigger');
			if (trigger) {
				e.preventDefault();
				refreshNonce();
				var targetId = trigger.dataset.target;
				var modal = document.getElementById(targetId);
				openModal(modal);
				return;
			}

			// Close button
			var closeBtn = e.target.closest('.storedash-enquiry-close');
			if (closeBtn) {
				var parentModal = closeBtn.closest('.storedash-enquiry-modal');
				closeModal(parentModal);
				return;
			}

			// Overlay click (click directly on the modal backdrop, not its children)
			if (e.target.classList.contains('storedash-enquiry-modal')) {
				closeModal(e.target);
			}
		});

		// Inline placements (the product tab, the shortcode) have no trigger
		// button to click, so first focus of a field is the engagement signal
		// there. `focusin` rather than `focus` because focus does not bubble.
		document.addEventListener('focusin', function (e) {
			if (e.target.closest('.storedash-enquiry-form-fields')) {
				refreshNonce();
			}
		});

		// Form submit
		document.addEventListener('submit', function (e) {
			var form = e.target.closest('.storedash-enquiry-form-fields');
			if (form) {
				e.preventDefault();
				submitForm(form);
			}
		});

		// ESC key
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeAllVisibleModals();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
