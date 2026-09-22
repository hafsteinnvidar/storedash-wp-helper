<?php
/**
 * FBT Settings REST Controller
 *
 * The app→plugin contract for global FBT display configuration, plus the
 * placement verifier. Mirrors the enquiry settings controller.
 *
 * Routes (namespace `storedash/v1`):
 *   GET  /fbt/settings          — current config + hash, for drift detection
 *   POST /fbt/settings          — push config from the dashboard
 *   GET  /fbt/placement-status  — does the bundle actually render, and where?
 *
 * Supabase (`fbt_settings`) remains the source of truth; this endpoint is the
 * storefront's replica.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Settings Controller Class
 */
class FBT_Settings_Controller {

	/**
	 * Transient holding the last placement verification result.
	 */
	const VERIFY_TRANSIENT = 'storedash_fbt_placement_status';

	/**
	 * How long a verification result stays fresh.
	 */
	const VERIFY_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How many bundle-carrying products to consider when picking a test
	 * product for the loopback check.
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
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/fbt/settings',
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
			'/fbt/placement-status',
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
	 * Permission check — same capability as the other settings writers.
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
		$config = FBT_Config::get();

		return rest_ensure_response(
			array(
				'success'           => true,
				'config'            => $config,
				'config_hash'       => $config['config_hash'],
				'plugin_version'    => STOREDASH_VERSION,
				'elementor_active'  => did_action( 'elementor/loaded' ) > 0,
				/*
				 * Whether the storedash-essentials plugin (owner of the
				 * Elementor/Bricks FBT widgets) is active — the dashboard uses
				 * this to word the "widget" placement mode honestly.
				 */
				'essentials_active' => defined( 'STOREDASH_ESSENTIALS_VERSION' ),
				/*
				 * The dashboard's live preview loads this stylesheet directly so
				 * the preview is styled by the exact CSS running on the
				 * storefront. Reported, not derived: the plugin directory name
				 * varies across installs.
				 */
				'style_url'         => STOREDASH_URL . 'assets/css/fbt-widget.css?v=' . STOREDASH_VERSION,
			)
		);
	}

	/**
	 * Apply a config push from the dashboard.
	 *
	 * Partial by design: the payload only carries the keys the caller owns, so
	 * a dashboard save can never clobber `entitled`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = $request->get_params();
		}

		$clean = FBT_Config::sanitize( is_array( $raw ) ? $raw : array() );

		if ( empty( $clean ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No recognised settings in payload.', 'storedash' ),
				)
			);
		}

		$config = FBT_Config::update( $clean );

		// Placement or activation changes invalidate any cached verification.
		$invalidating = array( 'placement_mode', 'placement_position', 'placement_priority', 'enabled', 'entitled' );
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
	 * Verify that the bundle actually renders on a real product page.
	 *
	 * Loopback-fetches one bundle-carrying product's permalink and inspects
	 * the HTML. Degrades to `unverifiable`, never to a false green.
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

		if ( ! FBT_Config::is_active() ) {
			$base['status'] = 'disabled';
			$base['reason'] = 'fbt_disabled';

			return $base;
		}

		if ( ! FBT_Config::is_auto_placement() ) {
			$base['status'] = 'widget_mode';
			$base['reason'] = 'placement_handled_by_builder_widget';

			return $base;
		}

		$product_id = $this->find_bundle_product();
		if ( ! $product_id ) {
			$base['status'] = 'no_test_product';
			$base['reason'] = 'no_published_product_has_a_bundle';

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
			add_query_arg( 'storedash_fbt_check', (string) time(), $url ),
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

		if ( false !== strpos( $body, 'storedash-fbt-fallback' ) ) {
			// The template was emitted: the summary chain never ran on this
			// theme. The bundle still shows, via JS insertion.
			$base['status']   = 'fallback';
			$base['position'] = 'fallback';
			$base['reason']   = 'theme_renders_no_summary_chain';

			return $base;
		}

		// Only the tokens modifier proves OUR hook-mode markup rendered — the
		// bare container class is also emitted by the essentials widgets.
		if ( false !== strpos( $body, 'storedash-fbt--tokens' ) ) {
			$base['status']   = 'verified';
			$base['position'] = FBT_Config::position();

			return $base;
		}

		$base['status'] = 'not_found';
		$base['reason'] = 'markup_absent';

		return $base;
	}

	/**
	 * Find a published product that actually carries a bundle.
	 *
	 * Verifying against a bundle-less product would report `not_found` on a
	 * perfectly working configuration, so candidates come from the meta table.
	 *
	 * @return int Product ID, or 0.
	 */
	private function find_bundle_product(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND pm.meta_key IN ('storedash_fbt_ids', 'woobt_ids')
				   AND pm.meta_value <> ''
				   AND pm.meta_value <> 'a:0:{}'
				   AND pm.meta_value <> '[]'
				 ORDER BY p.ID DESC
				 LIMIT %d",
				self::VERIFY_CANDIDATES
			)
		);
		// phpcs:enable

		foreach ( (array) $candidates as $candidate_id ) {
			// The meta row existing is not enough — the decoded bundle must
			// contain at least one purchasable companion.
			if ( null !== FBT_Data::bundle( (int) $candidate_id ) ) {
				return (int) $candidate_id;
			}
		}

		return 0;
	}
}

// Initialize
new FBT_Settings_Controller();
