<?php
/**
 * Title template renderer.
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure title template engine.
 *
 * Variables: {product} {store} {category} {price} {sku} {sep} {page} {brand}.
 * Unknown variables render empty; separators left dangling by empty variables
 * collapse ("A | | B" → "A | B") and leading/trailing separators are trimmed.
 *
 * @since 1.26.0
 */
class Title_Template {

	/**
	 * Supported variables.
	 */
	const VARIABLES = array( 'product', 'store', 'category', 'price', 'sku', 'sep', 'page', 'brand' );

	/**
	 * Internal marker for {sep} so a separator character inside a product name is
	 * never collapsed.
	 */
	const SEP_MARK = "\x1F";

	/**
	 * Render a template.
	 *
	 * @param string $template Template string.
	 * @param array  $vars     Variable values (plain text) keyed by name; `sep` is the separator.
	 * @return string
	 */
	public static function render( string $template, array $vars ): string {
		$sep = isset( $vars['sep'] ) ? trim( (string) $vars['sep'] ) : '';

		$out = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $vars ) {
				if ( 'sep' === $m[1] ) {
					return self::SEP_MARK;
				}
				if ( ! in_array( $m[1], self::VARIABLES, true ) || ! isset( $vars[ $m[1] ] ) ) {
					return '';
				}
				return trim( str_replace( self::SEP_MARK, '', wp_strip_all_tags( (string) $vars[ $m[1] ] ) ) );
			},
			$template
		);
		$out = (string) $out;

		$mark = preg_quote( self::SEP_MARK, '/' );
		// Collapse runs of separators (with any whitespace between) into one.
		$out = (string) preg_replace( '/\s*' . $mark . '(?:\s*' . $mark . ')*\s*/u', self::SEP_MARK, $out );
		// Trim separators at either end.
		$out = (string) preg_replace( '/^\s*' . $mark . '|' . $mark . '\s*$/u', '', trim( $out ) );
		$out = str_replace( self::SEP_MARK, '' === $sep ? ' ' : ' ' . $sep . ' ', $out );

		return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
	}

	/**
	 * Supported variable names used in a template (to compute only what is needed).
	 *
	 * @param string $template Template string.
	 * @return string[]
	 */
	public static function placeholders( string $template ): array {
		if ( ! preg_match_all( '/\{([a-z_]+)\}/', $template, $m ) ) {
			return array();
		}
		return array_values( array_intersect( array_unique( $m[1] ), self::VARIABLES ) );
	}

	/**
	 * `wc_price()` HTML → plain text ("1.990&nbsp;kr." → "1.990 kr.").
	 *
	 * @param string $html Price HTML.
	 * @return string
	 */
	public static function price_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
