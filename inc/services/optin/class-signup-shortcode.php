<?php
/**
 * Signup Shortcode
 *
 * `[storedash_signup]` — the newsletter signup form for stores without
 * Elementor (or anywhere a widget can't go: block themes, Divi, Bricks, a
 * classic text widget). Posts to the same `storedash_optin_submit` action as
 * the Elementor widget and binds to the same `assets/js/signup-widget.js`.
 *
 * The Elementor widget deliberately does NOT route through this markup. It
 * carries its own style controls and merchants have live sites depending on
 * that styling; the two paths share the stylesheet and the JS, not the PHP.
 *
 * Styling follows the theme. The wrapper carries
 * `.storedash-signup-widget--tokens`, which opts out of the widget's classic
 * colours, and the button gets the theme's own `button wp-element-button`
 * classes. Optional colour attributes arrive as CSS custom properties on the
 * wrapper (same pattern as the waitlist/enquiry renderers):
 *
 *   [storedash_signup heading="Join us" button="Sign up" accent="#0a7d4f"]
 *
 * @package StoreDash\Services\Optin
 * @since   1.28.0
 */

namespace StoreDash\Services\Optin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signup Shortcode Class
 */
class Signup_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'storedash_signup';

	/**
	 * Colour attributes → CSS custom properties consumed by signup-widget.css.
	 */
	const TOKEN_MAP = array(
		'accent'      => '--sd-su-accent',
		'accent_text' => '--sd-su-accent-text',
		'text'        => '--sd-su-text',
		'background'  => '--sd-su-bg',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Registered ahead of Widgets_Manager (priority 10) so the handles
		// exist even when Elementor is not installed — Widgets_Manager only
		// loads when `elementor/loaded` has fired. Re-registering an existing
		// handle is a no-op, so the two paths coexist safely.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register (not enqueue) the shared signup assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		if ( ! wp_style_is( 'storedash-signup-widget', 'registered' ) ) {
			wp_register_style(
				'storedash-signup-widget',
				STOREDASH_URL . 'assets/css/signup-widget.css',
				array(),
				STOREDASH_VERSION
			);
		}

		if ( ! wp_script_is( 'storedash-signup-widget', 'registered' ) ) {
			wp_register_script(
				'storedash-signup-widget',
				STOREDASH_URL . 'assets/js/signup-widget.js',
				array(),
				STOREDASH_VERSION,
				true
			);

			wp_localize_script(
				'storedash-signup-widget',
				'storedashSignup',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'storedash_optin_submit' ),
					'strings'  => array(
						'error_general' => __( 'An error occurred. Please try again.', 'storedash' ),
						'error_email'   => __( 'Please enter a valid email address.', 'storedash' ),
						'submitting'    => __( 'Subscribing...', 'storedash' ),
						'success'       => __( 'Thanks for subscribing!', 'storedash' ),
					),
				)
			);
		}
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			self::defaults(),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		// Shortcodes run inside the_content, after wp_enqueue_scripts — the
		// script and style still land, in the footer.
		wp_enqueue_style( 'storedash-signup-widget' );
		wp_enqueue_script( 'storedash-signup-widget' );

		return self::render( $atts );
	}

	/**
	 * Default attributes. Copy matches the Elementor widget's defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'heading'     => __( 'Subscribe for news and offers', 'storedash' ),
			'description' => __( 'Be the first to hear about new products, sales, and special offers.', 'storedash' ),
			'placeholder' => __( 'Your email address', 'storedash' ),
			'button'      => __( 'Subscribe', 'storedash' ),
			'submitting'  => __( 'Subscribing...', 'storedash' ),
			'success'     => __( 'Thanks for subscribing!', 'storedash' ),
			'privacy'     => __( 'We respect your privacy. Unsubscribe at any time.', 'storedash' ),
			'accent'      => '',
			'accent_text' => '',
			'text'        => '',
			'background'  => '',
		);
	}

	/**
	 * Render the form.
	 *
	 * @param array $atts Attributes, already merged with defaults().
	 * @return string
	 */
	public static function render( array $atts ): string {
		$style   = self::token_style( $atts );
		$classes = array( 'storedash-signup-widget', 'storedash-signup-widget--tokens' );

		// Only paint the button when asked; otherwise the theme's own button
		// styles (via the classes below) decide how it looks.
		if ( '' !== (string) sanitize_hex_color( (string) $atts['accent'] ) ) {
			$classes[] = 'storedash-signup-widget--accent';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo '' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
			<form class="storedash-signup-form"
				data-success-message="<?php echo esc_attr( $atts['success'] ); ?>"
				data-submitting-text="<?php echo esc_attr( $atts['submitting'] ); ?>">
				<?php wp_nonce_field( 'storedash_optin_submit', 'storedash_optin_nonce', false ); ?>

				<?php if ( '' !== (string) $atts['heading'] ) : ?>
					<h3 class="storedash-signup-title"><?php echo esc_html( $atts['heading'] ); ?></h3>
				<?php endif; ?>

				<?php if ( '' !== (string) $atts['description'] ) : ?>
					<p class="storedash-signup-description"><?php echo esc_html( $atts['description'] ); ?></p>
				<?php endif; ?>

				<div class="storedash-signup-row">
					<input type="email" name="email" class="storedash-signup-input"
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
						aria-label="<?php echo esc_attr( $atts['placeholder'] ); ?>" required />
					<button type="submit" class="storedash-signup-submit button wp-element-button">
						<?php echo esc_html( $atts['button'] ); ?>
					</button>
				</div>

				<div style="display:none !important;">
					<input type="text" name="website" tabindex="-1" autocomplete="off" />
				</div>

				<input type="hidden" name="_ts" value="" />

				<?php if ( '' !== (string) $atts['privacy'] ) : ?>
					<p class="storedash-signup-privacy"><?php echo esc_html( $atts['privacy'] ); ?></p>
				<?php endif; ?>

				<div class="storedash-signup-message" style="display:none;" role="status" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the custom-property declarations for the wrapper's inline style.
	 *
	 * Hex colours only: anything else is dropped, so an attribute can never
	 * smuggle extra declarations into the style attribute.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function token_style( array $atts ): string {
		$declarations = array();
		foreach ( self::TOKEN_MAP as $attr => $property ) {
			$value = sanitize_hex_color( trim( (string) ( $atts[ $attr ] ?? '' ) ) );
			if ( $value ) {
				$declarations[] = $property . ':' . $value;
			}
		}

		return implode( ';', $declarations );
	}
}

// Initialize
new Signup_Shortcode();
