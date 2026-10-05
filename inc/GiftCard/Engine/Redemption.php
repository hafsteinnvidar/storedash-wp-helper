<?php
/**
 * Apply gift cards to the cart and turn them into fees (contract D / E).
 *
 * Applied card ids live in the WC session (`storedash_gift_cards`, apply
 * order). Each applied card becomes a negative, NON-taxable fee
 * `storedash_gift_card_{id}` on `woocommerce_cart_calculate_fees` priority 30
 * (after Rewards credit at 20) that covers min(balance, what is still payable
 * INCLUDING shipping).
 *
 * The exact cap and the "no VAT on a payment fee" rule are applied by the
 * shared Credit\Payment_Fee_Pass (also used by Rewards credit), which
 * finalises each claimed fee inside WC_Cart_Totals — see its class doc.
 *
 * @package StoreDash\GiftCard\Engine
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Payment_Fee_Pass;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Checkout\Gift_Card_Only_Checkout;
use StoreDash\GiftCard\Code;
use StoreDash\GiftCard\Product\Gift_Card_Product;
use StoreDash\GiftCard\Settings;
use WP_Error;

/**
 * Cart side of gift cards.
 *
 * @since 1.24.0
 */
class Redemption {

	/**
	 * WC session key (contract D).
	 */
	const SESSION_KEY = 'storedash_gift_cards';

	/**
	 * Fee id prefix (+ card id).
	 */
	const FEE_PREFIX = 'storedash_gift_card_';

	/**
	 * Order fee item meta carrying the card id.
	 */
	const FEE_ITEM_META = '_storedash_gift_card_id';

	/**
	 * Card store.
	 *
	 * @var Card_Ledger
	 */
	protected $ledger;

	/**
	 * Limiter.
	 *
	 * @var Rate_Limiter
	 */
	protected $limiter;

	/**
	 * Re-entrancy guard for the fee hook.
	 *
	 * @var bool
	 */
	protected $is_processing = false;

