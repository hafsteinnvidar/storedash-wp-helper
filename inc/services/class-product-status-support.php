<?php
/**
 * Product Status Support
 *
 * WooCommerce's wc/v3 products collection validates the `status`, `include_status`
 * and `exclude_status` params against a fixed enum built from get_post_statuses()
 * (publish/draft/pending/private) plus future/trash. Any custom product post status
 * registered by a third-party plugin is therefore rejected with a 400 before the
 * query ever runs, even though WP_Query itself handles the status fine.
 *
 * This class widens that enum with the store's registered custom statuses so
 * StoreDash can list products in them, and describes the product status catalogue
 * for the /storedash/v1/statuses endpoint.
 *
 * Purely additive: when the store has no custom statuses every filter here returns
 * its input untouched, and requests that use only core slugs are never modified.
 *
 * @package StoreDash
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Product_Status_Support {

	/**
	 * Statuses WordPress registers for every post type. Used both to keep them out
	 * of the "custom" list and to flag them as core in the status catalogue.
	 *
	 * @var string[]
	 */
	const BUILTIN_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' );

	/**
	 * Built-in statuses that are always reported for products, even at zero rows.
	 * The remaining built-ins (trash/auto-draft/inherit) are internal and only
	 * reported when the store actually has products in them.
	 *
	 * @var string[]
	 */
	const ALWAYS_REPORTED_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Product collection routes whose status params get widened.
	 *
	 * @var string[]
	 */
	const PRODUCT_ROUTES = array( '/wc/v3/products', '/wc/v2/products' );

	/**
	 * Single instance
	 *
	 * @var StoreDash_Product_Status_Support|null
	 */
	private static $instance = null;

	/**
	 * Memoized custom status slugs for the current request.
	 *
	 * @var string[]|null
	 */
	private static $custom_slugs = null;

	/**
	 * Get singleton instance
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks into the REST layer.
	 *
	 * `rest_endpoints` runs once per REST request inside WP_REST_Server::get_routes(),
	 * which is where the registered arg schema (and therefore the enum the dispatcher
	 * validates against) is resolved. Both callbacks short-circuit on the first line
	 * when the store has no custom statuses.
	 */
	private function __construct() {
		add_filter( 'rest_endpoints', array( $this, 'widen_product_status_params' ) );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'honour_custom_status_query' ), 10, 2 );
	}

	/**
	 * Custom product post statuses registered on this store.
	 *
	 * A status qualifies when it is registered with WordPress (WP_Query only honours
	 * registered statuses), is not one of the WordPress built-ins, is not internal
	 * (auto-draft, inherit, the privacy request-* statuses) and is not a WooCommerce
	 * order status. register_post_status() is not post-type scoped, so this is the
	 * closest available approximation of "custom statuses a product may have".
	 *
	 * Memoized per request; reads only in-memory globals (no queries).
	 *
	 * @return string[] Status slugs.
	 */
	public static function get_custom_status_slugs(): array {
		if ( null !== self::$custom_slugs ) {
			return self::$custom_slugs;
		}

		$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$slugs          = array();

		foreach ( get_post_stati( array(), 'objects' ) as $slug => $status ) {
			if ( in_array( $slug, self::BUILTIN_STATUSES, true ) ) {
				continue;
			}
			if ( ! empty( $status->_builtin ) || ! empty( $status->internal ) ) {
				continue;
			}
			// WooCommerce order statuses share the global status registry.
			if ( isset( $order_statuses[ $slug ] ) || 0 === strpos( $slug, 'wc-' ) ) {
				continue;
			}
			$slugs[] = $slug;
		}

		self::$custom_slugs = $slugs;
		return self::$custom_slugs;
	}

	/**
	 * Describe the product status catalogue for /storedash/v1/statuses.
	 *
	 * Counts come from wp_count_posts( 'product' ), which is object-cached and
	 * already keyed by every registered status.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_product_statuses(): array {
		$counts  = (array) wp_count_posts( 'product' );
		$customs = self::get_custom_status_slugs();
		$report  = array();

		foreach ( get_post_stati( array(), 'objects' ) as $slug => $status ) {
			$count = isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;

			$always = in_array( $slug, self::ALWAYS_REPORTED_STATUSES, true ) || in_array( $slug, $customs, true );
			if ( ! $always && $count < 1 ) {
				continue;
			}

			$report[] = array(
				'slug'                => $slug,
				'label'               => isset( $status->label ) ? (string) $status->label : $slug,
				'core'                => in_array( $slug, self::BUILTIN_STATUSES, true ),
				'exclude_from_search' => (bool) ( $status->exclude_from_search ?? false ),
				'count'               => $count,
			);
		}

		return $report;
	}

	/**
	 * Add the store's custom statuses to the products collection status enums.
	 *
	 * Widens three params on the products list route:
	 *  - `status`         (string, sanitize_key — single slug only, commas are stripped)
	 *  - `include_status` (array, wp_parse_list — supports `include_status=a,b`)
	 *  - `exclude_status` (array, wp_parse_list — supports `exclude_status=a,b`)
	 *
	 * Multi-status filtering therefore goes through `include_status`; `status` cannot
	 * carry a comma-separated list because WooCommerce sanitizes it with sanitize_key.
	 *
	 * Only the enum lists grow, so every previously valid request stays valid and is
	 * routed exactly as before.
	 *
	 * @param array<string, array> $endpoints Registered REST endpoints, route => handlers.
	 * @return array<string, array>
	 */
	public function widen_product_status_params( $endpoints ) {
		$custom = self::get_custom_status_slugs();
		if ( empty( $custom ) || ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( self::PRODUCT_ROUTES as $route ) {
			if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
				continue;
			}

			foreach ( $endpoints[ $route ] as $index => $handler ) {
				if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
					continue;
				}
				// Collection params only live on the readable handler.
				if ( empty( $handler['methods']['GET'] ) ) {
					continue;
				}

				$endpoints[ $route ][ $index ]['args'] = self::merge_status_enums( $handler['args'], $custom );
			}
		}

		return $endpoints;
	}

	/**
	 * Merge custom slugs into the status-related enums of a handler's args.
	 *
	 * @param array    $args   Handler args.
	 * @param string[] $custom Custom status slugs.
	 * @return array
	 */
	private static function merge_status_enums( array $args, array $custom ): array {
		if ( isset( $args['status']['enum'] ) && is_array( $args['status']['enum'] ) ) {
			$args['status']['enum'] = array_values( array_unique( array_merge( $args['status']['enum'], $custom ) ) );
		}

		foreach ( array( 'include_status', 'exclude_status' ) as $key ) {
			if ( isset( $args[ $key ]['items']['enum'] ) && is_array( $args[ $key ]['items']['enum'] ) ) {
				$args[ $key ]['items']['enum'] = array_values(
					array_unique( array_merge( $args[ $key ]['items']['enum'], $custom ) )
				);
			}
		}

		return $args;
	}

	/**
	 * Make sure a custom `status` reaches WP_Query as post_status.
	 *
	 * WC_REST_Products_Controller::prepare_objects_query() already assigns
	 * $args['post_status'] = $request['status'], so this is a defensive no-op on
	 * current WooCommerce: it only writes when the request asked for a custom status
	 * and the arg does not already reflect it. `include_status` takes precedence in
	 * WooCommerce, so it is left alone when present.
	 *
	 * @param array            $args    Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function honour_custom_status_query( $args, $request ) {
		$custom = self::get_custom_status_slugs();
		if ( empty( $custom ) || ! is_array( $args ) ) {
			return $args;
		}

		$status = isset( $request['status'] ) ? $request['status'] : '';
		if ( ! is_string( $status ) || '' === $status || ! in_array( $status, $custom, true ) ) {
			return $args;
		}

		if ( ! empty( $request['include_status'] ) ) {
			return $args;
		}

		if ( isset( $args['post_status'] ) && $args['post_status'] === $status ) {
			return $args;
		}

		$args['post_status'] = $status;
		return $args;
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup(): void {}
}
