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

	/**
	 * Amounts applied in the last pass (fee id => positive amount).
	 *
	 * @return array
	 */
	public function applied(): array {
		return $this->applied;
	}
}
