<?php
/**
 * StoreDash Requirements Checker
 *
 * Validates that all plugin requirements are met before initialization.
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requirements checker class
 */
class StoreDash_Requirements {

	/**
	 * Minimum WooCommerce version required
	 */
	const MIN_WC_VERSION = '6.0.0';

	/**
	 * Minimum PHP version required
	 */
	const MIN_PHP_VERSION = '7.4';

	/**
	 * Minimum WordPress version required
	 */
	const MIN_WP_VERSION = '5.8';

	/**
	 * Check if all requirements are met
	 *
	 * @return bool True if all requirements met
	 */
	public function met(): bool {
		// Check WooCommerce is active
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return false;
		}

		// Check WooCommerce version
		if ( ! $this->is_woocommerce_version_compatible() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
			return false;
		}

		// Check PHP version
		if ( ! $this->is_php_version_compatible() ) {
			add_action( 'admin_notices', array( $this, 'php_version_notice' ) );
			return false;
		}

		// Check WordPress version
		if ( ! $this->is_wordpress_version_compatible() ) {
			add_action( 'admin_notices', array( $this, 'wordpress_version_notice' ) );
			return false;
		}

		return true;
	}

	/**
	 * Check if WooCommerce is active (multisite compatible)
	 *
	 * @return bool
	 */
	private function is_woocommerce_active(): bool {
		// Check if WooCommerce class exists
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		// For multisite, check if WooCommerce is network activated
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins' );
			if ( isset( $network_plugins['woocommerce/woocommerce.php'] ) ) {
				return true;
			}
		}

		// Check if WooCommerce is active on current site
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming the WordPress core active_plugins filter, not defining a hook.
		$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );
		return in_array( 'woocommerce/woocommerce.php', $active_plugins, true );
	}

	/**
	 * Check WooCommerce version compatibility
	 *
	 * @return bool
	 */
	private function is_woocommerce_version_compatible(): bool {
		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		return version_compare( WC_VERSION, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Check PHP version compatibility
	 *
	 * @return bool
	 */
	private function is_php_version_compatible(): bool {
		return version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );
	}

	/**
	 * Check WordPress version compatibility
	 *
	 * @return bool
	 */
	private function is_wordpress_version_compatible(): bool {
		return version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Admin notice: WooCommerce missing
	 */
	public function woocommerce_missing_notice(): void {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Storedash requires WooCommerce to be installed and active.', 'storedash' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Admin notice: WooCommerce version incompatible
	 */
	public function woocommerce_version_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: minimum WooCommerce version required */
					esc_html__( 'Storedash requires WooCommerce %s or higher. Please update WooCommerce.', 'storedash' ),
					esc_html( self::MIN_WC_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin notice: PHP version incompatible
	 */
	public function php_version_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: minimum PHP version required */
					esc_html__( 'Storedash requires PHP %s or higher. Please contact your hosting provider to upgrade PHP.', 'storedash' ),
					esc_html( self::MIN_PHP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin notice: WordPress version incompatible
	 */
	public function wordpress_version_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: minimum WordPress version required */
					esc_html__( 'Storedash requires WordPress %s or higher. Please update WordPress.', 'storedash' ),
					esc_html( self::MIN_WP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}

