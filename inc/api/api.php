<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_API {
	/**
	 * API namespace v2 (modern naming).
	 */
	public $namespace_v2 = 'storedash/v1';

	/**
	 * Single instance
	 *
	 * @var StoreDash_API
	 */
	private static $instance = null;

	/**
	 * @var StoreDash_API_Products
	 */
	private $products_controller;

	/**
	 * @var StoreDash_API_Emails
	 */
	private $emails_controller;

	/**
	 * @var StoreDash\Carts\Carts_Manager
	 */
	private $carts_controller;

	/**
	 * @var StoreDash_API_Orders
	 */
	private $orders_controller;

	/**
	 * @var StoreDash_API_Coupons
	 */
	private $coupons_controller;

	/**
	 * @var StoreDash_API_Media
	 */
	private $media_controller;

	/**
	 * @var StoreDash\Discounts\Discounts_Manager
	 */
	private $discounts_controller;

	/**
	 * Rewards credit module.
	 *
	 * @var StoreDash\Credit\Credit_Manager
	 */
	private $credit_controller;

	/**
	 * Get singleton instance
	 *
	 * @return StoreDash_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self(
				true,  // products
				true,  // orders
				true,  // emails
				true,  // carts
				true,  // media
				true,  // discounts
				true   // coupons
			);
		}
		return self::$instance;
	}

	/**
	 * Constructor - private to enforce singleton
	 *
	 * @param bool $enable_products Whether to enable the products controller
	 * @param bool $enable_orders Whether to enable the orders controller
	 * @param bool $enable_emails Whether to enable the emails controller
	 * @param bool $enable_carts Whether to enable the carts controller
	 * @param bool $enable_media Whether to enable the media controller
	 * @param bool $enable_discounts Whether to enable the discounts controller
	 * @param bool $enable_coupons Whether to enable the coupons controller
	 */
	private function __construct(
		$enable_products = false,
		$enable_orders = false,
		$enable_emails = false,
		$enable_carts = false,
		$enable_media = false,
		$enable_discounts = false,
		$enable_coupons = false
	) {
		// Initialize only the controllers that are explicitly enabled
		try {
			if ( $enable_products && class_exists( 'StoreDash\Products\Products_Manager' ) ) {
				try {
					// Initialize the new Products module directly
					$this->products_controller = new \StoreDash\Products\Products_Manager();
					$this->products_controller->init();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Products Manager: ' . $e->getMessage() );
				}
			}

			if ( $enable_orders && class_exists( 'StoreDash_API_Orders' ) ) {
				try {
					$this->orders_controller = new StoreDash_API_Orders();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Orders Controller: ' . $e->getMessage() );
				}
			}

			if ( $enable_coupons && class_exists( 'StoreDash_API_Coupons' ) ) {
				try {
					$this->coupons_controller = new StoreDash_API_Coupons();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Coupons Controller: ' . $e->getMessage() );
				}
			}

			if ( $enable_emails && class_exists( 'StoreDash_API_Emails' ) ) {
				try {
					$this->emails_controller = new StoreDash_API_Emails();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Emails Controller: ' . $e->getMessage() );
				}
			}

			if ( $enable_carts && class_exists( 'StoreDash\Carts\Carts_Manager' ) ) {
				try {
					$this->carts_controller = new \StoreDash\Carts\Carts_Manager();
					$this->carts_controller->init();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Carts Manager: ' . $e->getMessage() );
				}
			}

			if ( $enable_media && class_exists( 'StoreDash_API_Media' ) ) {
				try {
					$this->media_controller = new StoreDash_API_Media();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Media Controller: ' . $e->getMessage() );
				}
			}

			if ( $enable_discounts && class_exists( 'StoreDash\Discounts\Discounts_Manager' ) ) {
				try {
					$this->discounts_controller = new \StoreDash\Discounts\Discounts_Manager();
					$this->discounts_controller->init();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Discounts Manager: ' . $e->getMessage() );
				}
			}

			// Rewards credit (ledger, checkout fee, REST, Store API). Always on;
			// the feature itself is gated by the synced `enabled` setting.
			if ( class_exists( 'StoreDash\Credit\Credit_Manager' ) ) {
				try {
					$this->credit_controller = new \StoreDash\Credit\Credit_Manager();
					$this->credit_controller->init();
				} catch ( Exception $e ) {
					$this->log_api_error( 'Failed to initialize Credit Manager: ' . $e->getMessage() );
				}
			}

			// Add central CORS support - using our improved approach
			add_action( 'rest_api_init', array( $this, 'add_cors_support' ) );

		} catch ( Exception $e ) {
			$this->log_api_error( 'Critical error during API initialization: ' . $e->getMessage() );

			// Add admin notice for critical API errors
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Storedash Helper: Critical API initialization error. Please check error logs.', 'storedash' ); ?></p>
				</div>
					<?php
				}
			);
		}

		// Register core endpoints
		add_action( 'rest_api_init', array( $this, 'register_core_routes' ), 10 );

		// Track user activity
		add_action( 'init', array( $this, 'track_user_activity' ) );
	}

	/**
	 * Add CORS support for WooDash and StoreDash endpoints only
	 * Uses whitelist-based origin validation for security
	 */
	public function add_cors_support() {
		add_filter(
			'rest_pre_serve_request',
			function ( $served, $result, $request, $server ) {
				$route = $request->get_route();
				// Only modify CORS for our specific endpoints
				if ( strpos( $route, '/storedash/' ) === 0 ) {
					$origin = StoreDash_Helpers::get_server_var( 'HTTP_ORIGIN', 'url' );

					// Whitelist of allowed origins for security
					$allowed_origins = array(
						'https://app.storedash.io',
						'https://storedash.app',
						'https://storedash-sync-go-6aiat.ondigitalocean.app',
					);

					// Add configured storefront origin
					$storefront_url = get_option( 'storedash_storefront_url', '' );
					if ( $storefront_url ) {
						$allowed_origins[] = rtrim( $storefront_url, '/' );
					}

					// Only allow localhost in development
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						$allowed_origins[] = 'http://localhost:3000';
						$allowed_origins[] = 'http://localhost:3001';
					}

					// Only set CORS headers if origin is in whitelist
					if ( $origin && in_array( $origin, $allowed_origins, true ) ) {
						header( 'Access-Control-Allow-Origin: ' . $origin );
						header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
						header( 'Access-Control-Allow-Credentials: true' );
						header( 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-StoreDash-Customer' );

						// Handle preflight requests
						if ( StoreDash_Helpers::get_server_var( 'REQUEST_METHOD' ) === 'OPTIONS' ) {
							status_header( 200 );
							exit();
						}
					}
				}
				return $served;
			},
			10,
			4
		);
	}

	/**
	 * Register core REST routes and enabled controllers
	 */
	public function register_core_routes() {
		$namespaces = array( $this->namespace_v2 );

		// Register core endpoints
		foreach ( $namespaces as $ns ) {
			// Register verification endpoint (requires authentication)
			register_rest_route(
				$ns,
				'/verify',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'verify_plugin' ),
					'permission_callback' => function () {
						// Require manage_woocommerce capability for security
						return current_user_can( 'manage_woocommerce' );
					},
				)
			);

			// Register status endpoint (requires authentication)
			register_rest_route(
				$ns,
				'/status',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => function () {
						// Require manage_woocommerce capability for security
						return current_user_can( 'manage_woocommerce' );
					},
				)
			);

			// Register public ping endpoint for plugin verification during onboarding
			// Returns minimal info (no version exposure) - safe for public access
			register_rest_route(
				$ns,
				'/ping',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_ping' ),
					'permission_callback' => '__return_true',
				)
			);

			// Settings endpoint for StoreDash dashboard to push config
			register_rest_route(
				$ns,
				'/settings',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_woocommerce' );
					},
					'args'                => array(
						'storedash_storefront_url' => array(
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				)
			);

			// Status catalogue: every order and product status this store actually
			// has, including third-party custom statuses (requires authentication).
			register_rest_route(
				$ns,
				'/statuses',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_statuses' ),
					'permission_callback' => function () {
						// Require manage_woocommerce capability for security
						return current_user_can( 'manage_woocommerce' );
					},
				)
			);
		}

		// Products routes are registered automatically by the Products_Manager
		// in init(); nothing to register here.

		if ( isset( $this->emails_controller ) ) {
			$this->emails_controller->register_routes();
		}

		if ( isset( $this->carts_controller ) ) {
			$this->carts_controller->register_routes( $this->namespace_v2 );
		}

		if ( isset( $this->media_controller ) ) {
			$this->media_controller->register_routes();
		}

		if ( isset( $this->orders_controller ) ) {
			$this->orders_controller->register_routes();
		}

		if ( isset( $this->coupons_controller ) ) {
			$this->coupons_controller->register_routes();
		}
	}

	/**
	 * Verify plugin installation and return status
	 */
	public function verify_plugin( $request ) {
		return rest_ensure_response( $this->get_webhook_system_info() );
	}

	/**
	 * Track user activity for online status.
	 * Throttled to update at most once per 5 minutes per user via transient.
	 */
	public function track_user_activity() {
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id   = get_current_user_id();
		$cache_key = 'user_active_' . $user_id;

		// DB-backed transient so the throttle window persists across requests.
		if ( get_transient( $cache_key ) ) {
			return;
		}

		update_user_meta( $user_id, 'last_active', gmdate( 'Y-m-d H:i:s' ) );
		set_transient( $cache_key, 1, 5 * MINUTE_IN_SECONDS );
	}

	public function get_status() {
		$info                 = $this->get_webhook_system_info();
		$info['setup_status'] = get_option( 'woodash_setup_status' ) === 'completed' ? 'completed' : 'pending';
		return rest_ensure_response( $info );
	}

	/**
	 * Get shared system info for verify/status endpoints.
	 *
	 * @return array
	 */
	private function get_webhook_system_info() {
		$webhook_status = get_option( 'woodash_webhooks_setup' ) === 'completed';
		$webhook_count  = 0;
		if ( $webhook_status ) {
			global $wpdb;
			$webhook_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s",
					'WooDash - %'
				)
			);
		}

		return array(
			'status'              => 'active',
			'version'             => WOODASH_HELPER_VERSION,
			'woocommerce_version' => ( $wc = WC() ) ? $wc->version : 'unknown',
			'wordpress_version'   => get_bloginfo( 'version' ),
			'features'            => array(
				'subscriptions'         => class_exists( 'WC_Subscriptions' ),
				'webhooks'              => $webhook_status,
				'products_bulk'         => true,
				'smart_coupons'         => class_exists( 'WC_Smart_Coupons' ),
				'smart_coupons_version' => ( class_exists( 'WC_Smart_Coupons' ) && method_exists( 'WC_Smart_Coupons', 'get_instance' ) )
					? WC_Smart_Coupons::get_instance()->get_version()
					: null,
			),
			'webhooks'            => array(
				'status' => $webhook_status,
				'count'  => (int) $webhook_count,
			),
		);
	}

	/**
	 * Public ping endpoint for plugin verification during onboarding
	 * Returns minimal info - no version numbers exposed for security
	 *
	 * @return WP_REST_Response
	 */
	public function get_ping() {
		return rest_ensure_response(
			array(
				'active'             => true,
				'woocommerce_active' => class_exists( 'WooCommerce' ) && function_exists( 'WC' ),
			)
		);
	}

	/**
	 * Status catalogue for this store.
	 *
	 * Lets StoreDash discover custom order statuses (Order Status Manager, our own
	 * wc-ready-pickup, …) and custom product statuses instead of assuming the
	 * WooCommerce core set. Runs on rest_api_init, i.e. after init:0, so every
	 * status registered on init is already present.
	 *
	 * Response:
	 *   order_statuses[]   { slug, raw_slug, label, core, exclude_from_search, count }
	 *   product_statuses[] { slug, label, core, exclude_from_search, count }
	 *   hpos_enabled       bool
	 *
	 * `exclude_from_search` is null for order statuses that exist only via the
	 * wc_order_statuses filter (no register_post_status call). `count` is null when
	 * a count cannot be obtained without an expensive uncached query.
	 *
	 * @return WP_REST_Response
	 */
	public function get_statuses() {
		$hpos_enabled = false;
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
		) {
			$hpos_enabled = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return rest_ensure_response(
			array(
				'order_statuses'   => $this->get_order_status_catalogue(),
				'product_statuses' => class_exists( 'StoreDash_Product_Status_Support' )
					? StoreDash_Product_Status_Support::get_product_statuses()
					: array(),
				'hpos_enabled'     => $hpos_enabled,
			)
		);
	}

	/**
	 * Build the order status catalogue.
	 *
	 * Counts come from wc_orders_count(), which is backed by WooCommerce's own
	 * order-count cache and works under both HPOS and legacy CPT storage. Any
	 * status WooCommerce cannot count is reported as null rather than 0.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_order_status_catalogue() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$core_slugs = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' );
		$catalogue  = array();

		foreach ( wc_get_order_statuses() as $raw_slug => $label ) {
			$slug       = 0 === strpos( $raw_slug, 'wc-' ) ? substr( $raw_slug, 3 ) : $raw_slug;
			$status_obj = get_post_status_object( $raw_slug );
			$count      = null;

			if ( function_exists( 'wc_orders_count' ) ) {
				try {
					$count = (int) wc_orders_count( $raw_slug, 'shop_order' );
				} catch ( Throwable $e ) {
					$count = null;
				}
			}

			$catalogue[] = array(
				'slug'                => $slug,
				'raw_slug'            => $raw_slug,
				'label'               => (string) $label,
				'core'                => in_array( $slug, $core_slugs, true ),
				// Null when the status was only added through the wc_order_statuses
				// filter and never registered with register_post_status().
				'exclude_from_search' => $status_obj ? (bool) $status_obj->exclude_from_search : null,
				'count'               => $count,
			);
		}

		return $catalogue;
	}

	/**
	 * Update StoreDash settings (wp_options).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$updated = array();

		$storefront_url = $request->get_param( 'storedash_storefront_url' );
		if ( null !== $storefront_url ) {
			update_option( 'storedash_storefront_url', rtrim( $storefront_url, '/' ), false );
			$updated['storedash_storefront_url'] = get_option( 'storedash_storefront_url' );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'updated' => $updated,
			)
		);
	}

	/**
	 * Log API errors
	 *
	 * @param string $message Error message
	 * @param string $level Log level
	 */
	private function log_api_error( $message, $level = 'error' ) {
		if ( class_exists( 'StoreDash_Helpers' ) ) {
			StoreDash_Helpers::log_message( 'API: ' . $message, $level );
		} else {
			// Fallback if helpers not loaded
			error_log(
				sprintf(
					'[%s] StoreDash API %s: %s',
					current_time( 'Y-m-d H:i:s' ),
					strtoupper( $level ),
					$message
				)
			);
		}
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup() {}
}

