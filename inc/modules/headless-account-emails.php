<?php
/**
 * StoreDash headless account emails.
 *
 * Points the customer-facing account links in WordPress and WooCommerce emails
 * at the headless storefront instead of wp-login.php / the WooCommerce
 * My Account page.
 *
 * Without this, a shopper who requests a password reset on the storefront gets
 * an email that takes them to the WordPress origin — a different domain, a
 * different design, and a dead end, since the storefront session is never
 * established there.
 *
 * Everything here no-ops unless the storedash_storefront_url option is set, so
 * a non-headless store is completely unaffected.
 *
 * @package StoreDash
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Headless_Account_Emails {

	/**
	 * Storefront path that handles the reset link.
	 */
	const RESET_PATH = '/endursetja-lykilord';

	/**
	 * Storefront path for the account area.
	 */
	const ACCOUNT_PATH = '/minar-sidur';

	/**
	 * Singleton instance.
	 *
	 * @var StoreDash_Headless_Account_Emails|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StoreDash_Headless_Account_Emails
	 */
	public static function instance(): StoreDash_Headless_Account_Emails {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'retrieve_password_message', array( $this, 'filter_reset_message' ), 10, 4 );
		add_filter( 'woocommerce_account_endpoint_url', array( $this, 'filter_account_url' ), 10, 2 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_wc_endpoint_url' ), 10, 4 );
	}

	/**
	 * The configured storefront base URL, or '' when the store is not headless.
	 *
	 * @return string Base URL without a trailing slash.
	 */
	private function storefront_url(): string {
		$url = (string) get_option( 'storedash_storefront_url', '' );

		return $url ? untrailingslashit( $url ) : '';
	}

	/**
	 * Rewrite the reset URL inside the password-reset email body.
	 *
	 * WordPress builds the message with a wp-login.php?action=rp link. Rather
	 * than rebuild the whole (translated, possibly third-party filtered) message,
	 * this swaps just the URL, so any other customization of the copy survives.
	 *
	 * @param string  $message    The email body.
	 * @param string  $key        The password reset key.
	 * @param string  $user_login The username.
	 * @param WP_User $user_data  The user object.
	 * @return string
	 */
	public function filter_reset_message( $message, $key, $user_login, $user_data ) {
		$storefront = $this->storefront_url();

		if ( '' === $storefront ) {
			return $message;
		}

		// Only redirect shoppers. A staff account resetting its password still
		// needs the real wp-login.php flow to reach wp-admin.
		if ( $user_data instanceof WP_User && ! StoreDash_Customer_Tokens::user_is_eligible( $user_data ) ) {
			return $message;
		}

		$storefront_link = $storefront . self::RESET_PATH . '?' . http_build_query(
			array(
				'key'   => $key,
				'login' => $user_login,
			)
		);

		// WordPress writes the link as wp-login.php?login=...&key=...&action=rp&wp_lang=...
		// (parameter order has changed between versions), so match action=rp
		// anywhere in the query rather than assuming it comes first.
		$message = preg_replace(
			'#<?https?://[^\s<>]*wp-login\.php\?[^\s<>]*\baction=rp\b[^\s<>]*>?#i',
			$storefront_link,
			$message
		);

		return $message;
	}

	/**
	 * Point My Account endpoint URLs at the storefront.
	 *
	 * @param string $url      The endpoint URL.
	 * @param string $endpoint The endpoint slug.
	 * @return string
	 */
	public function filter_account_url( $url, $endpoint ) {
		$storefront = $this->storefront_url();

		if ( '' === $storefront || is_admin() ) {
			return $url;
		}

		return $storefront . self::ACCOUNT_PATH;
	}

	/**
	 * Point WooCommerce order/account endpoint URLs at the storefront.
	 *
	 * Covers "view order" links in transactional emails.
	 *
	 * @param string $url      The endpoint URL.
	 * @param string $endpoint The endpoint slug.
	 * @param string $value    The endpoint value (e.g. an order ID).
	 * @param string $permalink The base permalink.
	 * @return string
	 */
	public function filter_wc_endpoint_url( $url, $endpoint, $value, $permalink ) {
		$storefront = $this->storefront_url();

		if ( '' === $storefront || is_admin() ) {
			return $url;
		}

		if ( 'view-order' === $endpoint && $value ) {
			return $storefront . self::ACCOUNT_PATH . '/pantanir/' . rawurlencode( (string) $value );
		}

		if ( 'orders' === $endpoint ) {
			return $storefront . self::ACCOUNT_PATH . '/pantanir';
		}

		if ( 'edit-address' === $endpoint ) {
			return $storefront . self::ACCOUNT_PATH . '/heimilisfong';
		}

		return $url;
	}
}

StoreDash_Headless_Account_Emails::instance();
