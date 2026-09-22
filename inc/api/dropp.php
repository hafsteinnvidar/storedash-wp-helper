<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Dropp Consignment Controller
 *
 * Records a shipment that StoreDash already booked with Dropp into the
 * dropp-for-woocommerce plugin's own consignment table (`{prefix}dropp_consignments`),
 * so that plugin's order columns / metabox display it natively.
 *
 * IMPORTANT: StoreDash is the booker — it has already called the Dropp API and
 * holds the barcode. This endpoint ONLY mirrors that result into WordPress via
 * the Dropp model's plain DB insert (`save()`); it never calls the Dropp API, so
 * there is no risk of double-booking.
 */
class StoreDash_API_Dropp {

	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor — self-registers routes (mirrors Waitlist_API).
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the Dropp consignment route.
	 */
	public function register_routes() {
		$id_arg = array(
			'id' => array(
				'required'          => true,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/dropp-consignment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_consignment' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => $id_arg,
			)
		);

		// CI-1: cancelling in StoreDash used to leave the WooCommerce-side
		// consignment showing as booked. This marks it `cancelled` (a status the
		// serializer now excludes) and clears `_dropp_added` when no booked
		// consignment is left, so both systems agree the parcel is gone.
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/dropp-consignment',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'cancel_consignment' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => $id_arg,
			)
		);
	}

	/**
	 * Mark a StoreDash-booked Dropp consignment cancelled.
	 *
	 * Never calls the Dropp API — StoreDash has already done that. Scoped by
	 * barcode when one is supplied so a second consignment on the same order is
	 * untouched; falls back to every booked consignment on the order otherwise.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_consignment( $request ) {
		if ( ! class_exists( '\\Dropp\\Models\\Dropp_Consignment' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'skipped' => 'dropp_plugin_inactive',
				),
				200
			);
		}

		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$shipping_item_ids = array();
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$shipping_item_ids[] = (int) $item_id;
		}
		if ( empty( $shipping_item_ids ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'skipped' => 'no_shipping_item',
				),
				200
			);
		}

		$barcode = $this->nullable_text( $request->get_param( 'barcode' ) );

		global $wpdb;
		$item_placeholders = implode( ', ', array_fill( 0, count( $shipping_item_ids ), '%d' ) );

		if ( null !== $barcode ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}dropp_consignments SET status = 'cancelled' WHERE barcode = %s AND shipping_item_id IN ( {$item_placeholders} )",
					$barcode,
					...$shipping_item_ids
				)
			);
		} else {
			$non_booked   = self::cancellable_statuses();
			$placeholders = implode( ', ', array_fill( 0, count( $non_booked ), '%s' ) );
			$updated      = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}dropp_consignments SET status = 'cancelled' WHERE shipping_item_id IN ( {$item_placeholders} ) AND ( status IS NULL OR status NOT IN ( {$placeholders} ) )",
					...array_merge( $shipping_item_ids, $non_booked )
				)
			);
		}

		// Clear the plugin's "has a Dropp shipment" flag only when nothing booked
		// remains — a second, still-live consignment must keep it set.
		$non_booked   = self::cancellable_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $non_booked ), '%s' ) );
		$remaining    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}dropp_consignments WHERE shipping_item_id IN ( {$item_placeholders} ) AND ( status IS NULL OR status NOT IN ( {$placeholders} ) )",
				...array_merge( $shipping_item_ids, $non_booked )
			)
		);
		if ( 0 === $remaining ) {
			$order->delete_meta_data( '_dropp_added' );
			$order->save();
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'cancelled' => is_numeric( $updated ) ? (int) $updated : 0,
			),
			200
		);
	}

	/**
	 * Statuses that do NOT count as a live booked consignment.
	 *
	 * @return array<int,string>
	 */
	private static function cancellable_statuses(): array {
		return StoreDash_Dropp_Order_Meta::NON_BOOKED_STATUSES;
	}

	/**
	 * Record a StoreDash-booked Dropp consignment for an order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_consignment( $request ) {
		// The Dropp plugin must be active; otherwise there is nothing to write to.
		if ( ! class_exists( '\\Dropp\\Models\\Dropp_Consignment' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'skipped' => 'dropp_plugin_inactive',
				),
				200
			);
		}

		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$barcode = sanitize_text_field( (string) $request->get_param( 'barcode' ) );
		if ( '' === $barcode ) {
			return new WP_Error(
				'missing_barcode',
				__( 'A barcode is required.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		// The Dropp consignment table keys on the order's shipping line item id.
		$shipping_item_id = 0;
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$shipping_item_id = (int) $item_id;
			break;
		}
		if ( ! $shipping_item_id ) {
			return new WP_Error(
				'no_shipping_item',
				__( 'Order has no shipping line item.', 'storedash' ),
				array( 'status' => 422 )
			);
		}

		// Idempotency: never create a second consignment for the same shipping item
		// (woo-dash may retry, and single + bulk paths could both fire). Only BOOKED
		// consignments count — an unbooked draft (merchant clicked "Get new barcode"
		// in the WC Dropp UI, status 'ready') must not block recording StoreDash's
		// real booking. Mirror build_payload()'s booked definition: exclude
		// ready/error/overweight, but keep NULL-status rows (treated as booked there).
		global $wpdb;
		$non_booked   = StoreDash_Dropp_Order_Meta::NON_BOOKED_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $non_booked ), '%s' ) );
		$existing     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}dropp_consignments WHERE shipping_item_id = %d AND ( status IS NULL OR status NOT IN ( {$placeholders} ) )",
				$shipping_item_id,
				...$non_booked
			)
		);
		if ( $existing > 0 ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'skipped' => 'already_exists',
				),
				200
			);
		}

		$products = $request->get_param( 'products' );
		$customer = $request->get_param( 'customer' );
		$status   = $this->nullable_text( $request->get_param( 'status' ) );
		$value    = $request->get_param( 'value' );

		try {
			$consignment = new \Dropp\Models\Dropp_Consignment();
			$consignment->fill(
				array(
					'barcode'          => $barcode,
					'return_barcode'   => $this->nullable_text( $request->get_param( 'return_barcode' ) ),
					'dropp_order_id'   => $this->nullable_text( $request->get_param( 'dropp_order_id' ) ),
					'shipping_item_id' => $shipping_item_id,
					'location_id'      => (string) $request->get_param( 'location_id' ),
					// A StoreDash booking is already live at Dropp; default to the
					// Dropp post-booking status so it counts as "booked" (the plugin
					// excludes only ready/error/overweight from the booked count).
					'status'           => $status ?? 'initial',
					'day_delivery'     => (bool) $request->get_param( 'day_delivery' ),
					'value'            => is_numeric( $value ) ? (float) $value : null,
					'products'         => is_array( $products ) ? $products : array(),
					'customer'         => is_array( $customer ) ? $customer : array(),
				)
			);
			$consignment->save();
		} catch ( Exception $e ) {
			return new WP_Error(
				'consignment_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		// Mirror the Dropp plugin's own "added" flag so its checkout/admin logic
		// treats the order as having a Dropp shipment.
		$order->update_meta_data( '_dropp_added', '1' );
		$order->save();

		return new WP_REST_Response(
			array(
				'success'        => true,
				'consignment_id' => isset( $consignment->id ) ? (int) $consignment->id : null,
			),
			201
		);
	}

	/**
	 * Normalize an optional text param to a trimmed string or null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function nullable_text( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return sanitize_text_field( (string) $value );
	}
}

// Initialize (self-registering, mirrors Waitlist_API).
if ( function_exists( 'add_action' ) ) {
	new StoreDash_API_Dropp();
}

/**
 * StoreDash — expose Dropp consignments on the WC REST order response.
 *
 * The Dropp plugin stores booked consignments in its own DB table
 * (`{prefix}dropp_consignments`), linked to the order only via the shipping line
 * item id — never in order meta and never in any REST route. StoreDash's Go sync
 * reads vanilla `/wc/v3/orders`, so it can see the Dropp *location* (from the
 * shipping line) but never the barcode / dropp_order_id of an order booked
 * directly in WooCommerce. That leaves the orders list offering "Create shipment"
 * for orders that are in fact already shipped.
 *
 * This filter closes the gap WITHOUT a new route: it hangs off the order
 * serializer and appends ONE synthetic meta entry, `_storedash_dropp_consignments`,
 * whose value is a JSON array of the order's consignments
 * ({barcode, dropp_order_id, return_barcode, status, shipping_item_id}). The Go
 * sync persists order meta verbatim, and `ingest_dropp_shipments()` reads that
 * key to enrich `order_shipments`. See
 * supabase/migrations/20260724000010_ingest_dropp_shipments.sql.
 *
 * Invariants:
 *   - Adds NOTHING for orders with no booked consignment (no bloat on non-Dropp
 *     orders) and nothing when the Dropp plugin is inactive.
 *   - Never fatal: any error returns the response unchanged (a serializer helper
 *     must never break the orders API). Works for both HPOS and legacy storage.
 */
