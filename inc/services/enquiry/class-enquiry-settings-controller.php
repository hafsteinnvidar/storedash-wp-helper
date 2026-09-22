<?php
/**
 * Enquiry Settings REST Controller
 *
 * The app→plugin contract for enquiry form configuration, plus the placement
 * verifier that lets the dashboard tell a merchant the truth about whether
 * their form is actually showing.
 *
 * Routes (namespace `storedash/v1`):
 *   GET  /enquiry/settings          — current config + hash, for drift detection
 *   POST /enquiry/settings          — push config from the dashboard
 *   GET  /enquiry/placement-status  — does the form actually render, and where?
 *
 * Supabase (`enquiry_settings`) remains the source of truth; this endpoint is
 * the storefront's replica. `GET` exists so the dashboard can distinguish
 * "your site is running this exact config" from "your last save never landed",
 * and so the storedash-worker entitlement reconcile can diff before writing
 * rather than blind-writing every store every night.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.9.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Settings Controller Class
 */
class Enquiry_Settings_Controller {

	/**
	 * Transient holding the last placement verification result.
	 */
	const VERIFY_TRANSIENT = 'storedash_enquiry_placement_status';

	/**
	 * How long a verification result stays fresh.
	 */
	const VERIFY_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How many published products to try before giving up on finding one that
	 * passes the merchant's scope rules.
	 */
	const VERIFY_CANDIDATES = 25;

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
			'/enquiry/settings',
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
			'/enquiry/placement-status',
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
		$config = Enquiry_Config::get();

		return rest_ensure_response(
			array(
				'success'          => true,
				'config'           => $config,
				'config_hash'      => $config['config_hash'],
				'plugin_version'   => STOREDASH_VERSION,
				'elementor_active' => did_action( 'elementor/loaded' ) > 0,
				/*
				 * Whether this store can offer brand scoping at all. Native
				 * `product_brand` arrived in WooCommerce 9.6; stores below that,
				 * or still on a third-party brands plugin, have no such
				 * taxonomy, and a picker offering brands there would silently
				 * match nothing.
				 */
				'brands_supported' => taxonomy_exists( 'product_brand' ),
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
				'style_url'        => STOREDASH_URL . 'assets/css/enquiry-widget.css?v=' . STOREDASH_VERSION,
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

		$clean = Enquiry_Config::sanitize( is_array( $raw ) ? $raw : array() );

		if ( empty( $clean ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No recognised settings in payload.', 'storedash' ),
				)
			);
		}

		$config = Enquiry_Config::update( $clean );

		// Placement, scope or activation changes invalidate any cached
		// verification — a stale green check after switching placement is worse
		// than no check.
		$invalidating = array( 'placement_mode', 'placement_position', 'placement_priority', 'enabled', 'entitled', 'scope' );
		if ( array_intersect( array_keys( $clean ), $invalidating ) ) {
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
	 * Loopback-fetches one in-scope product's permalink and inspects the HTML.
	 * This never runs on a visitor request — only from the dashboard or an
	 * explicit refresh — and the result is cached.
	 *
	 * Degrades to `unverifiable`, never to a false green: if the store has no
	 * product matching the merchant's scope, or the host blocks loopback
	 * requests, the dashboard is told it could not check rather than told
	 * everything is fine.
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
			'position'     => '',
			'product_id'   => 0,
			'product_name' => '',
			'product_url'  => '',
			'checked_at'   => current_time( 'mysql', true ),
		);

		if ( ! Enquiry_Config::is_active() ) {
			$base['status'] = 'disabled';
			$base['reason'] = 'enquiry_disabled';

			return $base;
		}

		if ( ! Enquiry_Config::is_auto_placement() ) {
			$base['status'] = 'elementor_mode';
			$base['reason'] = 'placement_handled_by_elementor_widget';

			return $base;
		}

		$product_id = $this->find_in_scope_product();
		if ( ! $product_id ) {
			$base['status'] = 'no_test_product';
			$base['reason'] = 'no_published_product_matches_scope';

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
			add_query_arg( 'storedash_enquiry_check', (string) time(), $url ),
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

		if ( false !== strpos( $body, 'storedash-enquiry-fallback' ) ) {
			// The template was emitted, which means neither the tab filter nor
			// the summary hook produced anything — this theme runs neither the
			// standard product tabs nor the summary chain. The form still shows,
			// via JS insertion.
			$base['status']   = 'fallback';
			$base['position'] = 'fallback';
			$base['reason']   = 'theme_renders_no_tabs_or_summary';

			return $base;
		}

		if ( false !== strpos( $body, 'storedash-enquiry-widget' ) ) {
			$base['status'] = 'verified';
			// `tab-storedash_enquiry` is the panel id WooCommerce derives from
			// our tab key, so its presence distinguishes "rendered in the tab"
			// from "rendered in the summary" without a second request.
			$base['position'] = false !== strpos( $body, 'tab-storedash_enquiry' )
				? Enquiry_Config::POSITION_TAB
				: Enquiry_Config::POSITION_SUMMARY;

			return $base;
		}

		$base['status'] = 'not_found';
		$base['reason'] = 'markup_absent';

		return $base;
	}

	/**
	 * Find a published product that passes the merchant's scope rules.
	 *
	 * Testing against an out-of-scope product would report `not_found` on a
	 * perfectly working configuration, so scope is applied here rather than
	 * grabbing the first product in the table.
	 *
	 * @return int Product ID, or 0.
	 */
	private function find_in_scope_product(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				 FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				   AND post_status = 'publish'
				 ORDER BY ID DESC
				 LIMIT %d",
				self::VERIFY_CANDIDATES
			)
		);
		// phpcs:enable

		if ( empty( $candidates ) ) {
			return 0;
		}

		foreach ( $candidates as $candidate_id ) {
			$product = wc_get_product( (int) $candidate_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( Enquiry_Scope::is_eligible( $product ) ) {
				return (int) $candidate_id;
			}
		}

		return 0;
	}
}

// Initialize
new Enquiry_Settings_Controller();
