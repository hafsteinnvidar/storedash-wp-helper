<?php
/**
 * Classic (shortcode) checkout UI for gift cards.
 *
 * Renders a code box before the Place order button (same hook as the Rewards
 * credit box, so it is re-rendered with the order review on every
 * update_checkout), posts to `wc_ajax_storedash_gift_card_apply` /
 * `…_remove`, then triggers `update_checkout` so the fee hook recalculates.
 *
 * @package StoreDash\GiftCard\Checkout
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Engine\Redemption;
use StoreDash\GiftCard\Settings;

/**
 * Classic checkout box + AJAX.
 *
 * @since 1.24.0
 */
class Classic_Checkout {

	/**
	 * Redemption.
	 *
	 * @var Redemption
	 */
	protected $redemption;

	/**
	 * Constructor.
	 *
	 * @param Redemption $redemption Redemption.
	 */
	public function __construct( Redemption $redemption ) {
		$this->redemption = $redemption;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_box' ), 16 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wc_ajax_storedash_gift_card_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wc_ajax_storedash_gift_card_remove', array( $this, 'ajax_remove' ) );
	}

	/**
	 * Render the gift card box.
	 */
	public function render_box(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		$state = $this->redemption->cart_state();
		if ( $state['cart_has_gift_card'] && empty( $state['cards'] ) ) {
			return;
		}
		$currency = $state['currency'];
		?>
		<div class="storedash-gift-card-box" id="storedash-gift-card-box">
			<p class="form-row storedash-gift-card-box__title"><strong><?php esc_html_e( 'Gift card', 'storedash' ); ?></strong></p>
			<?php foreach ( $state['cards'] as $card ) : ?>
				<p class="form-row storedash-gift-card-box__card">
					<?php
					printf(
						/* translators: 1: last 4 characters of the code, 2: amount applied, 3: card balance */
						esc_html__( '····%1$s: %2$s applied (balance %3$s)', 'storedash' ),
						esc_html( $card['last4'] ),
						wp_kses_post( wc_price( $card['applied'], array( 'currency' => $currency ) ) ),
						wp_kses_post( wc_price( $card['balance'], array( 'currency' => $currency ) ) )
					);
					?>
					<a href="#" class="storedash-gift-card-box__remove" data-card="<?php echo esc_attr( (string) $card['id'] ); ?>"><?php esc_html_e( 'Remove', 'storedash' ); ?></a>
				</p>
			<?php endforeach; ?>
			<?php if ( $state['cart_has_gift_card'] ) : ?>
				<p class="form-row"><small><?php esc_html_e( 'Gift cards cannot be used to buy gift cards.', 'storedash' ); ?></small></p>
			<?php elseif ( count( $state['cards'] ) < $state['max_cards'] ) : ?>
				<p class="form-row storedash-gift-card-box__apply">
					<label for="storedash_gift_card_code" class="screen-reader-text"><?php esc_html_e( 'Gift card code', 'storedash' ); ?></label>
					<input type="text" class="input-text" id="storedash_gift_card_code" autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'Gift card code', 'storedash' ); ?>" />
					<button type="button" class="button storedash-gift-card-box__button"><?php esc_html_e( 'Apply', 'storedash' ); ?></button>
				</p>
				<p class="storedash-gift-card-box__error woocommerce-error" role="alert" style="display:none"></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue the checkout script (checkout page only).
	 */
	public function enqueue_scripts(): void {
		if ( ! Settings::is_enabled() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'storedash-gift-card-checkout',
			STOREDASH_URL . 'assets/js/gift-card-checkout.js',
			array( 'jquery' ),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-gift-card-checkout',
			'storedash_gift_card_params',
			array(
				'apply_url'  => \WC_AJAX::get_endpoint( 'storedash_gift_card_apply' ),
				'remove_url' => \WC_AJAX::get_endpoint( 'storedash_gift_card_remove' ),
				'nonce'      => wp_create_nonce( 'storedash_gift_card' ),
				'error'      => __( 'This gift card code is not valid.', 'storedash' ),
			)
		);
	}

	/**
	 * AJAX: apply a code.
	 */
	public function ajax_apply(): void {
		check_ajax_referer( 'storedash_gift_card', 'nonce' );

		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$result = $this->redemption->apply_code( $code );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400
			);
		}

		wp_send_json_success( array( 'applied' => true ) );
	}

	/**
	 * AJAX: remove a card.
	 */
	public function ajax_remove(): void {
		check_ajax_referer( 'storedash_gift_card', 'nonce' );

		$card_id = isset( $_POST['card'] ) ? absint( wp_unslash( $_POST['card'] ) ) : 0;
		$this->redemption->remove( $card_id );

		wp_send_json_success( array( 'removed' => true ) );
	}
}
