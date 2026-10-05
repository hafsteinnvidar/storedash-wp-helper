<?php
/**
 * Exact, untaxed negative "payment" fees (Rewards credit + gift cards).
 *
 * Storedash pays part of an order with negative cart fees: Rewards credit
 * (`storedash_credit`, priority 20) and gift cards (`storedash_gift_card_{id}`,
 * priority 30). Both are payments, not discounts: the order's VAT must stay on
 * the full goods value and the fee must take off exactly what the ledger
 * consumes. Two WC_Cart_Totals::get_fees_from_cart() behaviours break that:
 *
 * 1. Every NEGATIVE fee gets taxes split onto it when taxes are on, whatever
 *    its `taxable` flag — a 1.000 kr credit would take 1.000 × (1 + VAT) off.
 * 2. Negative fees are clamped at items + shipping EX tax, so with
 *    tax-inclusive prices a fee can never cover the VAT part of the total.
 *
 * Both are fixed in `woocommerce_cart_totals_get_fees_from_cart_taxes`, which
 * WooCommerce calls per fee AFTER the clamp and BEFORE the fee is stored. For a
 * claimed fee it returns no taxes and resets the fee total to the claim's cap,
 * given what is still payable at that point: gross items + gross shipping
 * (WooCommerce calculates shipping BEFORE fees since 3.2, so it is known on the
 * very first render) + every fee finalised before it, taxes included.
 *
 * The ORDER side has the same two behaviours: WC_Order::calculate_totals()
 * (Store API update_order_from_cart(), admin "Recalculate") clamps negative
 * fees at the ex-tax total and WC_Order_Item_Fee::calculate_taxes() splits VAT
 * onto them. For our fee lines (meta `_storedash_credit_fee` /
 * `_storedash_gift_card_id`) taxes are cleared, the pre-clamp amount restored
 * and the order total recomputed, so order VAT == the same order paid without
 * them and the fee line == what the ledger consumed.
 *
 * Shared by both modules — one pass, one filter, claims in fee order.
 *
 * @package StoreDash\Credit
 * @since   1.24.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-totals-pass fee finaliser.
 *
 * @since 1.24.0
 */
class Payment_Fee_Pass {

	/**
	 * Shared instance.
	 *
	 * @var Payment_Fee_Pass|null
	 */
	protected static $instance = null;

	/**
	 * Whether the hooks are registered.
	 *
	 * @var bool
	 */
	protected $registered = false;

	/**
	 * Cart of the current pass (null = no pass running).
	 *
	 * @var object|null
	 */
	protected $cart = null;

	/**
	 * Claimed fee id => callable( float $payable ): float applied.
	 *
	 * @var array
	 */
	protected $claims = array();

	/**
	 * Running total (major units) of every fee finalised in this pass.
	 *
	 * @var float
	 */
	protected $fees = 0.0;

	/**
	 * Order fee totals captured before WC_Order::calculate_totals() clamps them:
	 * spl_object_hash(item) => total.
	 *
	 * @var array
	 */
	protected $order_fee_totals = array();

	/**
	 * Fee id => amount actually applied (positive) in the last pass.
	 *
	 * @var array
	 */
	protected $applied = array();

