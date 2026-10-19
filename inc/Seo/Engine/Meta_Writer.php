<?php
/**
 * Validated writes of Storedash SEO meta (plan §4.9) for posts and terms.
 *
 * Used by the bulk-meta (B3) and migrate endpoints. `prepare()` is pure:
 * it validates an API field payload and turns it into meta writes/deletes;
 * `apply()` performs them through injectable WordPress callables.
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
 * Meta writer.
 *
 * @since 1.26.0
 */
class Meta_Writer {

	/**
	 * API field => meta key (plan §4.9). The one map bulk + migrate use.
	 */
	const FIELDS = array(
		'seo_title'           => '_storedash_seo_title',
		'seo_description'     => '_storedash_seo_description',
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
	 * Max length of a text field value.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * Writers: fn( string $type, int $id, string $key, string $value ),
	 * fn( string $type, int $id, string $key ), fn( string $type, int $id ).
	 *
	 * @var callable[]
	 */
	private $ops;

	/**
	 * Constructor.
	 *
	 * @param array $ops Optional `update`, `delete`, `clean` callables (tests).
	 */
	public function __construct( array $ops = array() ) {
		$this->ops = array_merge(
			array(
				'update' => array( __CLASS__, 'wp_update' ),
				'delete' => array( __CLASS__, 'wp_delete' ),
				'clean'  => array( __CLASS__, 'wp_clean' ),
			),
			$ops
		);
	}

	/**
	 * Validate an API field payload.
	 *
	 * Pure. Empty string / null deletes the key; `robots` with every directive
	 * true deletes the key (absent = all true). Any invalid field rejects the
	 * whole payload so an item is never half-written.
	 *
	 * @param mixed $fields Field payload.
	 * @return array { set: array<key,string>, delete: string[], error: string|null }
	 */
	public static function prepare( $fields ): array {
		$out = array(
			'set'    => array(),
			'delete' => array(),
			'error'  => null,
		);

		if ( ! is_array( $fields ) || array() === $fields ) {
			$out['error'] = 'fields must be a non-empty object';
			return $out;
		}

		$unknown = array_diff( array_map( 'strval', array_keys( $fields ) ), array_keys( self::FIELDS ) );
		if ( $unknown ) {
			$out['error'] = 'unknown fields: ' . implode( ', ', $unknown );
			return $out;
		}

		foreach ( $fields as $field => $value ) {
			$key   = self::FIELDS[ $field ];
			$value = 'robots' === $field ? self::robots_value( $value ) : self::field_value( $field, $value );
			if ( is_array( $value ) ) {
				$out['error'] = $field . ': ' . $value['error'];
				return $out;
			}
			if ( '' === $value ) {
				$out['delete'][] = $key;
			} else {
				$out['set'][ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Normalize a text / URL field. Returns '' to delete, or array( error ).
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|array
	 */
	private static function field_value( string $field, $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( 'og_image' === $field && is_int( $value ) ) {
			return $value > 0 ? (string) $value : array( 'error' => 'attachment ID must be positive' );
		}
		if ( ! is_string( $value ) ) {
			return array( 'error' => 'must be a string or null' );
		}

		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > self::MAX_LENGTH ) {
			return array( 'error' => 'longer than ' . self::MAX_LENGTH . ' characters' );
		}

		if ( 'og_image' === $field && ctype_digit( $value ) ) {
			return (int) $value > 0 ? (string) (int) $value : array( 'error' => 'attachment ID must be positive' );
		}
		if ( 'canonical' === $field || 'og_image' === $field ) {
			$url = Settings::http_url( $value );
			return '' === $url ? array( 'error' => 'must be an absolute http(s) URL' ) : $url;
		}
		return $value;
	}

	/**
	 * Robots object → §4.9 JSON string, '' (all true / null = delete) or array( error ).
	 *
	 * Missing directives default to true.
	 *
	 * @param mixed $value Raw value.
	 * @return string|array
	 */
	public static function robots_value( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_array( $value ) ) {
			return array( 'error' => 'must be an object of booleans' );
		}
		$unknown = array_diff( array_map( 'strval', array_keys( $value ) ), Meta_Reader::ROBOTS );
		if ( $unknown ) {
			return array( 'error' => 'unknown directives: ' . implode( ', ', $unknown ) );
		}

		$robots = array();
		foreach ( Meta_Reader::ROBOTS as $directive ) {
			$v = array_key_exists( $directive, $value ) ? $value[ $directive ] : true;
			if ( ! is_bool( $v ) ) {
				return array( 'error' => $directive . ' must be a boolean' );
			}
			$robots[ $directive ] = $v;
		}

		return in_array( false, $robots, true ) ? (string) wp_json_encode( $robots ) : '';
	}

	/**
	 * Apply a prepared payload to one object.
	 *
	 * @param string $type     'post' | 'term'.
	 * @param int    $id       Object ID.
	 * @param array  $prepared Result of prepare() (without error).
	 * @return array { written: string[], deleted: string[] }
	 */
	public function apply( string $type, int $id, array $prepared ): array {
		$written = array();
		$deleted = array();

		foreach ( $prepared['set'] as $key => $value ) {
			call_user_func( $this->ops['update'], $type, $id, $key, $value );
			$written[] = $key;
		}
		foreach ( $prepared['delete'] as $key ) {
			call_user_func( $this->ops['delete'], $type, $id, $key );
			$deleted[] = $key;
		}

		if ( $written || $deleted ) {
			call_user_func( $this->ops['clean'], $type, $id );
		}

		return array(
			'written' => $written,
			'deleted' => $deleted,
		);
	}

	/**
	 * WordPress update (values are slashed because update_metadata() unslashes).
	 *
	 * @param string $type  'post' | 'term'.
	 * @param int    $id    Object ID.
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 */
	public static function wp_update( string $type, int $id, string $key, string $value ): void {
		if ( 'term' === $type ) {
			update_term_meta( $id, $key, wp_slash( $value ) );
			return;
		}
		update_post_meta( $id, $key, wp_slash( $value ) );
	}

	/**
	 * WordPress delete.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object ID.
	 * @param string $key  Meta key.
	 */
	public static function wp_delete( string $type, int $id, string $key ): void {
		if ( 'term' === $type ) {
			delete_term_meta( $id, $key );
			return;
		}
		delete_post_meta( $id, $key );
	}

	/**
	 * Object cache clean, once per written object.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object ID.
	 */
	public static function wp_clean( string $type, int $id ): void {
		if ( 'term' === $type ) {
			clean_term_cache( $id );
			return;
		}
		clean_post_cache( $id );
	}
}
