<?php
/**
 * Shared WordPress stubs + class loading for the Seo suites.
 *
 * @package StoreDash\Tests\Seo
 */

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

// Option store for the settings controller suites.
$GLOBALS['__seo_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__seo_options'] ) ? $GLOBALS['__seo_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__seo_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return '6.8';
	}
}

$storedash_seo_root = __DIR__ . '/../../inc/Seo/';
require_once $storedash_seo_root . 'Settings.php';
require_once $storedash_seo_root . 'Engine/Title_Template.php';
require_once $storedash_seo_root . 'Engine/Meta_Reader.php';
require_once $storedash_seo_root . 'Engine/Plugin_Conflict_Guard.php';
require_once $storedash_seo_root . 'Engine/Head_Renderer.php';
require_once $storedash_seo_root . 'Engine/Meta_Writer.php';
require_once $storedash_seo_root . 'Engine/Migration_Map.php';
require_once $storedash_seo_root . 'Controllers/Abstract_Seo_Controller.php';
require_once $storedash_seo_root . 'Controllers/Seo_Settings_Controller.php';
require_once $storedash_seo_root . 'Controllers/Seo_Bulk_Controller.php';
require_once $storedash_seo_root . 'Controllers/Seo_Migrate_Controller.php';

/**
 * Minimal WP_REST_Request double: a JSON body.
 */
class Seo_Test_Request {
	/** @var string */
	private $body;

	public function __construct( array $body ) {
		$this->body = json_encode( $body );
	}

	public function get_body() {
		return $this->body;
	}

	public function get_params() {
		return array();
	}
}

/**
 * Recording Meta_Writer ops.
 */
function seo_recording_writer( array &$log ): \StoreDash\Seo\Engine\Meta_Writer {
	return new \StoreDash\Seo\Engine\Meta_Writer(
		array(
			'update' => static function ( $type, $id, $key, $value ) use ( &$log ) {
				$log[] = array( 'update', $type, $id, $key, $value );
			},
			'delete' => static function ( $type, $id, $key ) use ( &$log ) {
				$log[] = array( 'delete', $type, $id, $key );
			},
			'clean'  => static function ( $type, $id ) use ( &$log ) {
				$log[] = array( 'clean', $type, $id );
			},
		)
	);
}
