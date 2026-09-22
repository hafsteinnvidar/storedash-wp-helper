<?php
/**
 * Disable Posturinn Automatic Shipment Creation — for StoreDash-originated changes only.
 *
 * Posturinn auto-creates a shipment when an order reaches its configured
 * pdf_auto_generate_status. It only skips orders carrying its own
 * postis_shipment_meta, so shipments created in StoreDash (which never writes
 * that meta) would be duplicated when StoreDash syncs the status change back
 * to WooCommerce.
 *
 * We therefore strip Posturinn's status-change hooks ONLY on requests that
 * originate from StoreDash (identified by the X-StoreDash-Source header, sent
 * by both the woo-dash app and the sync service on every request). Status
 * changes made in wp-admin, at checkout, or by payment gateways keep
 * Posturinn's automatic shipment creation exactly as the merchant configured
 * it in the Posturinn plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Disable_Posturinn_Auto {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook early to remove Posturinn's automatic shipment creation
		add_action( 'init', array( $this, 'disable_automatic_shipments' ), 1 );

		// Also try to disable it on plugins_loaded in case Posturinn loads late
		add_action( 'plugins_loaded', array( $this, 'disable_automatic_shipments' ), 999 );

		// And once more after WooCommerce loads
		add_action( 'woocommerce_loaded', array( $this, 'disable_automatic_shipments' ), 1 );
	}

	/**
	 * Whether the current request originates from StoreDash (app or sync service).
	 *
	 * Both senders attach `X-StoreDash-Source: true` to every WooCommerce
	 * request (woo-dash: lib/api/woocommerce/core/request.ts; sync service:
	 * resty client default header in woo_client.go). Header presence is enough
	 * here — worst case a spoofed header suppresses the merchant's own label
	 * auto-creation for that one request, which is harmless.
	 *
	 * @var bool|null Cached per-request; null until first checked.
	 */
	private $is_storedash_request = null;

	/**
	 * Check whether the current request carries the StoreDash source header.
	 */
	private function is_storedash_request() {
		if ( null !== $this->is_storedash_request ) {
			return $this->is_storedash_request;
		}

		$detected = '' !== StoreDash_Helpers::get_server_var( 'HTTP_X_STOREDASH_SOURCE' );

		// Some server setups (e.g. FastCGI without header passthrough) drop
		// custom headers from $_SERVER; fall back to getallheaders().
		if ( ! $detected && function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'X-StoreDash-Source' ) && '' !== $value ) {
					$detected = true;
					break;
				}
			}
		}

		$this->is_storedash_request = $detected;
		return $detected;
	}

	/**
	 * Disable automatic shipment creation from Posturinn plugin —
	 * only when the change is being made by StoreDash.
	 */
	public function disable_automatic_shipments() {
		// Changes made in wp-admin / checkout / by gateways keep Posturinn's
		// own auto-create behavior; only StoreDash-originated writes are muted.
		if ( ! $this->is_storedash_request() ) {
			return;
		}

		// Check if Posturinn plugin is active
		if ( class_exists( 'POSTIS_Admin' ) ) {
			$postis_admin = POSTIS_Admin::get_instance();

			// Remove the hook that automatically creates shipments on status change
			remove_action( 'woocommerce_order_status_changed', array( $postis_admin, 'generate_shipment_pdf_on_status_change' ), 10 );
		}

		// Remove all Posturinn-specific hooks
		$this->remove_posturinn_hooks();
	}

	/**
	 * Remove only Posturinn-specific hooks from WooCommerce actions
	 */
	private function remove_posturinn_hooks() {
		global $wp_filter;

		// List of hooks to check
		$hook_names = array(
			'woocommerce_order_status_completed',
			'woocommerce_order_status_processing',
			'woocommerce_order_status_changed',
		);

		// Also check status transition hooks
		$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		foreach ( $statuses as $from_status ) {
			foreach ( $statuses as $to_status ) {
				$hook_names[] = "woocommerce_order_status_{$from_status}_to_{$to_status}";
			}
		}

		// Go through each hook and remove only Posturinn callbacks
		foreach ( $hook_names as $hook_name ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				continue;
			}

			// Get all callbacks for this hook
			$callbacks = $wp_filter[ $hook_name ]->callbacks;

			foreach ( $callbacks as $priority => $priority_callbacks ) {
				foreach ( $priority_callbacks as $idx => $callback_array ) {
					$function_name = $this->get_callback_name( $callback_array['function'] );

					// Check if this is a Posturinn/Postis callback
					if ( $this->is_posturinn_callback( $function_name, $callback_array['function'] ) ) {
						// Remove only this specific callback
						remove_filter( $hook_name, $callback_array['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Check if a callback belongs to Posturinn/Postis plugin
	 */
	private function is_posturinn_callback( $function_name, $callback ) {
		// Check by function name
		$posturinn_keywords = array( 'postis', 'posturinn', 'POSTIS', 'Posturinn' );
		foreach ( $posturinn_keywords as $keyword ) {
			if ( stripos( $function_name, $keyword ) !== false ) {
				return true;
			}
		}

		// Check by class name if it's an object method
		if ( is_array( $callback ) && is_object( $callback[0] ) ) {
			$class_name = get_class( $callback[0] );
			foreach ( $posturinn_keywords as $keyword ) {
				if ( stripos( $class_name, $keyword ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get human-readable callback name
	 */
	private function get_callback_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		} elseif ( is_array( $callback ) ) {
			if ( is_object( $callback[0] ) ) {
				return get_class( $callback[0] ) . '::' . $callback[1];
			} else {
				return $callback[0] . '::' . $callback[1];
			}
		} elseif ( is_object( $callback ) && is_a( $callback, 'Closure' ) ) {
			return 'Closure';
		}
		return 'Unknown';
	}
}

// Initialize the disabler
new StoreDash_Disable_Posturinn_Auto();

// Constant other code can check. Since the conditional rework this means
// "Posturinn auto-shipments are suppressed for StoreDash-originated requests",
// not a blanket disable.
if ( ! defined( 'WOODASH_DISABLE_POSTURINN_AUTO' ) ) {
	define( 'WOODASH_DISABLE_POSTURINN_AUTO', true );
}
