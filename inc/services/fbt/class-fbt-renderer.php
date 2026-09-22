<?php
/**
 * FBT Renderer
 *
 * The single place hook-mode FBT markup is produced. Every placement — the
 * summary hook, `after_summary`, the `[storedash_fbt]` shortcode, and the
 * footer fallback — renders through here, so there is exactly one markup
 * contract for `assets/js/fbt-widget.js` to bind to.
 *
 * The storedash-essentials Elementor/Bricks widgets deliberately do NOT route
 * through this class (same rule as the enquiry Elementor widget): they carry
 * their own controls and merchants have live pages depending on that styling.
 * The markup here intentionally uses the SAME `.storedash-fbt-*` class
 * contract as those widgets — plus a `storedash-fbt--tokens` modifier that
 * only this renderer emits, which is what scopes the token-driven stylesheet
 * so it can never restyle an essentials widget instance.
 *
 * Pricing rows are rendered with data attributes (`data-price`,
 * `data-main-price`, `data-fbt-items-total`) that the JS recomputes from on
 * checkbox change — identical to the essentials widget behaviour.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Renderer Class
 */
class FBT_Renderer {

	/**
	 * Render the bundle for a product.
	 *
	 * @param array       $config  FBT config (see FBT_Config).
	 * @param \WC_Product $product Main product being viewed.
	 * @return string HTML, or an empty string when nothing should render.
	 */
	public static function render( array $config, $product ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		// Variations carry no bundle meta of their own — the bundle always
		// belongs to the parent product.
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		$bundle = FBT_Data::bundle( $product_id );
		if ( null === $bundle ) {
			return '';
		}

		$items    = $bundle['items'];
		$discount = (float) $bundle['discount'];
		$title    = '' !== $bundle['before_text'] ? $bundle['before_text'] : self::copy( $config, 'title' );

		$show_images     = ! empty( $config['display']['show_images'] );
		$show_additional = ! empty( $config['display']['show_additional_price'] );
		$show_total      = ! empty( $config['display']['show_grand_total'] );
		$show_badge      = ! empty( $config['display']['show_discount_badge'] );
		$show_button     = ! empty( $config['display']['show_add_button'] );
		$img_size        = FBT_Config::image_px();

		$main_price          = (float) $product->get_price();
		$discount_multiplier = ( $discount > 0 ) ? ( 100 - $discount ) / 100 : 1;

		$fbt_items_total = 0.0;
		foreach ( $items as $item ) {
			if ( $item['checked'] && $item['product']->is_in_stock() ) {
				$fbt_items_total += (float) $item['product']->get_price() * $item['qty'];
			}
		}
		$fbt_items_discounted = $fbt_items_total * $discount_multiplier;
		$grand_total          = $main_price + $fbt_items_discounted;
		$has_checked          = $fbt_items_total > 0;

		ob_start();
		?>
		<div class="storedash-fbt-widget-container storedash-fbt--tokens"
			style="<?php echo esc_attr( self::token_style( $config ) ); ?>"
			data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
			data-discount="<?php echo esc_attr( (string) $discount ); ?>">
			<div class="storedash-fbt-bundle storedash-fbt-layout-compact">
				<?php if ( '' !== $title ) : ?>
					<h3 class="storedash-fbt-title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>

				<div class="storedash-fbt-products storedash-fbt-compact-list">
					<?php
					foreach ( $items as $item ) :
						$item_product    = $item['product'];
						$item_qty        = (int) $item['qty'];
						$original_price  = (float) $item_product->get_price() * $item_qty;
						$variation_id    = (int) $item['variation_id'];
						$is_out_of_stock = ! $item_product->is_in_stock();
						$is_checked      = $item['checked'] && ! $is_out_of_stock;
						?>
						<div class="storedash-fbt-compact-item<?php echo $is_out_of_stock ? ' storedash-fbt-out-of-stock' : ''; ?>"
							data-product-id="<?php echo esc_attr( (string) $item_product->get_id() ); ?>"
							data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>"
							data-price="<?php echo esc_attr( (string) $original_price ); ?>"
							data-quantity="<?php echo esc_attr( (string) $item_qty ); ?>">
							<label class="storedash-fbt-compact-label">
								<input type="checkbox"
									class="storedash-fbt-checkbox"
									value="<?php echo esc_attr( (string) $item_product->get_id() ); ?>"
									<?php checked( $is_checked ); ?>
									<?php disabled( $is_out_of_stock ); ?> />
								<span class="storedash-fbt-compact-checkbox-visual"></span>

								<?php
								if ( $show_images ) :
									$image_id = $item_product->get_image_id();
									if ( $image_id ) :
										?>
										<span class="storedash-fbt-compact-image" style="width:<?php echo esc_attr( (string) $img_size ); ?>px;height:<?php echo esc_attr( (string) $img_size ); ?>px;">
											<?php
											echo wp_get_attachment_image(
												$image_id,
												'woocommerce_thumbnail',
												false,
												array(
													'width' => $img_size,
													'height' => $img_size,
													'alt' => $item_product->get_name(),
												)
											);
											?>
										</span>
										<?php
									endif;
								endif;
								?>

								<span class="storedash-fbt-compact-name">
									<?php echo esc_html( $item_product->get_name() ); ?>
									<?php if ( $item_qty > 1 ) : ?>
										<span class="storedash-fbt-compact-qty">&times;<?php echo esc_html( (string) $item_qty ); ?></span>
									<?php endif; ?>
								</span>

								<span class="storedash-fbt-compact-price">
									<?php if ( $discount > 0 ) : ?>
										<del><?php echo wp_kses_post( wc_price( $original_price ) ); ?></del>
										<?php echo wp_kses_post( wc_price( $original_price * $discount_multiplier ) ); ?>
									<?php else : ?>
										<?php echo wp_kses_post( wc_price( $original_price ) ); ?>
									<?php endif; ?>
								</span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $discount > 0 && $show_badge ) : ?>
					<div class="storedash-fbt-discount-badge">
						<?php echo esc_html( self::discount_badge( $config, $discount ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_additional || $show_total ) : ?>
					<div class="storedash-fbt-pricing-section">
						<?php if ( $show_additional ) : ?>
							<div class="storedash-fbt-items-total-row<?php echo $has_checked ? '' : ' storedash-fbt-hidden'; ?>">
								<span class="storedash-fbt-items-label"><?php echo esc_html( self::copy( $config, 'items_label' ) ); ?></span>
								<span class="storedash-fbt-items-total" data-fbt-items-total="<?php echo esc_attr( (string) $fbt_items_total ); ?>">
									<?php if ( $discount > 0 ) : ?>
										<del><?php echo wp_kses_post( wc_price( $fbt_items_total ) ); ?></del>
										<?php echo wp_kses_post( wc_price( $fbt_items_discounted ) ); ?>
									<?php else : ?>
										<?php echo wp_kses_post( wc_price( $fbt_items_total ) ); ?>
									<?php endif; ?>
								</span>
							</div>
						<?php endif; ?>
						<?php if ( $show_total ) : ?>
							<div class="storedash-fbt-total<?php echo $has_checked ? '' : ' storedash-fbt-hidden'; ?>">
								<span class="storedash-fbt-total-label"><?php echo esc_html( self::copy( $config, 'total_label' ) ); ?></span>
								<span class="storedash-fbt-total-price" data-main-price="<?php echo esc_attr( (string) $main_price ); ?>">
									<?php echo wp_kses_post( wc_price( $grand_total ) ); ?>
								</span>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_button ) : ?>
					<button type="button"
						class="storedash-fbt-add-button"
						data-label="<?php echo esc_attr( self::copy( $config, 'button_label' ) ); ?>"
						data-adding-label="<?php echo esc_attr( self::copy( $config, 'adding_text' ) ); ?>">
						<?php echo esc_html( self::copy( $config, 'button_label' ) ); ?>
					</button>
					<div class="storedash-fbt-message" data-added-message="<?php echo esc_attr( self::copy( $config, 'added_message' ) ); ?>" style="display:none;"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Resolve a copy string: the merchant's override, else the plugin's own
	 * translated default. Empty override means "use the default".
	 *
	 * @param array  $config FBT config.
	 * @param string $key    Copy key.
	 * @return string
	 */
	public static function copy( array $config, string $key ): string {
		$value = (string) ( $config['copy'][ $key ] ?? '' );
		if ( '' !== $value ) {
			return $value;
		}

		return self::default_copy()[ $key ] ?? '';
	}

