<?php
/**
 * WordPress stubs + class loading for the Swatches suites.
 *
 * Term meta lives in `$GLOBALS['__swatch_meta'][ term_id ][ key ]`; every
 * write is also appended to `$GLOBALS['__swatch_meta_log']` so tests can
 * assert exact meta operations. Attachments are `$GLOBALS['__swatch_attachments']`
 * (id => full URL).
 *
 * @package StoreDash\Tests\Products\Swatches
 */

$GLOBALS['__swatch_meta']        = array();
$GLOBALS['__swatch_meta_log']    = array();
$GLOBALS['__swatch_attachments'] = array();

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		return $GLOBALS['__swatch_meta'][ (int) $term_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['__swatch_meta'][ (int) $term_id ][ $key ] = $value;
		$GLOBALS['__swatch_meta_log'][]                      = array( 'update', (int) $term_id, $key, $value );
		return true;
	}
}

if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		unset( $GLOBALS['__swatch_meta'][ (int) $term_id ][ $key ] );
		$GLOBALS['__swatch_meta_log'][] = array( 'delete', (int) $term_id, $key );
		return true;
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	// Same rule as WordPress core.
	function sanitize_hex_color( $color ) {
		if ( '' === $color ) {
			return '';
		}
		if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
			return $color;
		}
		return null;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : $value;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
		$url = $GLOBALS['__swatch_attachments'][ (int) $id ] ?? null;
		return null === $url ? false : preg_replace( '/(\.[a-z]+)$/', '-150x150$1', $url );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) {
		return $GLOBALS['__swatch_attachments'][ (int) $id ] ?? false;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( $url ) {
		$id = array_search( $url, $GLOBALS['__swatch_attachments'], true );
		return false === $id ? 0 : (int) $id;
	}
}

require_once __DIR__ . '/../../../inc/Products/Swatches/Swatch_Provider_Interface.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Abstract_Provider.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Emran_Provider.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Cartflows_Provider.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Provider_Resolver.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Swatch_Rest_Field.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Swatches_Controller.php';
require_once __DIR__ . '/../../../inc/Products/Swatches/Store_API_Extension.php';

/**
 * Shared fixtures.
 */
final class Swatch_Test_Fixtures {

	public static function reset(): void {
		$GLOBALS['__swatch_meta']        = array();
		$GLOBALS['__swatch_meta_log']    = array();
		$GLOBALS['__swatch_attachments'] = array();
		$GLOBALS['__seo_options']        = array();
	}

	public static function emran( bool $active = true ): \StoreDash\Products\Swatches\Emran_Provider {
		return new \StoreDash\Products\Swatches\Emran_Provider(
			static function () use ( $active ) {
				return $active ? '2.6.0' : null;
			}
		);
	}

	public static function cartflows( bool $active = true ): \StoreDash\Products\Swatches\Cartflows_Provider {
		return new \StoreDash\Products\Swatches\Cartflows_Provider(
			static function () use ( $active ) {
				return $active ? '1.0.14' : null;
			}
		);
	}

	public static function resolver( bool $emran, bool $cartflows ): \StoreDash\Products\Swatches\Provider_Resolver {
		return new \StoreDash\Products\Swatches\Provider_Resolver( array( self::emran( $emran ), self::cartflows( $cartflows ) ) );
	}
}