class StoreDash_Dropp_Order_Meta {

	const META_KEY = '_storedash_dropp_consignments';

	/**
	 * Consignment statuses that are NOT a booked shipment and must be excluded.
	 * Mirrors the Dropp plugin's own "booked" definition
	 * (Order_Adapter::count_consignments: status NOT IN ready/error/overweight):
	 *   - 'ready'      = unbooked draft. NOTE: a draft already carries a barcode
	 *                    (Dropp's "Get new barcode" issues it before the order is
	 *                    POSTed), so barcode-presence alone is NOT proof of booking.
	 *   - 'error'      = booking failed.
	 *   - 'overweight' = rejected by Dropp.
	 *   - 'cancelled'  = cancelled in StoreDash (CI-1). Wider than the Dropp
	 *                    plugin's own count, deliberately: a cancelled parcel is
	 *                    not a shipment, and re-exporting it would let the SQL
	 *                    ingest resurrect a row StoreDash has already cancelled.
	 * Kept in sync with the SQL ingest (ingest_dropp_shipments).
	 */
	const NON_BOOKED_STATUSES = array( 'ready', 'error', 'overweight', 'cancelled' );

	/**
	 * Constructor — self-registers on the order REST serializer.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'append_consignments' ), 20, 3 );
	}

	/**
	 * Append the order's Dropp consignments to the REST response meta_data.
	 *
	 * @param mixed $response WP_REST_Response (passed through unchanged on any guard miss).
	 * @param mixed $object   The prepared order object (WC_Order under HPOS + legacy).
	 * @param mixed $request  WP_REST_Request (unused).
	 * @return mixed The (possibly augmented) response.
	 */
	public function append_consignments( $response, $object, $request ) {
		try {
			// Nothing to expose if the Dropp plugin is not installed/active.
			if ( ! class_exists( '\\Dropp\\Models\\Dropp_Consignment' ) ) {
				return $response;
			}
			if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
				return $response;
			}

			// Resolve the WC_Order (HPOS passes the object directly; be defensive).
			$order = null;
			if ( $object instanceof WC_Order ) {
				$order = $object;
			} elseif ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
				$order = wc_get_order( $object->get_id() );
			}
			if ( ! $order ) {
				return $response;
			}

