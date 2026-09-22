<?php
/**
 * Earn rewards credit when an order reaches the configured status.
 *
 * Hooks `woocommerce_order_status_completed`, `woocommerce_order_status_processing`
 * and `woocommerce_payment_complete`; which one actually earns is decided at
 * runtime from `earn_on_status` so a settings change never needs a re-hook.
 *
 * Idempotent three ways: the `_storedash_credit_earned` order meta, the
 * `earn:{order_id}` idempotency key, and the enabled-at cut-off for old orders.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Credit_Webhook;
use StoreDash\Credit\Ledger;
use StoreDash\Credit\Rules_Repository;
use StoreDash\Credit\Settings;

/**
 * Earn flow.
 *
 * @since 1.17.0
 */
class Earn_Handler {

	/**
	 * Order meta keys.
	 */
	const META_EARNED    = '_storedash_credit_earned';
	const META_EARNED_ID = '_storedash_credit_earned_ledger_id';

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Rules.
	 *
	 * @var Rules_Repository
	 */
	protected $rules;

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	protected $eligibility;

	/**
	 * Constructor.
	 *
	 * @param Ledger|null           $ledger      Ledger.
	 * @param Rules_Repository|null $rules       Rules.
	 * @param Eligibility|null      $eligibility Eligibility.
	 */
	public function __construct( $ledger = null, $rules = null, $eligibility = null ) {
		$this->ledger      = $ledger ? $ledger : new Ledger();
		$this->rules       = $rules ? $rules : new Rules_Repository();
		$this->eligibility = $eligibility ? $eligibility : new Eligibility();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_status' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );
	}

	/**
	 * Status transition handler.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_status( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$settings = Settings::get();
		if ( $order->get_status() !== $settings['earn_on_status'] && ! ( 'completed' === $order->get_status() && 'processing' === $settings['earn_on_status'] ) ) {
			return;
		}
		$this->maybe_earn( $order, $settings );
	}

	/**
	 * Payment-complete handler (only relevant when earning on `processing`).
	 *
	 * @param int $order_id Order id.
	 */
	public function on_payment_complete( $order_id ): void {
		$settings = Settings::get();
		if ( 'processing' !== $settings['earn_on_status'] ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->maybe_earn( $order, $settings );
		}
	}

	/**
	 * Earn credit for an order if everything lines up.
	 *
	 * @param \WC_Order $order    Order.
	 * @param array     $settings Normalized settings.
	 * @return array|null Ledger result or null when nothing was earned.
	 */
	public function maybe_earn( \WC_Order $order, array $settings ) {
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}
		if ( $order instanceof \WC_Order_Refund ) {
			return null;
		}
		if ( '' !== (string) $order->get_meta( self::META_EARNED, true ) ) {
			return null;
		}

		$enabled_at = Settings::enabled_at();
		$created    = $order->get_date_created();
		if ( '' === $enabled_at || ! $created || $created->getTimestamp() < strtotime( $enabled_at . ' UTC' ) ) {
			return null;
		}

		$customer_key = Customer_Resolver::key_for_order( $order );
		if ( '' === $customer_key ) {
			return null;
		}

		$rules = $this->rules->get_enabled();
		if ( empty( $rules ) ) {
			return null;
		}

		$context = $this->build_context( $order );
		$rule    = Rule_Matcher::pick( $rules, $context );
		if ( ! $rule ) {
			return null;
		}

		$decimals = wc_get_price_decimals();
		$basis    = Earn_Calculator::basis(
			$context['lines'],
			$settings,
			(float) $order->get_meta( Spend_Handler::META_APPLIED, true ),
			$context['extras']
		);
		$amount   = Earn_Calculator::amount( $rule, $basis, $settings, $decimals );
		if ( $amount <= 0 ) {
			return null;
		}

		$expires_at = Earn_Calculator::expires_at( $rule, $settings, time() );

