<?php
/**
 * Waitlist Settings REST Controller
 *
 * The app→plugin contract for waitlist form configuration, plus the placement
 * verifier that lets the dashboard tell a merchant the truth about whether
 * their form is actually showing.
 *
 * Routes (namespace `storedash/v1`):
 *   GET  /waitlist/settings          — current config + hash, for drift detection
 *   POST /waitlist/settings          — push config from the dashboard
 *   GET  /waitlist/placement-status  — does the form actually render?
 *
 * Supabase (`waitlist_settings`) remains the source of truth; this endpoint is
 * the storefront's replica. `GET` exists so the dashboard can distinguish
 * "your site is running this exact config" from "your last save never landed",
 * and so the storedash-worker entitlement reconcile can diff before writing
 * rather than blind-writing every store every night.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.8.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Settings Controller Class
 */
class Waitlist_Settings_Controller {

	/**
	 * Transient holding the last placement verification result.
	 */
	const VERIFY_TRANSIENT = 'storedash_waitlist_placement_status';

	/**
	 * How long a verification result stays fresh.
	 */
	const VERIFY_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * Takes no parameters: this is hooked to `rest_api_init`, which passes the
	 * WP_REST_Server instance to its callbacks. A typed parameter here would
	 * receive that object and throw.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/waitlist/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/waitlist/placement-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_placement_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check — same capability as the cart settings writer.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return the current storefront config.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$config = Waitlist_Config::get();

		return rest_ensure_response(
			array(
				'success'          => true,
				'config'           => $config,
				'config_hash'      => $config['config_hash'],
				'plugin_version'   => STOREDASH_VERSION,
				'elementor_active' => did_action( 'elementor/loaded' ) > 0,
				/*
				 * The dashboard's live preview loads this stylesheet directly so
				 * that what a merchant sees is styled by the exact CSS running
				 * on their storefront — no bundled copy to drift.
				 *
				 * It is reported rather than derived because the plugin
				 * directory name is not fixed: installs exist as `storedash`,
				 * `storedash-wp` and `storedash-helper`, so a URL guessed
				 * dashboard-side would 404 on some stores.
				 */
				'style_url'        => STOREDASH_URL . 'assets/css/waitlist-widget.css?v=' . STOREDASH_VERSION,
			)
		);
	}

	/**
	 * Apply a config push from the dashboard (or an entitlement push from the
	 * worker reconcile).
	 *
	 * Partial by design: the payload only carries the keys the caller owns, so
	 * a dashboard save can never clobber `entitled` and a reconcile can never
	 * clobber merchant styling.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = $request->get_params();
		}

		$clean = Waitlist_Config::sanitize( is_array( $raw ) ? $raw : array() );

		if ( empty( $clean ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No recognised settings in payload.', 'storedash' ),
				)
			);
		}

		$config = Waitlist_Config::update( $clean );

		// Placement or activation changes invalidate any cached verification —
		// a stale green check after switching modes is worse than no check.
		if ( array_intersect( array_keys( $clean ), array( 'placement_mode', 'placement_priority', 'enabled', 'entitled', 'display_mode' ) ) ) {
			delete_transient( self::VERIFY_TRANSIENT );
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'config'      => $config,
				'config_hash' => $config['config_hash'],
			)
		);
	}

	/**
	 * Verify that the form actually renders on a real product page.
	 *
	 * Loopback-fetches one out-of-stock product's permalink and inspects the
	 * HTML. This never runs on a visitor request — only from the dashboard or
	 * an explicit refresh — and the result is cached.
	 *
	 * Degrades to `unverifiable`, never to a false green: if the store has no
	 * out-of-stock product to test with, or the host blocks loopback requests,
	 * the dashboard is told it could not check rather than told everything is
	 * fine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_placement_status( $request ) {
		$refresh = (bool) $request->get_param( 'refresh' );

		if ( ! $refresh ) {
			$cached = get_transient( self::VERIFY_TRANSIENT );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;

				return rest_ensure_response( $cached );
			}
		}

		$result = $this->run_verification();
		set_transient( self::VERIFY_TRANSIENT, $result, self::VERIFY_TTL );

		$result['cached'] = false;

		return rest_ensure_response( $result );
	}

	/**
	 * Perform the loopback verification.
	 *
	 * @return array
	 */
	private function run_verification(): array {
		$base = array(
			'status'       => 'unverifiable',
			'reason'       => '',
			'product_id'   => 0,
			'product_name' => '',
			'product_url'  => '',
			'checked_at'   => current_time( 'mysql', true ),
		);

		if ( ! Waitlist_Config::is_active() ) {
			$base['status'] = 'disabled';
			$base['reason'] = 'waitlist_disabled';

			return $base;
		}

		$product_id = $this->find_out_of_stock_product();
		if ( ! $product_id ) {
			$base['status'] = 'no_test_product';
			$base['reason'] = 'no_out_of_stock_products';

			return $base;
		}

		$product = wc_get_product( $product_id );
		$url     = get_permalink( $product_id );

		$base['product_id']   = $product_id;
		$base['product_name'] = $product ? $product->get_name() : '';
		$base['product_url']  = (string) $url;

		if ( ! $url ) {
			$base['reason'] = 'no_permalink';

			return $base;
		}

		$response = wp_remote_get(
			add_query_arg( 'storedash_waitlist_check', (string) time(), $url ),
			array(
				'timeout'    => 15,
				'sslverify'  => false,
				'user-agent' => 'StoreDash-PlacementVerifier/' . STOREDASH_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$base['reason'] = 'loopback_failed: ' . $response->get_error_code();

			return $base;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$base['reason'] = 'http_' . $code;

			return $base;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( false !== strpos( $body, 'storedash-waitlist-fallback' ) ) {
			// The template was emitted, which means the summary hook never
			// fired — the theme does not run the standard WooCommerce product
			// summary chain. The form still shows, via JS insertion.
			$base['status'] = 'fallback';
			$base['reason'] = 'theme_does_not_run_summary_hook';

			return $base;
		}

		if ( false !== strpos( $body, 'storedash-waitlist-widget' ) ) {
			$base['status'] = 'verified';

			return $base;
		}

		$base['status'] = 'not_found';
		$base['reason'] = 'markup_absent';

		return $base;
	}

	/**
	 * Find a published product suitable for verification.
	 *
	 * Prefers a simple out-of-stock product; falls back to the parent of an
	 * out-of-stock variation, since a variable product whose parent still reads
	 * `instock` is exactly the case the naive query misses.
	 *
	 * @return int Product ID, or 0.
	 */
	private function find_out_of_stock_product(): int {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$product_id = (int) $wpdb->get_var(
			"SELECT l.product_id
			 FROM {$lookup} l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			 WHERE l.stock_status <> 'instock'
			   AND p.post_type = 'product'
			   AND p.post_status = 'publish'
			 LIMIT 1"
		);

		if ( $product_id ) {
			// phpcs:enable
			return $product_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$parent_id = (int) $wpdb->get_var(
			"SELECT parent.ID
			 FROM {$lookup} l
			 INNER JOIN {$wpdb->posts} v ON v.ID = l.product_id AND v.post_type = 'product_variation'
			 INNER JOIN {$wpdb->posts} parent ON parent.ID = v.post_parent
			 WHERE l.stock_status <> 'instock'
			   AND parent.post_status = 'publish'
			 LIMIT 1"
		);
		// phpcs:enable

		return $parent_id;
	}
}

// Initialize
new Waitlist_Settings_Controller();