			$consignments = \Dropp\Models\Dropp_Consignment::from_order( $order );
			$payload      = self::build_payload( $consignments );
			if ( empty( $payload ) ) {
				return $response; // No booked consignment — add nothing.
			}

			$data = $response->get_data();
			if ( ! isset( $data['meta_data'] ) || ! is_array( $data['meta_data'] ) ) {
				$data['meta_data'] = array();
			}
			$data['meta_data'][] = array(
				'id'    => 0,
				'key'   => self::META_KEY,
				'value' => $payload,
			);
			$response->set_data( $data );
		} catch ( \Throwable $e ) {
			// Best-effort only: never break the orders API.
			return $response;
		}

		return $response;
	}

	/**
	 * Pure mapping: Dropp consignment objects/arrays -> StoreDash payload array.
	 *
	 * Keeps only consignments carrying a non-empty barcode OR dropp_order_id, so a
	 * "ready"/unbooked draft consignment never produces a phantom shipment.
	 *
	 * @param array $consignments Array of Dropp_Consignment (or array rows).
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_payload( array $consignments ): array {
		$out = array();
		foreach ( $consignments as $c ) {
			$status = self::nullable( self::prop( $c, 'status' ) );
			// Skip drafts / failed bookings — only booked consignments are shipments.
			if ( null !== $status && in_array( $status, self::NON_BOOKED_STATUSES, true ) ) {
				continue;
			}
			$barcode        = self::nullable( self::prop( $c, 'barcode' ) );
			$dropp_order_id = self::nullable( self::prop( $c, 'dropp_order_id' ) );
			if ( null === $barcode && null === $dropp_order_id ) {
				continue;
			}
			$out[] = array(
				'barcode'          => $barcode,
				'dropp_order_id'   => $dropp_order_id,
				'return_barcode'   => self::nullable( self::prop( $c, 'return_barcode' ) ),
				'status'           => $status,
				'shipping_item_id' => (int) self::prop( $c, 'shipping_item_id' ),
			);
		}
		return $out;
	}

	/**
	 * Read a property from an object or array, tolerating uninitialized typed props.
	 *
	 * @param mixed  $obj  Object or array.
	 * @param string $name Property/key name.
	 * @return mixed|null
	 */
	private static function prop( $obj, string $name ) {
		if ( is_array( $obj ) ) {
			return array_key_exists( $name, $obj ) ? $obj[ $name ] : null;
		}
		// isset() is false for both null and uninitialized typed properties.
		if ( is_object( $obj ) && isset( $obj->{$name} ) ) {
			return $obj->{$name};
		}
		return null;
	}

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function nullable( $value ) {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}
}

// Initialize (self-registering). Guarded so the file can be loaded by the
// standalone unit-test harness (no WordPress) to exercise build_payload().
if ( function_exists( 'add_filter' ) ) {
	new StoreDash_Dropp_Order_Meta();
}
