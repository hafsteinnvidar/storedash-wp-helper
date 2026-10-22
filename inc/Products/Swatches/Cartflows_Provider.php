<?php
declare(strict_types=1);

/**
 * CartFlows "Variation Swatches for WooCommerce" provider.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin slug `variation-swatches-woo/variation-swatches-woo.php` (tested
 * against 1.0.14). Term meta `cfvsw_color` (hex) and `cfvsw_image` — the
 * image meta holds the attachment's full-size URL (its media picker saves
 * `attachment.url`), not an id. Attribute types: select, label, color, image;
 * our `button` is its `label`, and it has no radio.
 *
 * Per-product overrides (`cfvsw_product_attr*` post meta) are out of scope.
 *
 * @since 1.29.0
 */
class Cartflows_Provider extends Abstract_Provider {

	/** Color meta. */
	public const META_COLOR = 'cfvsw_color';

	/** Image meta (attachment URL). */
	public const META_IMAGE = 'cfvsw_image';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'cartflows';
	}

	/**
	 * The plugin defines CFVSW_VER at file load (it has no global main class;
	 * its loader is `CFVSW\Plugin_Loader`), so this works in REST context.
	 *
	 * @return string|null
	 */
	protected function detect_version(): ?string {
		if ( defined( 'CFVSW_VER' ) ) {
			return (string) constant( 'CFVSW_VER' );
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
			'button' => 'label',
		);
	}

	/**
	 * CartFlows only adds its types to the selector on the attributes admin
	 * screen, so REST writes need them merged in explicitly.
	 *
	 * @return array<string,string>
	 */
	public function native_type_labels(): array {
		return array(
			'select' => __( 'Select', 'storedash' ),
			'label'  => __( 'Label', 'storedash' ),
			'color'  => __( 'Color', 'storedash' ),
			'image'  => __( 'Image', 'storedash' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function read( int $term_id ): array {
		$display   = $this->read_display( $term_id );
		$image_id  = null;
		$image_url = $display['image_url'];

		if ( null !== $image_url ) {
			$image_id = self::id_or_null( attachment_url_to_postid( $image_url ) );
			if ( null !== $image_id ) {
				$image_url = self::thumbnail_url( $image_id ) ?? $image_url;
			}
		}

		return array(
			'color'     => $display['color'],
			'color2'    => null,
			'image_id'  => $image_id,
			'image_url' => $image_url,
			'tooltip'   => null,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function read_display( int $term_id ): array {
		$url = get_term_meta( $term_id, self::META_IMAGE, true );
		$url = is_string( $url ) ? esc_url_raw( $url ) : '';

		return array(
			'color'     => self::hex_or_null( get_term_meta( $term_id, self::META_COLOR, true ) ),
			'color2'    => null,
			'image_url' => '' !== $url ? $url : null,
		);
	}

	/**
	 * `color2` is ignored (CartFlows has no dual color).
	 *
	 * @param int   $term_id Term id.
	 * @param array $data    Normalized swatch subset.
	 * @return true|\WP_Error
	 */
	public function write( int $term_id, array $data ) {
		$image_url = null;
		if ( array_key_exists( 'image_id', $data ) && null !== $data['image_id'] ) {
			$image_url = wp_get_attachment_url( (int) $data['image_id'] );
			if ( ! is_string( $image_url ) || '' === $image_url ) {
				return new \WP_Error(
					'storedash_invalid_swatch_image',
					__( 'The swatch image attachment has no URL.', 'storedash' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array_key_exists( 'color', $data ) ) {
			if ( null === $data['color'] ) {
				delete_term_meta( $term_id, self::META_COLOR );
			} else {
				update_term_meta( $term_id, self::META_COLOR, $data['color'] );
			}
		}

		if ( array_key_exists( 'image_id', $data ) ) {
			if ( null === $image_url ) {
				delete_term_meta( $term_id, self::META_IMAGE );
			} else {
				update_term_meta( $term_id, self::META_IMAGE, esc_url_raw( $image_url ) );
			}
		}

		return true;
	}
}