	/**
	 * Constructor.
	 *
	 * @param Card_Ledger|null  $ledger  Card store.
	 * @param Rate_Limiter|null $limiter Limiter.
	 */
	public function __construct( $ledger = null, $limiter = null ) {
		$this->ledger  = $ledger ? $ledger : new Card_Ledger();
		$this->limiter = $limiter ? $limiter : new Rate_Limiter();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		Payment_Fee_Pass::instance();
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fees' ), 30, 1 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'mark_fee_item' ), 10, 3 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_session' ) );
	}

	/**
	 * Visible fee name ("Gift card ····K9QZ").
	 *
	 * @param string $last4 Last 4 code characters.
	 * @return string
	 */
	public static function fee_name( string $last4 ): string {
		/* translators: %s: last 4 characters of the gift card code */
		return sprintf( __( 'Gift card ····%s', 'storedash' ), $last4 );
	}

	/**
	 * Card id from a fee id, 0 when not ours.
	 *
	 * @param string $fee_id Fee id.
	 * @return int
	 */
	public static function card_id_from_fee_id( string $fee_id ): int {
		if ( 0 !== strpos( $fee_id, self::FEE_PREFIX ) ) {
			return 0;
		}
		$rest = substr( $fee_id, strlen( self::FEE_PREFIX ) );
		return ctype_digit( $rest ) ? (int) $rest : 0;
	}

	// ── Session ───────────────────────────────────────────────────────────

	/**
	 * Applied card ids, in apply order.
	 *
	 * @return int[]
	 */
	public function applied_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$ids = WC()->session->get( self::SESSION_KEY );
		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) : array();
	}

	/**
	 * Store applied ids.
	 *
	 * @param int[] $ids Card ids.
	 */
	protected function set_applied_ids( array $ids ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
			WC()->session->set( self::SESSION_KEY, empty( $ids ) ? null : $ids );
		}
	}

	/**
	 * Forget applied cards (cart emptied after a successful order).
	 */
	public function clear_session(): void {
		$this->set_applied_ids( array() );
	}

	/**
	 * Apply a code.
	 *
	 * @param mixed $raw_code Shopper input.
	 * @return object|WP_Error Card row, or WP_Error with a contract-D code and HTTP status.
	 */
	public function apply_code( $raw_code ) {
		$settings = Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return $this->error( 'storedash_gift_card_disabled', __( 'Gift cards are not available right now.', 'storedash' ), 400 );
		}

		$keys = $this->limiter->current_keys();
		if ( $this->limiter->is_limited( $keys ) ) {
			return $this->error( 'storedash_gift_card_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'storedash' ), 429 );
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( Gift_Card_Product::cart_has_gift_card( $cart ) ) {
			return $this->error( 'storedash_gift_card_not_allowed', __( 'Gift cards cannot be used to buy gift cards.', 'storedash' ), 400 );
		}

		$applied = $this->applied_ids();
		$card    = $this->ledger->find_card_by_code( Code::normalize( $raw_code ) );

		if ( $card && in_array( (int) $card->id, $applied, true ) ) {
			return $card; // Already applied — no-op.
		}

		if ( count( $applied ) >= (int) $settings['max_cards_per_order'] ) {
			return $this->error(
				'storedash_gift_card_limit',
				/* translators: %d: maximum number of gift cards per order */
				sprintf( __( 'You can use at most %d gift cards per order.', 'storedash' ), (int) $settings['max_cards_per_order'] ),
				400
			);
		}

		if ( ! $card || Card_Ledger::STATUS_ACTIVE !== $card->status || (float) $card->balance <= 0 ) {
			$this->limiter->record_failure( $keys );
			if ( ! $card ) {
				Code::check_key_fingerprint();
			}
			// One generic message: never say whether the code exists.
			return $this->error( 'storedash_gift_card_invalid', __( 'This gift card code is not valid.', 'storedash' ), 400 );
		}

		$applied[] = (int) $card->id;
		$this->set_applied_ids( $applied );
		return $card;
	}

	/**
	 * Remove an applied card by id (or last 4 characters).
	 *
	 * @param mixed $ref Card id or last4.
	 * @return bool Whether something was removed.
	 */
	public function remove( $ref ): bool {
		$applied = $this->applied_ids();
		$keep    = array();
		$removed = false;

		foreach ( $applied as $card_id ) {
			$match = false;
			if ( is_numeric( $ref ) && (int) $ref === $card_id ) {
				$match = true;
			} elseif ( is_string( $ref ) && 4 === strlen( Code::normalize( $ref ) ) ) {
				$card  = $this->ledger->get_card( $card_id );
				$match = $card && Code::normalize( $ref ) === (string) $card->code_last4;
			}
			if ( $match ) {
				$removed = true;
			} else {
				$keep[] = $card_id;
			}
		}

		if ( $removed ) {
			$this->set_applied_ids( $keep );
		}
		return $removed;
	}

	// ── Fees ──────────────────────────────────────────────────────────────

	/**
	 * Add one fee per usable applied card.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function apply_fees( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( $this->is_processing ) {
			return;
		}
		$this->is_processing = true;

		try {
			$cards = $this->usable_cards( $cart );
			if ( empty( $cards ) ) {
				return;
			}

			$decimals = wc_get_price_decimals();

			// Provisional amounts from what the cart already knows (items and
			// shipping are calculated before fees). Payment_Fee_Pass makes them exact.
			$payable = Payment_Fee_Pass::base_payable(
				(float) $cart->get_cart_contents_total(),
				(float) $cart->get_cart_contents_tax(),
				(float) $cart->get_shipping_total(),
				(float) $cart->get_shipping_tax()
			);
			foreach ( $cart->fees_api()->get_fees() as $fee ) {
				$payable += (float) $fee->amount;
			}

			$balances = array();
			foreach ( $cards as $card ) {
				$balances[ (int) $card->id ] = (float) $card->balance;
			}
			$allocation = Fee_Allocator::allocate( $payable, $balances, $decimals );

			$pass = Payment_Fee_Pass::instance();
			foreach ( $cards as $card ) {
				$amount = $allocation[ (int) $card->id ] ?? 0.0;
				if ( $amount <= 0 ) {
					continue;
				}
				$fee_id  = self::FEE_PREFIX . (int) $card->id;
				$balance = (float) $card->balance;
				$pass->claim(
					$fee_id,
					static function ( float $payable ) use ( $balance, $decimals ): float {
						return Fee_Allocator::cap( $balance, $payable, $decimals );
					}
				);
				$cart->fees_api()->add_fee(
					array(
						'id'        => $fee_id,
						'name'      => self::fee_name( (string) $card->code_last4 ),
						'amount'    => -$amount,
						'taxable'   => false,
						'tax_class' => '',
					)
				);
			}
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Gift card fee failed: ' . $e->getMessage(), 'error' );
		} finally {
			$this->is_processing = false;
		}
	}

	/**
	 * Card id => amount actually applied, read from the calculated cart fees.
	 *
	 * @param \WC_Cart|null $cart Cart.
	 * @return array
	 */
	public function applied_from_cart( $cart = null ): array {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$out  = array();
		if ( ! $cart ) {
			return $out;
		}
		foreach ( $cart->get_fees() as $fee ) {
			$card_id = self::card_id_from_fee_id( (string) ( $fee->id ?? '' ) );
			if ( $card_id > 0 ) {
				$out[ $card_id ] = abs( (float) ( $fee->total ?? $fee->amount ) );
			}
		}
		return $out;
	}

	/**
	 * Full state for the Store API / checkout UIs (major-unit floats).
	 *
	 * @param \WC_Cart|null $cart Cart.
	 * @return array { enabled, max_cards, cart_has_gift_card, gift_card_only, currency, cards: [{id,last4,balance,applied}], applied_total }
	 */
	public function cart_state( $cart = null ): array {
		$settings = Settings::get();
		$cart     = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$applied  = $this->applied_from_cart( $cart );
		$cards    = array();
		$total    = 0.0;

		if ( ! empty( $settings['enabled'] ) ) {
			foreach ( $this->applied_ids() as $card_id ) {
				$card = $this->ledger->get_card( $card_id );
				if ( ! $card ) {
					continue;
				}
				$amount  = $applied[ $card_id ] ?? 0.0;
				$total  += $amount;
				$cards[] = array(
					'id'      => (int) $card->id,
					'last4'   => (string) $card->code_last4,
					'balance' => (float) $card->balance,
					'applied' => $amount,
				);
			}
		}

		return array(
			'enabled'            => ! empty( $settings['enabled'] ),
			'max_cards'          => (int) $settings['max_cards_per_order'],
			'cart_has_gift_card' => Gift_Card_Product::cart_has_gift_card( $cart ),
			'gift_card_only'     => $cart ? Gift_Card_Only_Checkout::is_gift_card_only( $cart->get_cart() ) : false,
			'currency'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'cards'              => $cards,
			'applied_total'      => $total,
		);
	}

	/**
	 * Tag our fee lines with the card id.
	 *
	 * @param \WC_Order_Item_Fee $item    Fee item.
	 * @param string             $fee_key Cart fee id.
	 * @param object             $fee     Cart fee.
	 */
	public function mark_fee_item( $item, $fee_key, $fee ): void {
		$fee_id  = isset( $fee->id ) ? (string) $fee->id : (string) $fee_key;
		$card_id = self::card_id_from_fee_id( $fee_id );
		if ( $card_id > 0 ) {
			$item->add_meta_data( self::FEE_ITEM_META, (string) $card_id, true );
		}
	}

	/**
	 * Applied cards that can still pay, dropping dead ones from the session.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return object[]
	 */
	protected function usable_cards( $cart ): array {
		$ids = $this->applied_ids();
		if ( empty( $ids ) || ! Settings::is_enabled() || Gift_Card_Product::cart_has_gift_card( $cart ) ) {
			return array();
		}

		$max   = (int) Settings::get()['max_cards_per_order'];
		$cards = array();
		$keep  = array();
		foreach ( $ids as $card_id ) {
			$card = $this->ledger->get_card( $card_id );
			if ( ! $card || Card_Ledger::STATUS_ACTIVE !== $card->status || (float) $card->balance <= 0 ) {
				continue;
			}
			$keep[] = $card_id;
			if ( count( $cards ) < $max ) {
				$cards[] = $card;
			}
		}
		if ( count( $keep ) !== count( $ids ) ) {
			$this->set_applied_ids( $keep );
		}
		return $cards;
	}

	/**
	 * WP_Error with an HTTP status.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