		$result = $this->ledger->add_credit(
			array(
				'customer_key' => $customer_key,
				'user_id'      => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
				'order_id'     => $order->get_id(),
				'rule_id'      => $rule['supabase_id'],
				'amount'       => $amount,
				'expires_at'   => $expires_at,
				'idem_key'     => 'earn:' . $order->get_id(),
				'type'         => Ledger::TYPE_EARN,
			)
		);

		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Rewards credit earn failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return null;
		}

		$row = $result['row'];
		$order->update_meta_data( self::META_EARNED, wc_format_decimal( $row->amount, $decimals ) );
		$order->update_meta_data( self::META_EARNED_ID, (int) $row->id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: credit amount, 2: rule name, 3: expiry text */
				__( 'Earned %1$s rewards credit (%2$s)%3$s.', 'storedash' ),
				wp_strip_all_tags( wc_price( (float) $row->amount, array( 'currency' => $order->get_currency() ) ) ),
				$rule['name'],
				$expires_at ? sprintf(
					/* translators: %s: expiry date */
					__( ', expires %s', 'storedash' ),
					date_i18n( get_option( 'date_format' ), strtotime( $expires_at . ' UTC' ) )
				) : ''
			)
		);
		$order->save();

		if ( ! empty( $result['created'] ) ) {
			Credit_Webhook::send( 'earned', $row );
		}

		return $result;
	}

	/**
	 * Build the rule-matcher context + calculator lines for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	protected function build_context( \WC_Order $order ): array {
		$settings   = Settings::get();
		$lines      = array();
		$categories = array();
		$brands     = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$lines[] = array(
				'total'    => (float) $item->get_total(),
				'tax'      => (float) $item->get_total_tax(),
				'excluded' => ! $product || $this->eligibility->product_excluded( $product, $settings ),
				'on_sale'  => $product ? $this->eligibility->is_on_sale( $product ) : false,
			);
			if ( $product ) {
				$pid        = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
				$categories = array_merge( $categories, $this->eligibility->get_product_terms( $pid, 'product_cat' ) );
				$brands     = array_merge( $brands, $this->eligibility->get_product_terms( $pid, 'product_brand' ) );
			}
		}

		// Ancestors too, so a rule targeting a parent category matches products
		// filed under a child (same semantics as exclusions).
		$categories = $this->with_ancestors( array_unique( $categories ), 'product_cat' );
		$brands     = $this->with_ancestors( array_unique( $brands ), 'product_brand' );

		$extras = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fee_total = (float) $fee->get_total();
			if ( $fee_total > 0 ) {
				$extras += $fee_total + (float) $fee->get_total_tax();
			}
		}

		$roles = array();
		if ( $order->get_customer_id() ) {
			$user = get_user_by( 'id', $order->get_customer_id() );
			if ( $user ) {
				$roles = (array) $user->roles;
			}
		}

		$email = $order->get_billing_email();

		return array(
			'lines'          => $lines,
			'extras'         => $extras,
			'order_total'    => (float) $order->get_total(),
			'roles'          => $roles,
			'category_ids'   => $categories,
			'brand_ids'      => $brands,
			'now'            => time(),
			'is_first_order' => static function () use ( $email, $order ) {
				if ( '' === (string) $email ) {
					return false;
				}
				$statuses = array_unique( array_merge( wc_get_is_paid_statuses(), array( 'completed', 'on-hold' ) ) );
				$others   = wc_get_orders(
					array(
						'customer' => $email,
						'status'   => $statuses,
						'exclude'  => array( $order->get_id() ),
						'limit'    => 1,
						'return'   => 'ids',
					)
				);
				return empty( $others );
			},
		);
	}

	/**
	 * Add ancestor term ids for hierarchical taxonomies.
	 *
	 * @param int[]  $term_ids Term ids.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	protected function with_ancestors( array $term_ids, string $taxonomy ): array {
		$out = array_map( 'intval', $term_ids );
		if ( ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return array_values( array_unique( $out ) );
		}
		foreach ( $term_ids as $term_id ) {
			$ancestors = get_ancestors( (int) $term_id, $taxonomy, 'taxonomy' );
			if ( is_array( $ancestors ) ) {
				$out = array_merge( $out, array_map( 'intval', $ancestors ) );
			}
		}
		return array_values( array_unique( $out ) );
	}
}
