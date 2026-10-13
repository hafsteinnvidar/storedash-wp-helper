<?php
/**
 * StoreDash REST route resolver.
 *
 * Answers one question for the request-scoped authenticators: "which REST
 * route will WordPress dispatch for this request?" — using the same inputs,
 * in the same order, that WP::parse_request() and WP_REST_Server use. The
 * raw REQUEST_URI string is never searched as a whole: a query string such
 * as `?x=/wp-json/storedash/` must not make `/wp-json/wp/v2/users` look like
 * a Storedash route (security audit 2026-10-13 #8 / #42).
 *
 * @package StoreDash
 * @since 1.24.1
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StoreDash_REST_Route {

	/**
	 * The REST route WordPress resolves for the current request, e.g.
	 * `/storedash/v1/status`, or '' when the request is not a REST request.
	 *
	 * Resolution order mirrors WordPress:
	 *  1. `$wp->query_vars['rest_route']` once parse_request has run (this is
	 *     exactly what rest_api_loaded() hands to the REST server).
	 *  2. `?rest_route=` from the query string — WP::parse_request() lets a
	 *     public query var in $_GET win over the rewrite match, so this is
	 *     the route even when the path also carries a /wp-json/ prefix.
	 *  3. The request PATH after the REST prefix (pretty permalinks). The
	 *     query string is parsed off first and never consulted.
	 *
	 * No decoding or `..` normalisation is applied: WP_REST_Server matches
	 * handlers against the route string as-is, so a caller who obscures the
	 * namespace with %2F or `..` simply fails to look like ours (fail closed).
	 *
	 * @return string Route with a leading slash, or '' if not a REST request.
	 */
	public static function current(): string {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$resolved = $GLOBALS['wp']->query_vars['rest_route'];
			if ( is_string( $resolved ) && '' !== $resolved ) {
				return self::normalize( $resolved );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route detection, not a form submission.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) && '' !== $_GET['rest_route'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against fixed namespace prefixes only, never echoed or stored.
			return self::normalize( wp_unslash( $_GET['rest_route'] ) );
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the path component is read, and only compared against fixed prefixes.
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$pos    = strpos( $path, $prefix );
		if ( false === $pos ) {
			return '';
		}

		return self::normalize( substr( $path, $pos + strlen( $prefix ) ) );
	}

	/**
	 * Whether a route sits inside one of the given namespaces.
	 *
	 * @param string   $route      Route as returned by current() or
	 *                             WP_REST_Request::get_route().
	 * @param string[] $namespaces Namespaces without slashes, e.g. ['storedash', 'woodash'].
	 * @return bool
	 */
	public static function in_namespaces( string $route, array $namespaces ): bool {
		$route = self::normalize( $route );
		foreach ( $namespaces as $ns ) {
			if ( 0 === strpos( $route, '/' . trim( $ns, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Leading slash, nothing else.
	 *
	 * @param string $route Raw route.
	 * @return string
	 */
	private static function normalize( string $route ): string {
		return '/' . ltrim( $route, '/' );
	}
}
