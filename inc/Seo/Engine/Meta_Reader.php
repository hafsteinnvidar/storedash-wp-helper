<?php
/**
 * Reads Storedash SEO meta (plan §4.9) for a post or term.
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Settings;

/**
 * One `get_post_meta( $id )` / `get_term_meta( $id )` call (no key) per object
 * per request; both hit the meta cache WordPress already primed for the
 * queried object.
 *
 * @since 1.26.0
 */
class Meta_Reader {

	/**
	 * Field => meta key (plan §4.9). Same keys on postmeta and termmeta.
	 */
	const KEYS = array(
		'title'               => '_storedash_seo_title',
		'description'         => '_storedash_seo_description',
		'focus_keyword'       => '_storedash_seo_focus_keyword',
		'canonical'           => '_storedash_seo_canonical',
		'robots'              => '_storedash_seo_robots',
		'breadcrumb_title'    => '_storedash_seo_breadcrumb_title',
		'og_title'            => '_storedash_seo_og_title',
		'og_description'      => '_storedash_seo_og_description',
		'og_image'            => '_storedash_seo_og_image',
		'twitter_title'       => '_storedash_seo_twitter_title',
		'twitter_description' => '_storedash_seo_twitter_description',
	);

	/**
	 * Robots directives (all default true = allowed).
	 */
	const ROBOTS = array( 'index', 'follow', 'imageindex', 'archive', 'snippet' );

	/**
	 * Fetcher for post meta: fn( int $id ): array.
	 *
	 * @var callable
	 */
	private $post_fetcher;

	/**
	 * Fetcher for term meta: fn( int $id ): array.
	 *
	 * @var callable
	 */
	private $term_fetcher;

	/**
	 * Per-request cache keyed "post:ID" / "term:ID".
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param callable|null $post_fetcher Default `get_post_meta( $id )`.
	 * @param callable|null $term_fetcher Default `get_term_meta( $id )`.
	 */
	public function __construct( $post_fetcher = null, $term_fetcher = null ) {
		$this->post_fetcher = $post_fetcher ? $post_fetcher : static function ( $id ) {
			return get_post_meta( $id );
		};
		$this->term_fetcher = $term_fetcher ? $term_fetcher : static function ( $id ) {
			return get_term_meta( $id );
		};
	}

	/**
	 * Normalized SEO meta for a post (product, page, post).
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	public function for_post( int $id ): array {
		return $this->read( 'post', $id );
	}

	/**
	 * Normalized SEO meta for a term (category, tag, attribute term).
	 *
	 * @param int $id Term ID.
	 * @return array
	 */
	public function for_term( int $id ): array {
		return $this->read( 'term', $id );
	}

	/**
	 * Cached read.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object ID.
	 * @return array
	 */
	private function read( string $type, int $id ): array {
		$key = $type . ':' . $id;
		if ( ! isset( $this->cache[ $key ] ) ) {
			$raw                 = $id > 0 ? call_user_func( 'post' === $type ? $this->post_fetcher : $this->term_fetcher, $id ) : array();
			$this->cache[ $key ] = self::normalize( is_array( $raw ) ? $raw : array() );
		}
		return $this->cache[ $key ];
	}

	/**
	 * Normalize a raw "all meta" array (key => list of raw values) into fields.
	 *
	 * Pure function. Strings are trimmed; `canonical` / `og_image` must be
	 * absolute http(s) URLs (an attachment ID is kept for `og_image`); `robots`
	 * accepts a JSON string, a serialized array or an array; absent = all true.
	 *
	 * @param array $raw Result of get_post_meta( $id ) / get_term_meta( $id ).
	 * @return array
	 */
	public static function normalize( array $raw ): array {
		$out = array();
		foreach ( self::KEYS as $field => $meta_key ) {
			if ( 'robots' === $field ) {
				continue;
			}
			$value         = self::first( $raw, $meta_key );
			$out[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		$out['canonical'] = Settings::http_url( $out['canonical'] );
		if ( ! ctype_digit( $out['og_image'] ) ) {
			$out['og_image'] = Settings::http_url( $out['og_image'] );
		}

		$out['robots'] = self::robots( self::first( $raw, self::KEYS['robots'] ) );

		// Featured image (posts) / category thumbnail (WC product_cat term meta).
		$thumb               = self::first( $raw, '_thumbnail_id' );
		$thumb               = ( null === $thumb || '' === $thumb ) ? self::first( $raw, 'thumbnail_id' ) : $thumb;
		$out['thumbnail_id'] = is_scalar( $thumb ) ? (int) $thumb : 0;

		return $out;
	}

	/**
	 * Parse the robots meta value.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,bool>
	 */
	public static function robots( $value ): array {
		$out = array_fill_keys( self::ROBOTS, true );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : self::unserialize( $value );
		}
		if ( ! is_array( $value ) ) {
			return $out;
		}

		foreach ( self::ROBOTS as $directive ) {
			if ( array_key_exists( $directive, $value ) ) {
				$v                 = $value[ $directive ];
				$out[ $directive ] = ! ( false === $v || 0 === $v || '0' === $v || 'false' === $v || '' === $v || null === $v );
			}
		}
		return $out;
	}

	/**
	 * First value for a key, unserialized when stored serialized.
	 *
	 * @param array  $raw Meta array.
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public static function first( array $raw, string $key ) {
		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}
		$value = is_array( $raw[ $key ] ) ? reset( $raw[ $key ] ) : $raw[ $key ];
		return is_string( $value ) ? self::unserialize( $value ) : $value;
	}

	/**
	 * Unserialize a serialized array (meta fetched without a key is not
	 * unserialized by WordPress). Objects are never instantiated.
	 *
	 * @param string $value Raw meta value.
	 * @return mixed
	 */
	private static function unserialize( string $value ) {
		if ( 0 === strpos( $value, 'a:' ) ) {
			$parsed = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}
		return $value;
	}
}
