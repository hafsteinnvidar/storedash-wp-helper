<?php
/**
 * Nudges guests to create an account at checkout while rewards credit is on.
 *
 * Credit is earned by email (guests included) but can only be SPENT by a
 * logged-in shopper, so a guest who never registers can never use what they
 * earned. While the feature is enabled this class:
 *
 *  - forces WooCommerce's "Allow customers to create an account during
 *    checkout" on (classic, blocks and the Store API `create_account` flag all
 *    read `WC_Checkout::is_registration_enabled()`),
 *  - forces "generate password" OFF so the shopper picks a password in the
 *    checkout form (classic + blocks render the field; the Store API accepts
 *    `customer_password`) instead of getting a set-password email,
 *  - pre-ticks the classic "Create an account?" checkbox,
 *  - prints a one-line hint above the account fields on classic checkout.
 *
 * Nothing here creates users itself — WooCommerce does, through its normal
 * checkout path. Headless storefronts read `extensions.storedash_credit`
 * (`enabled`, `logged_in`) and render their own prompt.
 *
 * @package StoreDash\Credit\Checkout
 * @since   1.17.0
 */

namespace StoreDash\Credit\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Settings;

/**
 * Account-creation nudge for guests.
 *
 * @since 1.17.0
 */
class Account_Prompt {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_checkout_registration_enabled', array( $this, 'force_registration_enabled' ) );
		add_filter( 'pre_option_woocommerce_registration_generate_password', array( $this, 'force_manual_password' ), 10, 1 );
		add_filter( 'woocommerce_create_account_default_checked', array( $this, 'default_checked' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_hint' ), 20 );
	}

	/**
	 * `WC_Checkout::is_registration_enabled()` → true while credit is on.
	 *
	 * @param bool $enabled Store setting.
	 * @return bool
	 */
	public function force_registration_enabled( $enabled ) {
		return Settings::is_enabled() ? true : $enabled;
	}

	/**
	 * `woocommerce_registration_generate_password` → 'no' while credit is on,
	 * so checkout shows a password field instead of emailing a set-password link.
	 *
	 * @param mixed $pre Short-circuit value (false = read the option).
	 * @return mixed
	 */
	public function force_manual_password( $pre ) {
		return Settings::is_enabled() ? 'no' : $pre;
	}

	/**
	 * Pre-tick "Create an account?" on classic checkout while credit is on.
	 *
	 * @param bool $checked Default.
	 * @return bool
	 */
	public function default_checked( $checked ) {
		return Settings::is_enabled() ? true : $checked;
	}

	/**
	 * Hint printed right above WooCommerce's account fields (classic checkout).
	 */
	public function render_hint(): void {
		if ( ! Settings::is_enabled() || is_user_logged_in() ) {
			return;
		}
		?>
		<p class="form-row form-row-wide storedash-credit-account-hint">
			<small><?php esc_html_e( 'You earn rewards credit on this order. Create an account below to use it on your next purchase.', 'storedash' ); ?></small>
		</p>
		<?php
	}
}
