/**
 * StoreDash Email Signup Widget JavaScript
 *
 * Handles single opt-in email-capture form submission.
 * Zero dependencies — no jQuery required.
 */

(function () {
	'use strict';

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function isValidEmail(email) {
		return EMAIL_RE.test(email);
	}

	function showMessage(el, message, type) {
		if (!el) return;
		el.className = 'storedash-signup-message ' + type;
		el.textContent = message;
		el.style.display = '';
	}

	function hideMessage(el) {
		if (!el) return;
		el.style.display = 'none';
	}

	function submitForm(form) {
		var button = form.querySelector('.storedash-signup-submit');
		var msgEl = form.querySelector('.storedash-signup-message');

		var email = (form.querySelector('input[name="email"]') || {}).value || '';

		var successMsg = form.dataset.successMessage || storedashSignup.strings.success || 'Thanks for subscribing!';
		var submittingTxt = form.dataset.submittingText || storedashSignup.strings.submitting || 'Subscribing...';

		// Honeypot — silently succeed for bots.
		var honeypot = form.querySelector('input[name="website"]');
		if (honeypot && honeypot.value) {
			showMessage(msgEl, successMsg, 'success');
			return;
		}

		if (!email || !isValidEmail(email)) {
			showMessage(msgEl, storedashSignup.strings.error_email, 'error');
			return;
		}

		var nonceField = form.querySelector('input[name="storedash_optin_nonce"]');
		var nonce = nonceField ? nonceField.value : storedashSignup.nonce;

		var originalText = button.dataset.originalText || button.textContent;
		button.disabled = true;
		button.textContent = submittingTxt;
		hideMessage(msgEl);

		var tsInput = form.querySelector('input[name="_ts"]');
		var body = new URLSearchParams({
			action: 'storedash_optin_submit',
			nonce: nonce,
			email: email,
			website: honeypot ? honeypot.value : '',
			_ts: tsInput ? tsInput.value : ''
		});

		fetch(storedashSignup.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (response) {
				if (response && response.success) {
					showMessage(msgEl, successMsg, 'success');
					form.reset();
				} else {
					showMessage(
						msgEl,
						(response && response.data && response.data.message) || storedashSignup.strings.error_general,
						'error'
					);
				}
			})
			.catch(function () {
				showMessage(msgEl, storedashSignup.strings.error_general, 'error');
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = originalText;
			});
	}

	function init() {
		// Store original button text + stamp render time on every form.
		var buttons = document.querySelectorAll('.storedash-signup-submit');
		for (var i = 0; i < buttons.length; i++) {
			buttons[i].dataset.originalText = buttons[i].textContent;
		}

		var forms = document.querySelectorAll('.storedash-signup-form');
		for (var j = 0; j < forms.length; j++) {
			var ts = forms[j].querySelector('input[name="_ts"]');
			if (ts) ts.value = Math.floor(Date.now() / 1000);
		}

		// Delegated submit so dynamically-added widgets work.
		document.addEventListener('submit', function (e) {
			var form = e.target.closest('.storedash-signup-form');
			if (form) {
				e.preventDefault();
				submitForm(form);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