	/**
	 * Shared instance (registers its hooks once).
	 *
	 * @return Payment_Fee_Pass
	 */
	public static function instance(): Payment_Fee_Pass {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::$instance->register();
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( $this->registered || ! function_exists( 'add_action' ) ) {
			return;
		}
		$this->registered = true;
		// Before every fee producer: start a fresh pass.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'begin' ), -1000, 1 );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( $this, 'finalize' ), PHP_INT_MAX, 2 );

		// Order side.
		add_action( 'woocommerce_order_before_calculate_totals', array( $this, 'order_before_totals' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_order_item_fee_after_calculate_taxes', array( $this, 'order_fee_untaxed' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'order_after_totals' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Start a totals pass.
	 *
	 * @param object $cart WC_Cart (exposes cart contents / shipping getters).
	 */
	public function begin( $cart ): void {
		$this->cart    = $cart;
		$this->claims  = array();
		$this->fees    = 0.0;
		$this->applied = array();
	}

	/**
	 * Claim a fee added in this pass.
	 *
	 * @param string   $fee_id Cart fee id.
	 * @param callable $cap    function( float $payable ): float — amount to apply (positive)
	 *                         given what is still payable before this fee.
	 */
	public function claim( string $fee_id, callable $cap ): void {
		$this->claims[ $fee_id ] = $cap;
	}

	/**
	 * Gross amount payable before any fee.
	 *
	 * Pure function — unit tested.
	 *
	 * @param float $items_total    Items total after discounts, ex tax.
	 * @param float $items_tax      Items tax.
	 * @param float $shipping_total Shipping ex tax.
	 * @param float $shipping_tax   Shipping tax.
	 * @return float
	 */
	public static function base_payable( float $items_total, float $items_tax, float $shipping_total, float $shipping_tax ): float {
		return max( 0.0, $items_total + $items_tax + $shipping_total + $shipping_tax );
	}

	/**
	 * Gross shipping (cost + tax) of a cart. Known inside the fee hook: WC
	 * calculates shipping before fees.
	 *
	 * @param object $cart WC_Cart.
	 * @return float
	 */
	public static function shipping_gross( $cart ): float {
		if ( ! $cart || ! method_exists( $cart, 'get_shipping_total' ) ) {
			return 0.0;
		}
		return max( 0.0, (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() );
	}

	/**
	 * Per-fee finaliser (see class doc).
	 *
	 * @param array  $taxes Fee taxes (cents).
	 * @param object $fee   WC_Cart_Totals fee props (total in cents, object = cart fee).
	 * @return array
	 */
	public function finalize( $taxes, $fee ) {
		if ( null === $this->cart || ! is_object( $fee ) || ! isset( $fee->object ) ) {
			return $taxes;
		}

		$fee_id = isset( $fee->object->id ) ? (string) $fee->object->id : '';
		if ( ! isset( $this->claims[ $fee_id ] ) ) {
			// Someone else's fee: remember what it does to the total.
			$this->fees += wc_remove_number_precision( (float) $fee->total + array_sum( array_map( 'floatval', (array) $taxes ) ) );
			return $taxes;
		}

		$cart    = $this->cart;
		$payable = self::base_payable(
			(float) $cart->get_cart_contents_total(),
			(float) $cart->get_cart_contents_tax(),
			(float) $cart->get_shipping_total(),
			(float) $cart->get_shipping_tax()
		) + $this->fees;

		$applied = max( 0.0, min( max( 0.0, $payable ), (float) call_user_func( $this->claims[ $fee_id ], max( 0.0, $payable ) ) ) );

		$fee->total               = wc_add_number_precision( -$applied );
		$fee->object->amount      = -$applied;
		$this->fees              -= $applied;
		$this->applied[ $fee_id ] = $applied;

		return array();
	}

	// ── Order side ───────────────────────────────────────────────────────

	/**
	 * Whether an order fee line is one of ours (Rewards credit or gift card).
	 *
	 * The meta is added in `woocommerce_checkout_create_order_fee_item`, i.e.
	 * before the line is added to the order, so it is there for the first
	 * calculate_totals(). The legacy cart fee key is a fallback.
	 *
	 * @param object $item WC_Order_Item_Fee.
	 * @return bool
	 */
	public static function is_payment_fee_item( $item ): bool {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return false;
		}
		if ( '1' === (string) $item->get_meta( '_storedash_credit_fee', true ) || (int) $item->get_meta( '_storedash_gift_card_id', true ) > 0 ) {
			return true;
		}
		$key = isset( $item->legacy_fee_key ) ? (string) $item->legacy_fee_key : '';
		return 'storedash_credit' === $key || 0 === strpos( $key, 'storedash_gift_card_' );
	}

	/**
	 * Remember our fee totals before WooCommerce clamps them.
	 *
	 * @param bool   $and_taxes Whether taxes are recalculated.
	 * @param object $order     WC_Order.
	 */
	public function order_before_totals( $and_taxes, $order ): void {
		$this->order_fee_totals = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_fees' ) ) {
			return;
		}
		foreach ( $order->get_fees() as $item ) {
			if ( self::is_payment_fee_item( $item ) ) {
				$this->order_fee_totals[ spl_object_hash( $item ) ] = (float) $item->get_total();
			}
		}
	}

	/**
	 * No VAT split onto our negative fee lines.
	 *
	 * @param object $item WC_Order_Item_Fee.
	 */
	public function order_fee_untaxed( $item ): void {
		if ( self::is_payment_fee_item( $item ) && (float) $item->get_total() < 0 ) {
			$item->set_taxes( false );
		}
	}

	/**
	 * Restore pre-clamp amounts and recompute the order total.
	 *
	 * @param bool   $and_taxes Whether taxes were recalculated.
	 * @param object $order     WC_Order.
	 */
	public function order_after_totals( $and_taxes, $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_fees' ) ) {
			return;
		}

		$ours   = array();
		$others = 0.0;
		foreach ( $order->get_fees() as $key => $item ) {
			if ( ! self::is_payment_fee_item( $item ) ) {
				$others += (float) $item->get_total() + (float) $item->get_total_tax();
				continue;
			}
			$hash         = spl_object_hash( $item );
			$ours[ $key ] = array(
				'item'  => $item,
				'total' => array_key_exists( $hash, $this->order_fee_totals ) ? $this->order_fee_totals[ $hash ] : (float) $item->get_total(),
			);
		}
		$this->order_fee_totals = array();
		if ( empty( $ours ) ) {
			return;
		}

		$goods = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$goods += (float) $line->get_total() + (float) $line->get_total_tax();
		}

		$decimals = wc_get_price_decimals();
		$result   = self::order_totals(
			$goods,
			(float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
			$others,
			array_map(
				static function ( $entry ) {
					return $entry['total'];
				},
				$ours
			),
			$decimals
		);

		$retaxed = false;
		foreach ( $ours as $key => $entry ) {
			$total = $result['fees'][ $key ];
			if ( abs( (float) $entry['item']->get_total_tax() ) > 0.000001 ) {
				$entry['item']->set_taxes( false );
				$retaxed = true;
			}
			if ( abs( (float) $entry['item']->get_total() - $total ) > 0.000001 ) {
				$entry['item']->set_total( $total );
			}
		}
		if ( $retaxed && method_exists( $order, 'update_taxes' ) ) {
			$order->update_taxes(); // Order tax lines must not keep the removed fee VAT.
		}
		$order->set_total( $result['total'] );
	}

	/**
	 * Order total with our fees at their own amounts (no VAT split), never below 0.
	 *
	 * Pure function — unit tested. When the fees would exceed the order (e.g.
	 * items were removed in admin), the LAST of our fees shrink first.
	 *
	 * @param float $goods_gross    Line items after discounts incl. tax.
	 * @param float $shipping_gross Shipping incl. tax.
	 * @param float $other_fees     Other fee lines incl. tax.
	 * @param array $fees           key => our fee total (negative), in order.
	 * @param int   $decimals       Price decimals.
	 * @return array { fees: key => total, total: float }
	 */
	public static function order_totals( float $goods_gross, float $shipping_gross, float $other_fees, array $fees, int $decimals ): array {
		$payable = round( $goods_gross + $shipping_gross + $other_fees, $decimals );
		$out     = array();
		$ours    = 0.0;
		foreach ( $fees as $key => $total ) {
			$out[ $key ] = round( min( 0.0, (float) $total ), $decimals );
			$ours       += $out[ $key ];
		}

		$over = round( -( $payable + $ours ), $decimals );
		foreach ( array_reverse( array_keys( $out ) ) as $key ) {
			if ( $over <= 0 ) {
				break;
			}
			$shrink      = min( $over, -$out[ $key ] );
			$out[ $key ] = round( $out[ $key ] + $shrink, $decimals );
			$over        = round( $over - $shrink, $decimals );
		}

		return array(
			'fees'  => $out,
			'total' => max( 0.0, round( $payable + array_sum( $out ), $decimals ) ),
		);
	}

	/**
	 * Amounts applied in the last pass (fee id => positive amount).
	 *
	 * @return array
	 */
	public function applied(): array {
		return $this->applied;
	}
}
