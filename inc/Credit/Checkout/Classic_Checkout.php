<?php
/**
 * Classic (shortcode) checkout UI for rewards credit.
 *
 * Renders a small box before the Place order button (the same theme-agnostic
 * hook the marketing opt-in uses), posts the choice to
 * `wc_ajax_storedash_credit_apply`, then triggers `update_checkout` so the
 * fee hook recalculates totals.
 *
 * @package StoreDash\Credit\Checkout
 * @since   1.17.0
 */

namespace StoreDash\Credit\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Engine\Credit_Request;
use StoreDash\Credit\Engine\Spend_Handler;
use StoreDash\Credit\Settings;

/**
 * Classic checkout box + AJAX.
 *
 * @since 1.17.0
 */
class Classic_Checkout {

	/**
	 * Spend handler (for cart state).
	 *
	 * @var Spend_Handler
	 */
	protected $spend;

	/**
	 * Constructor.
	 *
	 * @param Spend_Handler $spend Spend handler.
	 */
	public function __construct( Spend_Handler $spend ) {
		$this->spend = $spend;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_box' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wc_ajax_storedash_credit_apply', array( $this, 'ajax_apply' ) );
	}

	/**
	 * Render the credit box (hidden when disabled, logged out, or no balance).
	 */
	public function render_box(): void {
		if ( ! Settings::is_enabled() || ! is_user_logged_in() ) {
			return;
		}

		$state = $this->spend->cart_state();
		if ( $state['balance'] <= 0 ) {
			return;
		}

		$request  = Credit_Request::get();
		$currency = $state['currency'];
		$max      = $state['max_applicable'];
		?>
		<div class="storedash-credit-box" id="storedash-credit-box" data-max="<?php echo esc_attr( (string) $max ); ?>">
			<p class="form-row storedash-credit-box__balance">
				<strong><?php esc_html_e( 'Rewards credit', 'storedash' ); ?></strong>
				<span class="storedash-credit-box__amount">
					<?php
					printf(
						/* translators: %s: formatted balance */
						esc_html__( 'Available: %s', 'storedash' ),
						wp_kses_post( wc_price( $state['balance'], array( 'currency' => $currency ) ) )
					);
					?>
				</span>
				<?php if ( ! empty( $state['expiring_next'] ) ) : ?>
					<small class="storedash-credit-box__expiry">
						<?php
						printf(
							/* translators: 1: amount, 2: date */
							esc_html__( '%1$s expires %2$s', 'storedash' ),
							wp_kses_post( wc_price( $state['expiring_next']['amount'], array( 'currency' => $currency ) ) ),
							esc_html( date_i18n( get_option( 'date_format' ), strtotime( $state['expiring_next']['at'] . ' UTC' ) ) )
						);
						?>
					</small>
				<?php endif; ?>
			</p>
			<?php if ( $max > 0 ) : ?>
				<p class="form-row storedash-credit-box__toggle">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
						<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox"
							name="storedash_credit_apply" id="storedash_credit_apply" value="1" <?php checked( ! empty( $request['apply'] ) ); ?> />
						<span class="woocommerce-form__label-text"><?php esc_html_e( 'Use my rewards credit', 'storedash' ); ?></span>
					</label>
				</p>
				<p class="form-row storedash-credit-box__amount-row" <?php echo empty( $request['apply'] ) ? 'style="display:none"' : ''; ?>>
					<label for="storedash_credit_amount"><?php esc_html_e( 'Amount to use', 'storedash' ); ?></label>
					<input type="number" class="input-text" id="storedash_credit_amount" name="storedash_credit_amount"
						min="0" step="<?php echo esc_attr( (string) pow( 10, -wc_get_price_decimals() ) ); ?>" max="<?php echo esc_attr( (string) $max ); ?>"
						placeholder="<?php echo esc_attr( wc_format_localized_price( $max ) ); ?>"
						value="<?php echo esc_attr( null !== $request['amount'] ? (string) $request['amount'] : '' ); ?>" />
					<small>
						<?php
						printf(
							/* translators: %s: formatted maximum */
							esc_html__( 'Leave empty to use up to %s.', 'storedash' ),
							wp_kses_post( wc_price( $max, array( 'currency' => $currency ) ) )
						);
						?>
					</small>
				</p>
			<?php else : ?>
				<p class="form-row"><small><?php esc_html_e( 'Rewards credit cannot be used on this order.', 'storedash' ); ?></small></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue the checkout script (checkout page only).
	 */
	public function enqueue_scripts(): void {
		if ( ! Settings::is_enabled() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'storedash-credit-checkout',
			STOREDASH_URL . 'assets/js/credit-checkout.js',
			array( 'jquery' ),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-credit-checkout',
			'storedash_credit_params',
			array(
				'apply_url' => \WC_AJAX::get_endpoint( 'storedash_credit_apply' ),
				'nonce'     => wp_create_nonce( 'storedash' ),
			)
		);
	}

	/**
	 * AJAX: store the shopper's choice in the session.
	 */
	public function ajax_apply(): void {
		check_ajax_referer( 'storedash', 'nonce' );

		if ( ! Settings::is_enabled() || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to use rewards credit.', 'storedash' ) ), 403 );
		}

		$apply  = isset( $_POST['apply'] ) ? Settings::to_bool( sanitize_text_field( wp_unslash( $_POST['apply'] ) ) ) : false;
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$amount = '' === $amount ? null : (float) str_replace( ',', '.', $amount );

		Credit_Request::set( $apply, $amount );

		$state = $this->spend->cart_state( null, Credit_Request::get() );
		wp_send_json_success(
			array(
				'apply'          => $apply,
				'applied'        => $state['applied'],
				'max_applicable' => $state['max_applicable'],
				'balance'        => $state['balance'],
			)
		);
	}
}
