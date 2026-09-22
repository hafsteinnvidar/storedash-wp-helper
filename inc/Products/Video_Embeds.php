<?php
declare(strict_types=1);

/**
 * Video embeds in product descriptions
 *
 * WooCommerce's REST products controller runs `description` and
 * `short_description` through wp_filter_post_kses(), and the variations
 * controller runs `description` through wp_kses_post(). Neither allows
 * <iframe>, so a YouTube/Vimeo video added in Storedash arrives in WordPress as
 * an empty wrapper and never shows on the product page.
 *
 * This re-sanitizes those fields from the raw request with the same 'post'
 * allowlist plus <iframe>, then drops every iframe whose src is not an https
 * YouTube/Vimeo embed URL. Fields without an iframe are left exactly as
 * WooCommerce sanitized them.
 *
 * @package StoreDash\Products
 * @since   1.18.0
 */

namespace StoreDash\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps trusted video iframes in product descriptions written over REST.
 *
 * @since 1.18.0
 */
class Video_Embeds {

	/**
	 * Attributes kept on an allowed iframe. Everything else is stripped by kses.
	 *
	 * @var string[]
	 */
	const IFRAME_ATTRIBUTES = array( 'src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy' );

	/**
	 * Embed hosts and the path prefix a player URL must start with.
	 *
	 * @var array<string, string>
	 */
	const EMBED_HOSTS = array(
		'www.youtube.com'          => '/embed/',
		'youtube.com'              => '/embed/',
		'www.youtube-nocookie.com' => '/embed/',
		'youtube-nocookie.com'     => '/embed/',
		'player.vimeo.com'         => '/video/',
	);

	/**
	 * Register the WooCommerce REST hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'restore_product_embeds' ), 10, 2 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'restore_variation_embeds' ), 10, 2 );
	}

	/**
	 * Restore video iframes in a product's description and short description.
	 *
	 * @param \WC_Product|\WP_Error $product Product about to be saved.
	 * @param \WP_REST_Request      $request Request object.
	 * @return \WC_Product|\WP_Error
	 */
	public function restore_product_embeds( $product, $request ) {
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$fields = array(
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
		);

		foreach ( $fields as $field => $setter ) {
			$raw = self::raw_html( $request, $field );
			if ( null !== $raw ) {
				// Mirror wp_filter_post_kses() (slashes stripped in, added back out)
				// so the only difference from WooCommerce's value is the iframes.
				$product->$setter( addslashes( self::sanitize( stripslashes( $raw ) ) ) );
			}
		}

		return $product;
	}

	/**
	 * Restore video iframes in a variation's description.
	 *
	 * @param \WC_Product_Variation|\WP_Error $variation Variation about to be saved.
	 * @param \WP_REST_Request                $request   Request object.
	 * @return \WC_Product_Variation|\WP_Error
	 */
	public function restore_variation_embeds( $variation, $request ) {
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		$raw = self::raw_html( $request, 'description' );
		if ( null !== $raw ) {
			// Variations use wp_kses_post() (no slashing) in WooCommerce.
			$variation->set_description( self::sanitize( $raw ) );
		}

		return $variation;
	}

	/**
	 * Kses with the 'post' allowlist plus iframe, keeping only trusted players.
	 *
	 * @param string $html Unslashed HTML.
	 * @return string
	 */
	public static function sanitize( string $html ): string {
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['iframe'] = array_fill_keys( self::IFRAME_ATTRIBUTES, true );

		return self::keep_allowed_iframes( wp_kses( $html, $allowed ) );
	}

	/**
	 * Remove every iframe whose src is not a trusted video player URL.
	 *
	 * @param string $html Kses-sanitized HTML.
	 * @return string
	 */
	public static function keep_allowed_iframes( string $html ): string {
		$result = preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*>#is',
			static function ( array $match ): string {
				if ( preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $match[0], $src )
					&& self::is_allowed_video_src( html_entity_decode( $src[2], ENT_QUOTES ) ) ) {
					return $match[0];
				}
				return '';
			},
			$html
		);

		return null === $result ? '' : $result;
	}

	/**
	 * Whether a URL is an https YouTube/Vimeo embed player URL.
	 *
	 * @param string $src Iframe src.
	 * @return bool
	 */
	public static function is_allowed_video_src( string $src ): bool {
		$parts = wp_parse_url( trim( $src ) );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) ) {
			return false;
		}

		$host   = strtolower( $parts['host'] ?? '' );
		$prefix = self::EMBED_HOSTS[ $host ] ?? null;

		return null !== $prefix && 0 === strpos( $parts['path'] ?? '', $prefix );
	}

	/**
	 * The raw request value for a field, only when it contains an iframe.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $field   Field name.
	 * @return string|null
	 */
	private static function raw_html( $request, string $field ): ?string {
		$raw = $request[ $field ] ?? null;

		return is_string( $raw ) && false !== stripos( $raw, '<iframe' ) ? $raw : null;
	}
}
