<?php
/**
 * Waitlist Form Renderer
 *
 * The single place waitlist form markup is produced. Every placement — the
 * automatic product-page hook, the `[storedash_waitlist]` shortcode, and the
 * footer fallback — renders through here, so there is exactly one markup
 * contract for `assets/js/waitlist-widget.js` to bind to.
 *
 * The Elementor widget deliberately does NOT route through this class. It
 * carries its own style controls and its own markup, and merchants have live
 * sites depending on that styling; re-pointing it here would silently restyle
 * their storefronts on plugin update. The two paths share the stylesheet and
 * the JS, not the PHP.
 *
 * Styling here is token-driven: values arrive as CSS custom properties on the
 * wrapper element, and `assets/css/waitlist-widget.css` consumes them under
 * `.storedash-waitlist-widget--tokens`. Elementor instances never receive that
 * class, so their inline Elementor styles keep winning and cannot be affected
 * by anything a merchant does in the dashboard.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.8.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Renderer Class
 */
class Waitlist_Renderer {

	/**
	 * Incrementing instance counter, for unique modal IDs within a request.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * Render the widget for a product.
	 *
	 * @param array       $config  Waitlist config (see Waitlist_Config).
	 * @param \WC_Product $product Product being viewed.
	 * @param array       $stock   Result of Waitlist_Stock::resolve().
	 * @return string HTML, or an empty string when nothing should render.
	 */
	public static function render( array $config, $product, array $stock ): string {
		if ( empty( $stock['eligible'] ) ) {
			return '';
		}

		++self::$instance_count;
		$unique_id = 'storedash-waitlist-' . self::$instance_count;

		$is_modal = 'modal' === ( $config['display_mode'] ?? 'inline' );

		$classes = array( 'storedash-waitlist-widget', 'storedash-waitlist-widget--tokens' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( self::token_style( $config ) ); ?>"
			data-product-id="<?php echo esc_attr( (string) $stock['product_id'] ); ?>"
			data-variation-id="<?php echo esc_attr( (string) $stock['variation_id'] ); ?>"
			data-product-type="<?php echo esc_attr( ! empty( $stock['is_variable'] ) ? 'variable' : 'simple' ); ?>"
			data-all-oos="<?php echo esc_attr( ! empty( $stock['all_oos'] ) ? '1' : '0' ); ?>">
			<?php if ( $is_modal ) : ?>
				<button type="button" class="storedash-waitlist-trigger" data-target="<?php echo esc_attr( $unique_id ); ?>">
					<span class="storedash-trigger-text"><?php echo esc_html( self::copy( $config, 'trigger_label' ) ); ?></span>
				</button>
				<div id="<?php echo esc_attr( $unique_id ); ?>" class="storedash-waitlist-modal" style="display:none;">
					<div class="storedash-waitlist-modal-content">
						<span class="storedash-waitlist-close">&times;</span>
						<?php echo self::render_form( $config, $stock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			<?php else : ?>
				<?php echo self::render_form( $config, $stock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the inner form.
	 *
	 * @param array $config Waitlist config.
	 * @param array $stock  Stock resolution result.
	 * @return string
	 */
	private static function render_form( array $config, array $stock ): string {
		$heading     = self::copy( $config, 'heading' );
		$description = self::copy( $config, 'description' );
		$privacy     = (string) ( $config['copy']['privacy_notice'] ?? '' );

		$name_mode  = (string) ( $config['fields']['name'] ?? Waitlist_Config::FIELD_OFF );
		$phone_mode = (string) ( $config['fields']['phone'] ?? Waitlist_Config::FIELD_OFF );

		$consent_enabled = ! empty( $config['consent']['enabled'] );

		/*
		 * Channel choice (Email / SMS / Both). When on, both inputs are always
		 * emitted and the script shows/hides them per choice; the initial state
		 * is rendered here from the merchant's `default_channel` so nothing
		 * jumps on load. When off, the markup is the plain email-only form.
		 */
		$sms_choice      = ! empty( $config['sms_choice_enabled'] );
		$default_channel = Waitlist_Config::effective_default_channel( $config );
		$start_phone     = Waitlist_Config::channel_needs_phone( $default_channel );
		$render_phone    = $sms_choice || Waitlist_Config::FIELD_OFF !== $phone_mode;
		$phone_hidden    = $sms_choice && Waitlist_Config::FIELD_OFF === $phone_mode && ! $start_phone;
		$phone_required  = $start_phone || Waitlist_Config::FIELD_REQUIRED === $phone_mode;
		$phone_label     = self::copy( $config, $start_phone ? 'placeholder_phone_sms' : 'placeholder_phone' );
		$email_hidden    = ! Waitlist_Config::channel_needs_email( $default_channel );

		$variations = isset( $stock['variations'] ) && is_array( $stock['variations'] )
			? $stock['variations']
			: array();

		ob_start();
		?>
		<div class="storedash-waitlist-form">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="storedash-waitlist-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>

			<?php if ( '' !== $description ) : ?>
				<p class="storedash-waitlist-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<form class="storedash-waitlist-form-fields"
				data-product-id="<?php echo esc_attr( (string) $stock['product_id'] ); ?>"
				data-variation-id="<?php echo esc_attr( (string) $stock['variation_id'] ); ?>"
				data-success-message="<?php echo esc_attr( self::copy( $config, 'success_message' ) ); ?>"
				data-submitting-text="<?php echo esc_attr( self::copy( $config, 'submitting_text' ) ); ?>"
				<?php if ( $sms_choice ) : ?>
					data-error-phone-required="<?php echo esc_attr( self::copy( $config, 'error_phone_required' ) ); ?>"
					data-error-email-required="<?php echo esc_attr( self::copy( $config, 'error_email_required' ) ); ?>"
				<?php endif; ?>>
				<?php wp_nonce_field( 'storedash_waitlist_submit', 'storedash_waitlist_nonce' ); ?>

				<?php if ( ! empty( $variations ) ) : ?>
					<div class="storedash-waitlist-field">
						<select class="storedash-waitlist-input storedash-waitlist-variation-select">
							<?php if ( count( $variations ) > 1 ) : ?>
								<option value="0"><?php echo esc_html( self::copy( $config, 'placeholder_variation' ) ); ?></option>
							<?php endif; ?>
							<?php foreach ( $variations as $variation ) : ?>
								<option value="<?php echo esc_attr( (string) $variation['variation_id'] ); ?>">
									<?php echo esc_html( $variation['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( Waitlist_Config::FIELD_OFF !== $name_mode ) : ?>
					<div class="storedash-waitlist-field">
						<input type="text" name="customer_name" class="storedash-waitlist-input"
							placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_name' ) ); ?>"
							<?php echo Waitlist_Config::FIELD_REQUIRED === $name_mode ? 'required' : ''; ?> />
					</div>
				<?php endif; ?>

				<?php if ( $sms_choice ) : ?>
					<?php echo self::render_channel_choice( $config, $default_channel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<div class="storedash-waitlist-field storedash-waitlist-email-field"<?php echo $email_hidden ? ' hidden' : ''; ?>>
						<input type="email" name="customer_email" class="storedash-waitlist-input" autocomplete="email"
							placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_email' ) ); ?>"
							aria-label="<?php echo esc_attr( self::copy( $config, 'placeholder_email' ) ); ?>"
							<?php echo $email_hidden ? '' : 'required'; ?> />
					</div>
				<?php else : ?>
					<div class="storedash-waitlist-field">
						<input type="email" name="customer_email" class="storedash-waitlist-input"
							placeholder="<?php echo esc_attr( self::copy( $config, 'placeholder_email' ) ); ?>" required />
					</div>
				<?php endif; ?>

				<?php if ( $render_phone ) : ?>
					<div class="storedash-waitlist-field storedash-waitlist-phone-field"<?php echo $phone_hidden ? ' hidden' : ''; ?>>
						<input type="tel" name="customer_phone" class="storedash-waitlist-input" autocomplete="tel"
							placeholder="<?php echo esc_attr( $phone_label ); ?>"
							aria-label="<?php echo esc_attr( $phone_label ); ?>"
							data-phone-mode="<?php echo esc_attr( $phone_mode ); ?>"
							data-placeholder-default="<?php echo esc_attr( self::copy( $config, 'placeholder_phone' ) ); ?>"
							data-placeholder-sms="<?php echo esc_attr( self::copy( $config, 'placeholder_phone_sms' ) ); ?>"
							<?php echo $phone_required ? 'required' : ''; ?> />
					</div>
				<?php endif; ?>

				<!-- Honeypot -->
				<div style="display:none !important;"><input type="text" name="website" tabindex="-1" autocomplete="off" /></div>

				<?php
				/*
				 * Anti-bot stamp: server render time + HMAC. Stamped in PHP, not
				 * JS — a script-set timestamp measures "time since the script
				 * ran", so a slow connection starts the clock late and a real
				 * shopper trips the trap. The HMAC stops a bot from simply
				 * posting a back-dated timestamp.
				 */
				$stamp = Waitlist_Handler::form_stamp();
				?>
				<input type="hidden" name="_ts" value="<?php echo esc_attr( (string) $stamp['ts'] ); ?>" />
				<input type="hidden" name="_tsh" value="<?php echo esc_attr( $stamp['hash'] ); ?>" />

				<?php if ( $consent_enabled ) : ?>
					<?php
					/*
					 * Marketing consent (ADR-019). Deliberately unchecked, and
					 * deliberately NOT `required`: the waitlist notification is
					 * transactional and must never be gated on a marketing
					 * opt-in. Unticking this still joins the waitlist.
					 */
					?>
					<label class="storedash-waitlist-consent">
						<input type="checkbox" name="marketing_optin" value="1" />
						<span
							data-label-email="<?php echo esc_attr( self::copy( $config, 'consent_label' ) ); ?>"
							data-label-sms="<?php echo esc_attr( self::copy( $config, 'consent_label_sms' ) ); ?>"
							data-label-both="<?php echo esc_attr( self::copy( $config, 'consent_label_both' ) ); ?>"
						><?php echo esc_html( self::consent_label_for( $config, $sms_choice ? $default_channel : Waitlist_Config::CHANNEL_EMAIL ) ); ?></span>
					</label>
				<?php endif; ?>

				<?php if ( '' !== $privacy ) : ?>
					<p class="storedash-waitlist-privacy"><?php echo esc_html( $privacy ); ?></p>
				<?php endif; ?>

				<button type="submit" class="storedash-waitlist-submit storedash-waitlist-submit--full">
					<?php echo esc_html( self::copy( $config, 'button_label' ) ); ?>
				</button>

				<div class="storedash-waitlist-message" style="display:none;"></div>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the "How should we tell you?" Email / SMS / Both choice.
	 *
	 * A native radio group inside a fieldset/legend, so keyboard (arrow keys)
	 * and screen-reader semantics come from the browser rather than script.
	 * Styled as a compact segmented control in the stylesheet. Starts on the
	 * merchant's `default_channel`.
	 *
	 * @param array  $config          Waitlist config.
	 * @param string $default_channel Pre-checked channel.
	 * @return string
	 */
	private static function render_channel_choice( array $config, string $default_channel ): string {
		$options = array(
			Waitlist_Config::CHANNEL_EMAIL => self::copy( $config, 'channel_email' ),
			Waitlist_Config::CHANNEL_SMS   => self::copy( $config, 'channel_sms' ),
			Waitlist_Config::CHANNEL_BOTH  => self::copy( $config, 'channel_both' ),
		);

		ob_start();
		?>
		<fieldset class="storedash-waitlist-field storedash-waitlist-channel">
			<legend class="storedash-waitlist-channel-label"><?php echo esc_html( self::copy( $config, 'label_channel' ) ); ?></legend>
			<div class="storedash-waitlist-channel-options">
				<?php foreach ( $options as $value => $label ) : ?>
					<label class="storedash-waitlist-channel-option">
						<input type="radio" name="notify_channel" value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $default_channel, $value ); ?> />
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
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
	 * @param array  $config Waitlist config.
	 * @param string $key    Copy key.
	 * @return string
	 */
	/**
	 * Consent wording for a channel: the box means "market to me on the
	 * channel(s) I picked", so Email / SMS / Both each read differently.
	 *
	 * @param array  $config  Waitlist config.
	 * @param string $channel One of Waitlist_Config::CHANNEL_*.
	 * @return string
	 */
	public static function consent_label_for( array $config, string $channel ): string {
		switch ( $channel ) {
			case Waitlist_Config::CHANNEL_SMS:
				return self::copy( $config, 'consent_label_sms' );
			case Waitlist_Config::CHANNEL_BOTH:
				return self::copy( $config, 'consent_label_both' );
			default:
				return self::copy( $config, 'consent_label' );
		}
	}

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
			case 'heading':
				return __( 'Out of stock', 'storedash' );
			case 'description':
				return __( 'Enter your email and we\'ll let you know the moment it\'s back.', 'storedash' );
			case 'button_label':
				return __( 'Notify me', 'storedash' );
			case 'submitting_text':
				return __( 'Submitting...', 'storedash' );
			case 'success_message':
				return __( 'Thank you! We\'ll notify you when this product is back in stock.', 'storedash' );
			case 'trigger_label':
				return __( 'Notify me when back in stock', 'storedash' );
			case 'placeholder_name':
				return __( 'Your name', 'storedash' );
			case 'placeholder_email':
				return __( 'Your email address', 'storedash' );
			case 'placeholder_phone':
				return __( 'Phone number (optional)', 'storedash' );
			case 'placeholder_variation':
				return __( 'Choose an option', 'storedash' );
			case 'label_channel':
				return __( 'How should we tell you?', 'storedash' );
			case 'channel_email':
				return __( 'Email', 'storedash' );
			case 'channel_sms':
				return __( 'SMS', 'storedash' );
			case 'channel_both':
				return __( 'Both', 'storedash' );
			case 'placeholder_phone_sms':
				return __( 'Mobile number', 'storedash' );
			case 'error_phone_required':
				return __( 'Please enter your phone number to get a text message.', 'storedash' );
			case 'error_email_required':
				return __( 'Please enter your email address.', 'storedash' );
			case 'consent_label_sms':
				return __( 'Text me about new products and offers', 'storedash' );
			case 'consent_label_both':
				return __( 'Email and text me about new products and offers', 'storedash' );
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
	 * @param array $config Waitlist config.
	 * @return string
	 */
	private static function token_style( array $config ): string {
		$tokens = isset( $config['tokens'] ) && is_array( $config['tokens'] ) ? $config['tokens'] : array();

		$map = array(
			'accent'       => '--sd-wl-accent',
			'accent_text'  => '--sd-wl-accent-text',
			'text'         => '--sd-wl-text',
			'muted'        => '--sd-wl-muted',
			'background'   => '--sd-wl-bg',
			'border'       => '--sd-wl-border',
			'radius'       => '--sd-wl-radius',
			'field_radius' => '--sd-wl-field-radius',
			'padding'      => '--sd-wl-padding',
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