	/**
	 * Resolve the discount badge text with the percent number filled in.
	 *
	 * Deliberately NOT `sprintf`: the badge can be merchant-typed text, and any
	 * stray `%` ("Save 10%!", the dashboard's own `%s% discount` placeholder)
	 * makes PHP 8 `sprintf` throw — a fatal error on the product page. Plain
	 * replacement accepts both `%s%` and `%s%%` styles and never throws.
	 *
	 * @param array $config   FBT config.
	 * @param float $discount Discount percent.
	 * @return string
	 */
	public static function discount_badge( array $config, float $discount ): string {
		return str_replace(
			array( '%s', '%%' ),
			array( (string) $discount, '%' ),
			self::copy( $config, 'discount_badge' )
		);
	}

	/**
	 * Default copy, translated in the site's language.
	 *
	 * Mirrored (in English) by the dashboard's COPY_PLACEHOLDERS. If the two
	 * drift, the worst case is an inaccurate dashboard preview, not wrong
	 * storefront copy.
	 *
	 * @return array<string,string>
	 */
	public static function default_copy(): array {
		return array(
			'title'          => __( 'Frequently bought together', 'storedash' ),
			'items_label'    => __( 'Selected extras:', 'storedash' ),
			'total_label'    => __( 'Total:', 'storedash' ),
			'button_label'   => __( 'Add selected to cart', 'storedash' ),
			'adding_text'    => __( 'Adding…', 'storedash' ),
			'added_message'  => __( 'Added to cart', 'storedash' ),
			/* translators: %s: discount percentage number. */
			'discount_badge' => __( '%s%% discount on bundle', 'storedash' ),
		);
	}

	/**
	 * Build the CSS-custom-property style attribute from config tokens.
	 *
	 * Values were sanitised on receipt (FBT_Config::sanitize) and the attribute
	 * is escaped at the call site; consumed by `assets/css/fbt-widget.css`
	 * under `.storedash-fbt--tokens` only, so essentials widget instances are
	 * untouchable from here.
	 *
	 * @param array $config FBT config.
	 * @return string
	 */
	private static function token_style( array $config ): string {
		$tokens = is_array( $config['tokens'] ?? null ) ? $config['tokens'] : array();
		$style  = '';

		foreach ( $tokens as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}

			$style .= '--sd-fbt-' . str_replace( '_', '-', (string) $key ) . ':' . $value . ';';
		}

		return $style;
	}
}
