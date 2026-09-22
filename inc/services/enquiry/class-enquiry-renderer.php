<?php
/**
 * Enquiry Form Renderer
 *
 * The single place enquiry form markup is produced. Every placement — the
 * product tab, the summary-hook trigger button, the `[storedash_enquiry]`
 * shortcode, and the footer fallback — renders through here, so there is
 * exactly one markup contract for `assets/js/enquiry-widget.js` to bind to.
 *
 * The Elementor widget deliberately does NOT route through this class. It
 * carries its own style controls and its own markup, and merchants have live
 * sites depending on that styling; re-pointing it here would silently restyle
 * their storefronts on plugin update. The two paths share the stylesheet and
 * the JS, not the PHP.
 *
 * Styling here is token-driven: values arrive as CSS custom properties on the
 * wrapper element, and `assets/css/enquiry-widget.css` consumes them under
 * `.storedash-enquiry-widget--tokens`. Elementor instances never receive that
 * class, so their inline Elementor styles keep winning and cannot be affected
 * by anything a merchant does in the dashboard.
 *
 * Inline vs modal is decided by the CALLER, not read from config. The tab
 * placement is always inline (the customer already clicked "Ask a question" —
 * a trigger button inside that tab is a pointless second click), the summary
 * placement is always a trigger button plus modal (a full form under the price
 * on every product page pushes the buy button down), and the footer fallback
 * is always a trigger button because a misplaced button is far less damaging
 * than a misplaced form.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.9.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Renderer Class
 */
class Enquiry_Renderer {

