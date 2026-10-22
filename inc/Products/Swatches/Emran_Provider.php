<?php
declare(strict_types=1);

/**
 * Emran Ahmed "Variation Swatches for WooCommerce" provider.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin slug `woo-variation-swatches/woo-variation-swatches.php` (tested
 * against 2.6.0). Swatches live in plain term meta; the attribute type is
 * WooCommerce's own `attribute_type` column with the same names we use.
 *
 * @since 1.29.0
 */
class Emran_Provider extends Abstract_Provider {

	/** Primary color meta (hex). */
	public const META_COLOR = 'product_attribute_color';

	/** Secondary color meta (hex), shown only when dual color is on. */
	public const META_COLOR2 = 'secondary_color';

	/** Dual color flag meta (`yes` from the admin form, `1` from its REST). */
	public const META_DUAL = 'is_dual_color';

	/** Image meta (attachment id). */
	public const META_IMAGE = 'product_attribute_image';

	/** Tooltip text meta (Pro UI; read-only here). */
	public const META_TOOLTIP = 'tooltip_text';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'emran';
	}

	/**
	 * The plugin defines this constant at file load, before plugins_loaded,
	 * so it is reliable in REST context.
	 *
	 * @return string|null
	 */
	protected function detect_version(): ?string {
		if ( defined( 'WOO_VARIATION_SWATCHES_PLUGIN_VERSION' ) ) {
			return (string) constant( 'WOO_VARIATION_SWATCHES_PLUGIN_VERSION' );
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function type_map(): array {
		return array(
			'select' => 'select',
			'color'  => 'color',
			'image'  => 'image',
			'button' => 'button',
			'radio'  => 'radio',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function native_type_labels(): array {
		return array(
			'select' => __( 'Select', 'storedash' ),
			'color'  => __( 'Color', 'storedash' ),
			'image'  => __( 'Image', 'storedash' ),
			'button' => __( 'Button', 'storedash' ),
			'radio'  => __( 'Radio', 'storedash' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function read( int $term_id ): array {
		$display  = $this->read_display( $term_id );
		$image_id = self::id_or_null( get_term_meta( $term_id, self::META_IMAGE, true ) );
		$tooltip  = get_term_meta( $term_id, self::META_TOOLTIP, true );
		$tooltip  = is_string( $tooltip ) ? sanitize_text_field( $tooltip ) : '';

		return array(
			'color'     => $display['color'],
			'color2'    => $display['color2'],
			'image_id'  => $image_id,
			'image_url' => $display['image_url'],
			'tooltip'   => '' !== $tooltip ? $tooltip : null,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function read_display( int $term_id ): array {
		$color2 = null;
		if ( self::is_truthy( get_term_meta( $term_id, self::META_DUAL, true ) ) ) {
			$color2 = self::hex_or_null( get_term_meta( $term_id, self::META_COLOR2, true ) );
		}

		return array(
			'color'     => self::hex_or_null( get_term_meta( $term_id, self::META_COLOR, true ) ),
			'color2'    => $color2,
			'image_url' => self::thumbnail_url( self::id_or_null( get_term_meta( $term_id, self::META_IMAGE, true ) ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( int $term_id, array $data ) {
		if ( array_key_exists( 'color', $data ) ) {
			if ( null === $data['color'] ) {
				delete_term_meta( $term_id, self::META_COLOR );
			} else {
				update_term_meta( $term_id, self::META_COLOR, $data['color'] );
			}
		}

		if ( array_key_exists( 'color2', $data ) ) {
			if ( null === $data['color2'] ) {
				delete_term_meta( $term_id, self::META_COLOR2 );
				update_term_meta( $term_id, self::META_DUAL, 'no' );
			} else {
				update_term_meta( $term_id, self::META_COLOR2, $data['color2'] );
				update_term_meta( $term_id, self::META_DUAL, 'yes' );
			}
		}

		if ( array_key_exists( 'image_id', $data ) ) {
			if ( null === $data['image_id'] ) {
				delete_term_meta( $term_id, self::META_IMAGE );
			} else {
				update_term_meta( $term_id, self::META_IMAGE, (int) $data['image_id'] );
			}
		}

		return true;
	}

	/**
	 * Mirror of `wc_string_to_bool()` without needing WooCommerce loaded.
	 *
	 * @param mixed $value Raw meta value.
	 * @return bool
	 */
	private static function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
