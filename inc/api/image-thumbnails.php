<?php
/**
 * Adds a small-image URL (`thumbnail_src`) next to `src` on the images the
 * WooCommerce REST API returns, so StoreDash lists can load a ~10 KB catalog
 * thumbnail instead of the full-size original.
 *
 * Strictly additive: `src` and every other core field are left untouched, and
 * `thumbnail_src` is only added when WordPress confirms a real, smaller,
 * already-generated file exists. The URL is resolved by WordPress itself —
 * never guessed from the filename (sizes and names differ per store/theme).
 * Any guard miss or error returns the response unchanged.
 *
 * Covers: product `images[]`, variation `image`, order `line_items[].image`.
 * WC webhooks build their payload through the same serializers, so they get
 * the field too.
 *
 * @package StoreDash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends `thumbnail_src` to REST image objects.
 */
class StoreDash_Image_Thumbnails {

	/**
	 * WooCommerce catalog size: generated for every product image, ~300px.
	 */
	const SIZE = 'woocommerce_thumbnail';

	/**
	 * Per-request memo: attachment ID → thumbnail URL ('' = none).
	 *
	 * @var array<int, string>
	 */
	private static $memo = array();

	/**
	 * Constructor — self-registers on the product / variation / order serializers.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'on_product' ), 20, 1 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'on_variation' ), 20, 1 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'on_order' ), 20, 1 );
	}

	/**
	 * Product: `images[]`.
	 *
	 * @param mixed $response WP_REST_Response (passed through unchanged on any guard miss).
	 * @return mixed
	 */
	public function on_product( $response ) {
		return self::rewrite(
			$response,
			function ( array $data ) {
				if ( isset( $data['images'] ) && is_array( $data['images'] ) ) {
					foreach ( $data['images'] as $i => $image ) {
						$data['images'][ $i ] = self::with_thumbnail( $image, 'wp_get_attachment_image_src' );
					}
				}
				return $data;
			}
		);
	}

	/**
	 * Variation: singular `image`.
	 *
	 * @param mixed $response WP_REST_Response.
	 * @return mixed
	 */
	public function on_variation( $response ) {
		return self::rewrite(
			$response,
			function ( array $data ) {
				if ( isset( $data['image'] ) ) {
					$data['image'] = self::with_thumbnail( $data['image'], 'wp_get_attachment_image_src' );
				}
				return $data;
			}
		);
	}

	/**
	 * Order: `line_items[].image`.
	 *
	 * @param mixed $response WP_REST_Response.
	 * @return mixed
	 */
	public function on_order( $response ) {
		return self::rewrite(
			$response,
			function ( array $data ) {
				if ( isset( $data['line_items'] ) && is_array( $data['line_items'] ) ) {
					foreach ( $data['line_items'] as $i => $item ) {
						if ( is_array( $item ) && isset( $item['image'] ) ) {
							$data['line_items'][ $i ]['image'] = self::with_thumbnail( $item['image'], 'wp_get_attachment_image_src' );
						}
					}
				}
				return $data;
			}
		);
	}

	/**
	 * Apply `$mutate` to the response data; on anything unexpected return the
	 * response exactly as it came in.
	 *
	 * @param mixed    $response WP_REST_Response.
	 * @param callable $mutate   function( array $data ): array.
	 * @return mixed
	 */
	private static function rewrite( $response, callable $mutate ) {
		try {
			if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
				return $response;
			}
			$data = $response->get_data();
			if ( ! is_array( $data ) ) {
				return $response;
			}
			$response->set_data( $mutate( $data ) );
		} catch ( \Throwable $e ) {
			return $response;
		}
		return $response;
	}

	/**
	 * Return the image with `thumbnail_src` added when a real smaller file
	 * exists; otherwise the image unchanged. Pure apart from `$resolver`, so it
	 * is unit-testable without WordPress.
	 *
	 * @param mixed    $image    REST image (array or object with `id` + `src`); anything else passes through.
	 * @param callable $resolver wp_get_attachment_image_src-compatible: ( int $id, string $size ) → [ url, w, h, is_intermediate ] | false.
	 * @return mixed
	 */
	public static function with_thumbnail( $image, callable $resolver ) {
		$is_object = is_object( $image );
		$fields    = $is_object ? get_object_vars( $image ) : $image;
		if ( ! is_array( $fields ) ) {
			return $image;
		}

		$id  = isset( $fields['id'] ) && is_numeric( $fields['id'] ) ? (int) $fields['id'] : 0;
		$src = isset( $fields['src'] ) && is_string( $fields['src'] ) ? $fields['src'] : '';
		// No attachment (placeholder / external image) or nothing to compare against.
		if ( $id <= 0 || '' === $src ) {
			return $image;
		}

		if ( ! array_key_exists( $id, self::$memo ) ) {
			$found = $resolver( $id, self::SIZE );
			// [3] = is_intermediate: false means WP fell back to the full-size file.
			self::$memo[ $id ] = ( is_array( $found ) && ! empty( $found[0] ) && is_string( $found[0] ) && ! empty( $found[3] ) )
				? $found[0]
				: '';
		}

		$thumb = self::$memo[ $id ];
		if ( '' === $thumb || $thumb === $src ) {
			return $image;
		}

		if ( $is_object ) {
			$image->thumbnail_src = $thumb;
		} else {
			$image['thumbnail_src'] = $thumb;
		}
		return $image;
	}

	/**
	 * Test hook: clear the per-request memo.
	 */
	public static function reset_memo() {
		self::$memo = array();
	}
}

// Initialize (self-registering). Guarded so the file can be loaded by the
// standalone unit-test harness (no WordPress) to exercise with_thumbnail().
if ( function_exists( 'add_filter' ) ) {
	new StoreDash_Image_Thumbnails();
}