	/**
	 * Maximum message length, mirrored by the handler.
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Incrementing instance counter, for unique modal IDs within a request.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * Render the widget for a product.
	 *
	 * @param array       $config  Enquiry config (see Enquiry_Config).
	 * @param \WC_Product $product Product being viewed.
	 * @param bool        $modal   Render a trigger button plus modal instead of
	 *                             an inline form.
	 * @return string HTML, or an empty string when nothing should render.
	 */
	public static function render( array $config, $product, bool $modal ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$product_id   = $product->get_id();
		$variation_id = 0;

		if ( $product->is_type( 'variation' ) ) {
			$variation_id = $product->get_id();
			$product_id   = $product->get_parent_id();
		}

		++self::$instance_count;
		$unique_id = 'storedash-enquiry-' . self::$instance_count;

		$classes = array( 'storedash-enquiry-widget', 'storedash-enquiry-widget--tokens' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( self::token_style( $config ) ); ?>"
			data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
			data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>">
			<?php if ( $modal ) : ?>
				<button type="button" class="storedash-enquiry-trigger" data-target="<?php echo esc_attr( $unique_id ); ?>">
					<span class="storedash-trigger-text"><?php echo esc_html( self::copy( $config, 'trigger_label' ) ); ?></span>
				</button>
				<div id="<?php echo esc_attr( $unique_id ); ?>" class="storedash-enquiry-modal" style="display:none;">
					<div class="storedash-enquiry-modal-content">
						<span class="storedash-enquiry-close">&times;</span>
						<?php echo self::render_form( $config, $product_id, $variation_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			<?php else : ?>
				<?php echo self::render_form( $config, $product_id, $variation_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the inner form.
	 *
	 * @param array $config       Enquiry config.
	 * @param int   $product_id   Product ID.
	 * @param int   $variation_id Variation ID, or 0.
	 * @return string
	 */
	private static function render_form( array $config, int $product_id, int $variation_id ): string {
		$heading     = self::copy( $config, 'heading' );
		$description = self::copy( $config, 'description' );
		$privacy     = (string) ( $config['copy']['privacy_notice'] ?? '' );

		$name_mode  = (string) ( $config['fields']['name'] ?? Enquiry_Config::FIELD_OPTIONAL );
		$phone_mode = (string) ( $config['fields']['phone'] ?? Enquiry_Config::FIELD_OFF );

		$consent_enabled = ! empty( $config['consent']['enabled'] );

		ob_start();
		?>
		<div class="storedash-enquiry-form">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="storedash-enquiry-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>

			<?php if ( '' !== $description ) : ?>
				<p class="storedash-enquiry-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<form class="storedash-enquiry-form-fields"
				data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
				data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>"
				data-success-message="<?php echo esc_attr( self::copy( $config, 'success_message' ) ); ?>"
				data-submitting-text="<?php echo esc_attr( self::copy( $config, 'submitting_text' ) ); ?>">
				<?php wp_nonce_field( 'storedash_enquiry_submit', 'storedash_enquiry_nonce' ); ?>

				<?php if ( Enquiry_Config::FIELD_OFF !== $name_mode ) : ?>
					<div class="storedash-enquiry-field">
						<input type="text" name="customer_name" class="storedash-enquiry-input"
							placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_name' ) ); ?>"
							<?php echo Enquiry_Config::FIELD_REQUIRED === $name_mode ? 'required' : ''; ?> />
					</div>
				<?php endif; ?>

				<div class="storedash-enquiry-field">
					<input type="email" name="customer_email" class="storedash-enquiry-input"
						placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_email' ) ); ?>" required />
				</div>

				<?php if ( Enquiry_Config::FIELD_OFF !== $phone_mode ) : ?>
					<div class="storedash-enquiry-field">
						<input type="tel" name="customer_phone" class="storedash-enquiry-input"
							placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_phone' ) ); ?>"
							<?php echo Enquiry_Config::FIELD_REQUIRED === $phone_mode ? 'required' : ''; ?> />
					</div>
				<?php endif; ?>

				<div class="storedash-enquiry-field">
					<textarea name="message" class="storedash-enquiry-textarea" rows="4"
						placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_message' ) ); ?>"
						maxlength="<?php echo esc_attr( (string) self::MAX_MESSAGE_LENGTH ); ?>" required></textarea>
				</div>

				<!-- Honeypot -->
				<div style="display:none !important;"><input type="text" name="website" tabindex="-1" autocomplete="off" /></div>

				<?php
				/*
				 * Anti-bot stamp: server render time + HMAC. Stamped in PHP, not
				 * JS — a script-set timestamp measures "time since the script
				 * ran", so a slow connection starts the clock late and a real
				 * shopper trips the trap. The HMAC stops a bot from simply
				 * posting a back-dated timestamp.
				 *
				 * The credential refresh endpoint deliberately never reissues
				 * this: re-stamping on interaction restarts the timing window at
				 * the moment a fast human is about to submit.
				 */
				$stamp = Enquiry_Handler::form_stamp();
				?>
				<input type="hidden" name="_ts" value="<?php echo esc_attr( (string) $stamp['ts'] ); ?>" />
				<input type="hidden" name="_tsh" value="<?php echo esc_attr( $stamp['hash'] ); ?>" />

				<?php if ( $consent_enabled ) : ?>
					<?php
					/*
					 * Marketing consent (ADR-019). Deliberately unchecked, and
					 * deliberately NOT `required`: an enquiry is a support
					 * request, and the merchant's reply must never be gated on a
					 * marketing opt-in. Unticking this still sends the enquiry.
					 */
					?>
					<label class="storedash-enquiry-consent">
						<input type="checkbox" name="marketing_optin" value="1" />
						<span><?php echo esc_html( self::copy( $config, 'consent_label' ) ); ?></span>
					</label>
				<?php endif; ?>

				<?php if ( '' !== $privacy ) : ?>
					<p class="storedash-enquiry-privacy"><?php echo esc_html( $privacy ); ?></p>
				<?php endif; ?>

				<button type="submit" class="storedash-enquiry-submit storedash-enquiry-submit--full">
					<?php echo esc_html( self::copy( $config, 'button_label' ) ); ?>
				</button>

				<div class="storedash-enquiry-message" style="display:none;"></div>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Resolve a copy string: merchant-authored value, else the plugin's own
	 * translated default.
	 *
	 * This is why the dashboard stores empty strings rather than pre-filled
	 * defaults — an untouched field means "use the localised default", so a
	 * merchant who configures nothing still gets correct copy in the site's
	 * language rather than English hardcoded at save time.
	 *
	 * @param array  $config Enquiry config.
	 * @param string $key    Copy key.
	 * @return string
	 */
	public static function copy( array $config, string $key ): string {
		if ( 'consent_label' === $key ) {
			$custom = trim( (string) ( $config['consent']['label'] ?? '' ) );

			return '' !== $custom
				? $custom
				: __( 'Email me about new products and offers', 'storedash' );
		}

		$custom = trim( (string) ( $config['copy'][ $key ] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		return self::default_copy( $key );
	}

	/**
	 * Plugin-supplied, translatable default for a copy key.
	 *
	 * @param string $key Copy key.
	 * @return string
	 */
	private static function default_copy( string $key ): string {
		switch ( $key ) {
			case 'tab_label':
				return __( 'Ask a question', 'storedash' );
			case 'heading':
				return __( 'Ask about this product', 'storedash' );
			case 'description':
				return __( 'Send us your question and we\'ll get back to you by email.', 'storedash' );
			case 'button_label':
				return __( 'Send enquiry', 'storedash' );
			case 'submitting_text':
				return __( 'Sending...', 'storedash' );
			case 'success_message':
				return __( 'Thank you! Your enquiry has been submitted. We\'ll get back to you soon.', 'storedash' );
			case 'trigger_label':
				return __( 'Ask a question', 'storedash' );
			case 'placeholder_name':
				return __( 'Your name', 'storedash' );
			case 'placeholder_email':
				return __( 'Your email address', 'storedash' );
			case 'placeholder_phone':
				return __( 'Phone number (optional)', 'storedash' );
			case 'placeholder_message':
				return __( 'What would you like to know?', 'storedash' );
			default:
				return '';
		}
	}

	/**
	 * Build the CSS custom property declaration for the wrapper element.
	 *
	 * Emitted as an inline `style` attribute rather than a `<style>` block so
	 * that it survives being cloned out of a `<template>` by the fallback
	 * placement, and so there is nothing to de-duplicate across instances.
	 *
	 * @param array $config Enquiry config.
	 * @return string
	 */
	private static function token_style( array $config ): string {
		$tokens = isset( $config['tokens'] ) && is_array( $config['tokens'] ) ? $config['tokens'] : array();

		$map = array(
			'accent'       => '--sd-eq-accent',
			'accent_text'  => '--sd-eq-accent-text',
			'text'         => '--sd-eq-text',
			'muted'        => '--sd-eq-muted',
			'background'   => '--sd-eq-bg',
			'border'       => '--sd-eq-border',
			'radius'       => '--sd-eq-radius',
			'field_radius' => '--sd-eq-field-radius',
			'padding'      => '--sd-eq-padding',
		);

		$declarations = array();
		foreach ( $map as $token => $property ) {
			$value = trim( (string) ( $tokens[ $token ] ?? '' ) );
			if ( '' !== $value ) {
				$declarations[] = $property . ':' . $value;
			}
		}

		return implode( ';', $declarations );
	}
}
