<?php
declare(strict_types=1);

/**
 * Products Bulk Controller
 *
 * @package StoreDash\Products
 * @since   1.16.0
 */

namespace StoreDash\Products\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time-budgeted bulk product writes.
 *
 * `POST /storedash/v1/products/bulk` applies a list of product / variation
 * updates by running each one through WooCommerce's own REST update
 * controller (`WC_REST_Products_Controller::update_item` /
 * `WC_REST_Product_Variations_Controller::update_item`). That keeps the exact
 * field semantics, validation, lookup-table maintenance and hooks of the
 * native `wc/v3` write path, while avoiding the two things that make the
 * native `/products/batch` endpoint time out on slow hosts:
 *
 *  1. the full product response is never built — every inner request carries
 *     `_fields=id`, so WooCommerce only serialises the id;
 *  2. the loop stops when its wall-clock budget is spent and reports which
 *     items it did not reach, so the caller re-sends only those instead of
 *     guessing a chunk size that fits the store.
 *
 * Per-item outcomes are reported individually; the endpoint itself only
 * fails for malformed input or a missing WooCommerce REST layer.
 *
 * @since 1.16.0
 */
class Products_Bulk_Controller {

	/**
	 * Hard cap on items per request. Matches the WooCommerce batch cap.
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Default wall-clock budget when the caller sends none.
	 */
	public const DEFAULT_BUDGET_MS = 20000;

	/**
	 * Smallest budget accepted — below this nothing meaningful completes.
	 */
	public const MIN_BUDGET_MS = 1000;

	/**
	 * Largest budget accepted, regardless of what the caller asks for.
	 */
	public const MAX_BUDGET_MS = 60000;

	/**
	 * Share of PHP's `max_execution_time` we allow the loop to consume. The
	 * remainder is headroom for bootstrap (already spent before we run) and for
	 * the single item that may overrun the deadline.
	 */
	private const EXECUTION_TIME_SHARE = 0.8;

	/**
	 * Keys the caller must not put inside `data` — they are addressed by the
	 * item envelope and set by this controller.
	 */
	private const RESERVED_DATA_KEYS = array( 'id', 'product_id', 'parent_id' );

	/**
	 * Lazily created WooCommerce controllers, shared across items.
	 *
	 * @var array<string, object>
	 */
	private $wc_controllers = array();

