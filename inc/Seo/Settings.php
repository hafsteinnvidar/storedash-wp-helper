<?php
/**
 * Native SEO settings.
 *
 * Store-level SEO settings (title templates, noindex rules, default OG image …)
 * are owned by Storedash (`seo_store_settings`) and will be pushed here by B3
 * (`PUT /storedash/v1/seo/settings`). Until then the defaults in code apply.
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed accessor over the `storedash_seo_settings` option.
 *
 * @since 1.26.0
 */
class Settings {

	/**
	 * Option holding the settings array (pushed by Storedash).
	 */
	const OPTION = 'storedash_seo_settings';

	/**
	 * Option Storedash sets to switch the native SEO module on. Default off.
	 */
	const ENABLED_OPTION = 'storedash_seo_enabled';

	/**
	 * Per-request cache of the normalized settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Code defaults (used until B3 pushes real values).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'title_template_product'  => '{product} {sep} {store}',
			'title_template_category' => '{category} {sep} {store}',
			'title_template_page'     => '{page} {sep} {store}',
			'title_separator'         => '|',
			'default_og_image_url'    => '',
			'noindex_tags'            => false,
			'noindex_attributes'      => false,
			'noindex_search'          => true,
			'noindex_paginated'       => false,
			// Sorted / layered-nav filtered archive URLs (?orderby, ?filter_*, ?min_price …).
			'noindex_filtered'        => true,
			'breadcrumbs_enabled'     => true,
			'schema_org'              => array(),
			'ai_bots'                 => array(),
			'llms_txt_enabled'        => false,
		);
	}

	/**
	 * Normalize a raw settings payload into the canonical shape.
	 *
	 * Pure function. Unknown keys are dropped; wrong types fall back to defaults.
	 *
	 * @param array $raw Raw settings (option value or push payload).
	 * @return array
	 */
	public static function normalize( array $raw ): array {
		$out = self::defaults();

		foreach ( array( 'title_template_product', 'title_template_category', 'title_template_page', 'title_separator' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$out[ $key ] = trim( wp_strip_all_tags( (string) $raw[ $key ] ) );
			}
		}

		if ( isset( $raw['default_og_image_url'] ) && is_string( $raw['default_og_image_url'] ) ) {
			$out['default_og_image_url'] = self::http_url( $raw['default_og_image_url'] );
		}

		foreach ( array( 'noindex_tags', 'noindex_attributes', 'noindex_search', 'noindex_paginated', 'noindex_filtered', 'breadcrumbs_enabled', 'llms_txt_enabled' ) as $flag ) {
			if ( array_key_exists( $flag, $raw ) ) {
				$out[ $flag ] = self::to_bool( $raw[ $flag ] );
			}
		}

		foreach ( array( 'schema_org', 'ai_bots' ) as $list ) {
			if ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ) {
				$out[ $list ] = $raw[ $list ];
			}
		}

		return $out;
	}

	/**
	 * Current settings (normalized, read once per request).
	 *
	 * @return array
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::normalize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Whether Storedash has switched the native SEO module on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return self::to_bool( get_option( self::ENABLED_OPTION, false ) );
	}

	/**
	 * Drop the per-request cache (after a settings push, and in tests).
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Absolute http(s) URL (trimmed, as given) or ''.
	 *
	 * Shared validator for `default_og_image_url`, `canonical` and `og_image`
	 * (Settings, Meta_Reader, Meta_Writer). See is_absolute_url().
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	public static function http_url( string $url ): string {
		$url = trim( $url );
		return self::is_absolute_url( $url ) ? $url : '';
	}

	/**
	 * Is this an absolute http(s) URL with a host?
	 *
	 * `FILTER_VALIDATE_URL` rejects non-ASCII bytes, so IRIs such as
	 * `https://verslun.is/vörur/þráðlaus/` are percent-encoded (path, query,
	 * fragment only; the host must already be ASCII / punycode) before
	 * validation. The caller keeps the original string.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_absolute_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || ! preg_match( '#^https?://[^/?\#]+#i', $url ) ) {
			return false;
		}
		$encoded = (string) preg_replace_callback(
			'/[\x80-\xFF]/',
			static function ( $m ) {
				return '%' . strtoupper( bin2hex( $m[0] ) );
			},
			$url
		);
		return false !== filter_var( $encoded, FILTER_VALIDATE_URL );
	}

	/**
	 * Loose boolean coercion for JSON / option values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}
