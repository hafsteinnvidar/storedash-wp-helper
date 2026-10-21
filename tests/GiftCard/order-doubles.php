<?php
/**
 * Order / refund / item doubles for the GiftCard hook-level suites
 * (Reservation recalc + delete, Refund_Handler status / refund flows).
 *
 * @package StoreDash\Tests\GiftCard
 */

require_once __DIR__ . '/gift-card-stubs.php';

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		return $GLOBALS['__test_post_types'][ $post_id ] ?? false;
	}
}

/**
 * Line / fee / refund item double.
 */
class GC_Test_Item {
	public $total     = 0.0;
	public $total_tax = 0.0;
	public $subtotal  = 0.0;
	public $quantity  = 1;
	public $meta      = array();

	public function __construct( $total, $quantity = 1, $meta = array(), $subtotal = null ) {
		$this->total    = (float) $total;
		$this->quantity = (int) $quantity;
		$this->meta     = $meta;
		$this->subtotal = null === $subtotal ? (float) $total : (float) $subtotal;
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $total ) {
		$this->total = (float) $total;
	}
	public function get_total_tax() {
		return $this->total_tax;
	}
	public function set_taxes( $taxes ) {
		$this->total_tax = 0.0;
	}
	public function get_subtotal() {
		return $this->subtotal;
	}
	public function get_quantity() {
		return $this->quantity;
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

/**
 * WC_Order double with line items, refunds and an optional Payment_Fee_Pass
 * replay in calculate_totals() (no WC clamp / tax split).
 */
class GC_Test_Order extends WC_Order {
	/** @var GC_Test_Item[] item id => line */
	public $lines = array();
	/** @var array WC_Order_Refund doubles, newest first */
	public $refunds = array();
	/** @var \StoreDash\Credit\Payment_Fee_Pass|null */
	public $pass = null;
	public $remaining_refund = 0.0;
	public $recalcs          = 0;

	public function get_items( $type = 'line_item' ) {
		if ( 'fee' === $type ) {
			return $this->fees;
		}
		return 'line_item' === $type ? $this->lines : array();
	}
	public function get_item( $item_id ) {
		return $this->lines[ $item_id ] ?? false;
	}
	public function get_fees() {
		return $this->fees;
	}
	public function get_refunds() {
		return $this->refunds;
	}
	public function get_remaining_refund_amount() {
		return $this->remaining_refund;
	}
	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}
	public function get_shipping_total() {
		return 0.0;
	}
	public function get_shipping_tax() {
		return 0.0;
	}
	public function set_total( $total ) {
		$this->total = (float) $total;
	}
	public function calculate_totals( $and_taxes = true ) {
		++$this->recalcs;
		if ( $this->pass ) {
			$this->pass->order_before_totals( $and_taxes, $this );
		}
		$this->total = 0.0;
		foreach ( $this->lines as $line ) {
			$this->total += $line->get_total() + $line->get_total_tax();
		}
		foreach ( $this->fees as $fee ) {
			$this->total += $fee->get_total();
		}
		if ( $this->pass ) {
			$this->pass->order_after_totals( $and_taxes, $this );
		}
		return $this->total;
	}
}

if ( ! class_exists( 'WC_Order_Refund' ) ) {
	/**
	 * Refund double (WooCommerce's does NOT extend WC_Order).
	 */
	class WC_Order_Refund { // phpcs:ignore
		public $id     = 0;
		public $amount = 0.0;
		public $items  = array();
		public $fees   = array();

		public function __construct( $id = 0, $amount = 0.0, $items = array() ) {
			$this->id     = (int) $id;
			$this->amount = (float) $amount;
			$this->items  = $items;
		}
		public function get_id() {
			return $this->id;
		}
		public function get_amount() {
			return $this->amount;
		}
		public function get_items( $types = 'line_item' ) {
			$types = (array) $types;
			$out   = in_array( 'line_item', $types, true ) ? $this->items : array();
			return in_array( 'fee', $types, true ) ? $out + $this->fees : $out;
		}
		public function get_fees() {
			return $this->fees;
		}
	}
}