	/**
	 * REST argument schema for the route.
	 *
	 * @return array
	 */
	public function get_args(): array {
		return array(
			'items'     => array(
				'required'          => true,
				'type'              => 'array',
				'minItems'          => 1,
				'maxItems'          => self::MAX_ITEMS,
				'validate_callback' => array( $this, 'validate_items_arg' ),
			),
			'budget_ms' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => self::MIN_BUDGET_MS,
				'maximum'  => self::MAX_BUDGET_MS,
				'default'  => self::DEFAULT_BUDGET_MS,
			),
		);
	}

	/**
	 * REST `validate_callback` for `items`.
	 *
	 * @param mixed $value Raw request value.
	 * @return true|\WP_Error
	 */
	public function validate_items_arg( $value ) {
		$result = self::normalize_items( $value );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				$result['error']['code'],
				$result['error']['message'],
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		if ( ! class_exists( 'WC_REST_Products_Controller' ) || ! class_exists( 'WC_REST_Product_Variations_Controller' ) ) {
			return new \WP_Error(
				'wc_rest_unavailable',
				__( 'The WooCommerce REST API is not available on this store.', 'storedash' ),
				array( 'status' => 503 )
			);
		}

		$normalized = self::normalize_items( $request->get_param( 'items' ) );
		if ( isset( $normalized['error'] ) ) {
			return new \WP_Error(
				$normalized['error']['code'],
				$normalized['error']['message'],
				array( 'status' => 400 )
			);
		}

		$requested_budget = $request->get_param( 'budget_ms' );
		$budget_ms        = self::effective_budget(
			is_numeric( $requested_budget ) ? (int) $requested_budget : self::DEFAULT_BUDGET_MS,
			(int) ini_get( 'max_execution_time' ),
			self::elapsed_since_request_start_ms()
		);

		// Term counts are recomputed once at the end instead of per product —
		// the same trick the WooCommerce importer uses for bulk writes.
		wp_defer_term_counting( true );
		try {
			$result = $this->process(
				$normalized['items'],
				$budget_ms,
				array( $this, 'apply_item' )
			);
		} finally {
			wp_defer_term_counting( false );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Run the budgeted loop. Pure apart from the injected callables, so it is
	 * unit-testable without WordPress.
	 *
	 * @param array         $items     Normalized items (see normalize_items).
	 * @param int           $budget_ms Wall-clock budget for this call.
	 * @param callable      $apply     `function( array $item ): array` returning
	 *                                 `['ok' => bool, 'code' => ?string, 'message' => ?string]`.
	 * @param callable|null $clock     `function(): float` returning milliseconds.
	 *                                 Defaults to microtime.
	 * @return array{done: array, remaining: array, processed: int, elapsed_ms: int, budget_ms: int}
	 */
	public function process( array $items, int $budget_ms, callable $apply, ?callable $clock = null ): array {
		$now      = $clock ?? static function (): float {
			return microtime( true ) * 1000;
		};
		$started  = $now();
		$deadline = $started + $budget_ms;

		$done      = array();
		$remaining = array();

		foreach ( $items as $index => $item ) {
			// Always attempt the first item so a tiny budget still makes progress.
			if ( $index > 0 && $now() >= $deadline ) {
				$remaining[] = $item['id'];
				continue;
			}

			try {
				$outcome = $apply( $item );
			} catch ( \Throwable $e ) {
				$outcome = array(
					'ok'      => false,
					'code'    => 'exception',
					'message' => $e->getMessage(),
				);
				if ( class_exists( '\StoreDash_Helpers' ) ) {
					\StoreDash_Helpers::log_message(
						'products/bulk: unhandled exception applying item',
						'error',
						array(
							'id'        => $item['id'],
							'parent_id' => $item['parent_id'],
							'exception' => get_class( $e ),
							'message'   => $e->getMessage(),
						)
					);
				}
			}

			$entry = array(
				'id' => $item['id'],
				'ok' => ! empty( $outcome['ok'] ),
			);
			if ( ! $entry['ok'] ) {
				$entry['code']    = isset( $outcome['code'] ) ? (string) $outcome['code'] : 'unknown';
				$entry['message'] = isset( $outcome['message'] ) ? (string) $outcome['message'] : '';
			}
			$done[] = $entry;
		}

		return array(
			'done'       => $done,
			'remaining'  => $remaining,
			'processed'  => count( $done ),
			'elapsed_ms' => (int) round( $now() - $started ),
			'budget_ms'  => $budget_ms,
		);
	}

	/**
	 * Apply one normalized item through the WooCommerce REST controllers.
	 *
	 * @param array $item Normalized item.
	 * @return array{ok: bool, code?: string, message?: string}
	 */
	public function apply_item( array $item ): array {
		$id        = $item['id'];
		$parent_id = $item['parent_id'];

		$product = wc_get_product( $id );
		if ( ! $product || 0 === $product->get_id() ) {
			return array(
				'ok'      => false,
				'code'    => 'product_not_found',
				'message' => __( 'No product with this ID exists on the store.', 'storedash' ),
			);
		}

		$is_variation = $product->is_type( 'variation' );

		if ( $parent_id > 0 ) {
			if ( ! $is_variation ) {
				return array(
					'ok'      => false,
					'code'    => 'not_a_variation',
					'message' => __( 'parent_id was given but the ID is not a variation.', 'storedash' ),
				);
			}
			if ( (int) $product->get_parent_id() !== $parent_id ) {
				return array(
					'ok'      => false,
					'code'    => 'variation_parent_mismatch',
					'message' => __( 'The variation does not belong to the given parent product.', 'storedash' ),
				);
			}
			$controller = $this->wc_controller( 'variation' );
			$route      = '/wc/v3/products/' . $parent_id . '/variations/' . $id;
			$url_params = array(
				'product_id' => $parent_id,
				'id'         => $id,
			);
		} else {
			if ( $is_variation ) {
				return array(
					'ok'      => false,
					'code'    => 'variation_requires_parent_id',
					'message' => __( 'Variations must be sent with their parent_id.', 'storedash' ),
				);
			}
			$controller = $this->wc_controller( 'product' );
			$route      = '/wc/v3/products/' . $id;
			$url_params = array( 'id' => $id );
		}

		$inner = new \WP_REST_Request( 'PUT', $route );
		$inner->set_url_params( $url_params );
		// `_fields=id` makes WooCommerce serialise only the id in the response —
		// the expensive part of a native product write on slow hosts.
		$inner->set_query_params( array( '_fields' => 'id' ) );
		$inner->set_body_params( array_merge( $item['data'], $url_params ) );

		$response = $controller->update_item( $inner );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => (string) $response->get_error_code(),
				'message' => (string) $response->get_error_message(),
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Validate and normalize the raw `items` payload.
	 *
	 * Each item becomes `['id' => int, 'parent_id' => int (0 = product), 'data' => array]`.
	 *
	 * @param mixed $raw Raw request value.
	 * @return array{items?: array, error?: array{code: string, message: string}}
	 */
	public static function normalize_items( $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return self::invalid( 'empty_items', 'items must be a non-empty array.' );
		}
		if ( count( $raw ) > self::MAX_ITEMS ) {
			return self::invalid( 'too_many_items', sprintf( 'items may contain at most %d entries.', self::MAX_ITEMS ) );
		}

		$items    = array();
		$seen_ids = array();
		foreach ( array_values( $raw ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::invalid( 'invalid_item', sprintf( 'items[%d] must be an object.', $index ) );
			}

			$id = isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;
			if ( $id <= 0 ) {
				return self::invalid( 'invalid_item_id', sprintf( 'items[%d].id must be a positive integer.', $index ) );
			}

			$parent_id = 0;
			if ( array_key_exists( 'parent_id', $entry ) && null !== $entry['parent_id'] ) {
				$parent_id = is_numeric( $entry['parent_id'] ) ? (int) $entry['parent_id'] : -1;
				if ( $parent_id <= 0 ) {
					return self::invalid( 'invalid_parent_id', sprintf( 'items[%d].parent_id must be a positive integer when present.', $index ) );
				}
			}

			$data = isset( $entry['data'] ) ? $entry['data'] : null;
			if ( ! is_array( $data ) || array() === $data ) {
				return self::invalid( 'empty_item_data', sprintf( 'items[%d].data must be a non-empty object.', $index ) );
			}
			foreach ( self::RESERVED_DATA_KEYS as $reserved ) {
				if ( array_key_exists( $reserved, $data ) ) {
					return self::invalid( 'reserved_data_key', sprintf( 'items[%d].data must not contain "%s".', $index, $reserved ) );
				}
			}

			$dedupe_key = $parent_id . ':' . $id;
			if ( isset( $seen_ids[ $dedupe_key ] ) ) {
				return self::invalid( 'duplicate_item', sprintf( 'items[%d] repeats id %d.', $index, $id ) );
			}
			$seen_ids[ $dedupe_key ] = true;

			$items[] = array(
				'id'        => $id,
				'parent_id' => $parent_id,
				'data'      => $data,
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Clamp the requested budget and keep it inside PHP's execution limit.
	 *
	 * @param int $requested_ms          Budget the caller asked for.
	 * @param int $max_execution_time    `max_execution_time` in seconds (0 = unlimited).
	 * @param int $already_elapsed_ms    Milliseconds spent since the PHP request began.
	 * @return int
	 */
	public static function effective_budget( int $requested_ms, int $max_execution_time, int $already_elapsed_ms = 0 ): int {
		$budget = max( self::MIN_BUDGET_MS, min( self::MAX_BUDGET_MS, $requested_ms ) );

		if ( $max_execution_time > 0 ) {
			$php_ceiling = (int) floor( $max_execution_time * 1000 * self::EXECUTION_TIME_SHARE ) - max( 0, $already_elapsed_ms );
			$budget      = min( $budget, max( self::MIN_BUDGET_MS, $php_ceiling ) );
		}

		return $budget;
	}

	/**
	 * Milliseconds since PHP started handling this request.
	 *
	 * @return int
	 */
	private static function elapsed_since_request_start_ms(): int {
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		}
		return 0;
	}

	/**
	 * Lazily build the WooCommerce REST controller for a kind of object.
	 *
	 * @param string $kind 'product' or 'variation'.
	 * @return object
	 */
	private function wc_controller( string $kind ) {
		if ( ! isset( $this->wc_controllers[ $kind ] ) ) {
			$this->wc_controllers[ $kind ] = 'variation' === $kind
				? new \WC_REST_Product_Variations_Controller()
				: new \WC_REST_Products_Controller();
		}
		return $this->wc_controllers[ $kind ];
	}

	/**
	 * Build a validation failure.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function invalid( string $code, string $message ): array {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
