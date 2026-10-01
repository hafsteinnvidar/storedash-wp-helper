<?php
declare(strict_types=1);

/**
 * Products Menu Order Controller
 *
 * @package StoreDash\Products
 * @since   1.23.0
 */

namespace StoreDash\Products\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk shop-order (menu_order) writes.
 *
 * `POST /storedash/v1/products/menu-order` sets `menu_order` on a list of
 * products the way WooCommerce's own "Sorting" screen does
 * (`WC_AJAX::product_ordering`): a direct `posts` update per product, a post
 * cache clean for the rows that changed, and one product-query transient
 * flush at the end. No product object is loaded or saved, so a whole-catalog
 * renumber costs one cheap query per product instead of a full product save
 * (hooks, lookup tables, webhooks) each.
 *
 * Like core's sorting, this does not fire `woocommerce_update_product` and
 * does not touch `post_modified`.
 *
 * @since 1.23.0
 */
class Products_Menu_Order_Controller {

	/**
	 * Hard cap on items per request.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * REST argument schema for the route.
	 *
	 * @return array
	 */
	public function get_args(): array {
		return array(
			'items' => array(
				'required'          => true,
				'type'              => 'array',
				'minItems'          => 1,
				'maxItems'          => self::MAX_ITEMS,
				'validate_callback' => array( $this, 'validate_items_arg' ),
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
		$normalized = self::normalize_items( $request->get_param( 'items' ) );
		if ( isset( $normalized['error'] ) ) {
			return new \WP_Error(
				$normalized['error']['code'],
				$normalized['error']['message'],
				array( 'status' => 400 )
			);
		}

		$items   = $normalized['items'];
		$current = $this->read_current_positions( array_column( $items, 'id' ) );
		$result  = $this->process( $items, $current, array( $this, 'write_position' ) );

		if ( $result['changed'] > 0 && class_exists( 'WC_Post_Data' ) ) {
			\WC_Post_Data::delete_product_query_transients();
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Decide and apply each item. Pure apart from the injected writer, so it is
	 * unit-testable without WordPress.
	 *
	 * @param array    $items   Normalized items (see normalize_items).
	 * @param array    $current Map of product ID => current menu_order, products only.
	 * @param callable $write   `function( int $id, int $menu_order ): bool` — true when stored.
	 * @return array{done: array, changed: int}
	 */
	public function process( array $items, array $current, callable $write ): array {
		$done    = array();
		$changed = 0;

		foreach ( $items as $item ) {
			$id = $item['id'];

			if ( ! array_key_exists( $id, $current ) ) {
				$done[] = array(
					'id'      => $id,
					'ok'      => false,
					'code'    => 'product_not_found',
					'message' => 'No product with this ID exists on the store.',
				);
				continue;
			}

			if ( (int) $current[ $id ] === $item['menu_order'] ) {
				$done[] = array(
					'id' => $id,
					'ok' => true,
				);
				continue;
			}

			if ( ! $write( $id, $item['menu_order'] ) ) {
				$done[] = array(
					'id'      => $id,
					'ok'      => false,
					'code'    => 'db_update_failed',
					'message' => 'The position could not be saved.',
				);
				continue;
			}

			++$changed;
			$done[] = array(
				'id' => $id,
				'ok' => true,
			);
		}

		return array(
			'done'    => $done,
			'changed' => $changed,
		);
	}

	/**
	 * Validate and normalize the raw `items` payload.
	 *
	 * Each item becomes `['id' => int, 'menu_order' => int]`.
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

		$items = array();
		$seen  = array();
		foreach ( array_values( $raw ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::invalid( 'invalid_item', sprintf( 'items[%d] must be an object.', $index ) );
			}

			$id = isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;
			if ( $id <= 0 ) {
				return self::invalid( 'invalid_item_id', sprintf( 'items[%d].id must be a positive integer.', $index ) );
			}

			if ( ! isset( $entry['menu_order'] ) || ! is_numeric( $entry['menu_order'] ) || (int) $entry['menu_order'] != $entry['menu_order'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- numeric strings are valid JSON-decoded input.
				return self::invalid( 'invalid_menu_order', sprintf( 'items[%d].menu_order must be an integer.', $index ) );
			}

			if ( isset( $seen[ $id ] ) ) {
				return self::invalid( 'duplicate_item', sprintf( 'items[%d] repeats id %d.', $index, $id ) );
			}
			$seen[ $id ] = true;

			$items[] = array(
				'id'         => $id,
				'menu_order' => (int) $entry['menu_order'],
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Current menu_order of the given IDs, limited to real products (never
	 * variations or other post types).
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,int>
	 */
	private function read_current_positions( array $ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type = 'product' AND ID IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$ids
			)
		);

		$current = array();
		foreach ( (array) $rows as $row ) {
			$current[ (int) $row->ID ] = (int) $row->menu_order;
		}
		return $current;
	}

	/**
	 * Store one position and drop the product's post cache.
	 *
	 * @param int $id         Product ID.
	 * @param int $menu_order New position.
	 * @return bool
	 */
	public function write_position( int $id, int $menu_order ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'menu_order' => $menu_order ),
			array( 'ID' => $id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}

		clean_post_cache( $id );
		return true;
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
