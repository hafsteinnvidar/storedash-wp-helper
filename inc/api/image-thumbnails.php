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
 *
 * Variations also get `gallery_images`: WooCommerce returns the native
 * variation gallery as bare attachment IDs (`gallery_image_ids`), so each ID
 * is resolved to `{ id, src, name, alt, thumbnail_src? }` here — StoreDash has
 * no other way to show those photos. Same order as `gallery_image_ids`; IDs
 * that no longer resolve to a file are skipped.
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
				if ( isset( $data['gallery_image_ids'] ) && is_array( $data['gallery_image_ids'] ) ) {
					$data['gallery_images'] = self::gallery_images(
						$data['gallery_image_ids'],
						'wp_get_attachment_image_src',
						array( __CLASS__, 'attachment_labels' )
					);
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
	 * Resolve variation gallery attachment IDs to REST image objects. Pure apart
	 * from the two resolvers, so it is unit-testable without WordPress.
	 *
	 * @param array    $ids      Attachment IDs, in gallery order.
	 * @param callable $resolver wp_get_attachment_image_src-compatible.
	 * @param callable $labels   ( int $id ) → array{ name: string, alt: string }.
	 * @return array<int, array<string, mixed>>
	 */
	public static function gallery_images( array $ids, callable $resolver, callable $labels ) {
		$images = array();
		foreach ( $ids as $raw_id ) {
			$id = is_numeric( $raw_id ) ? (int) $raw_id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$full = $resolver( $id, 'full' );
			if ( ! is_array( $full ) || empty( $full[0] ) || ! is_string( $full[0] ) ) {
				continue;
			}
			$label    = $labels( $id );
			$images[] = self::with_thumbnail(
				array(
					'id'   => $id,
					'src'  => $full[0],
					'name' => isset( $label['name'] ) ? (string) $label['name'] : '',
					'alt'  => isset( $label['alt'] ) ? (string) $label['alt'] : '',
				),
				$resolver
			);
		}
		return $images;
	}

	/**
	 * Name + alt text for an attachment (same sources WC uses for REST images).
	 *
	 * @param int $id Attachment ID.
	 * @return array{ name: string, alt: string }
	 */
	public static function attachment_labels( $id ) {
		return array(
			'name' => (string) get_the_title( $id ),
			'alt'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
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
