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

// Option store + get/update/add/delete_option + current_time: shared, in
// tests/bootstrap.php (`$GLOBALS['__seo_options']`).

// Transient store (B2 sitemap cache + redirect hits).
$GLOBALS['__seo_transients']     = array();
$GLOBALS['__seo_cron']           = array();

// WP-Cron (B5 hourly flush; Seo_Manager::init() self-heals the schedule).
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return $GLOBALS['__seo_cron'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		$GLOBALS['__seo_cron'][ $hook ] = (int) $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		$had = isset( $GLOBALS['__seo_cron'][ $hook ] ) ? 1 : 0;
		unset( $GLOBALS['__seo_cron'][ $hook ] );
		return $had;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['__seo_transients'] ) ? $GLOBALS['__seo_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__seo_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__seo_transients'][ $key ] );
		return true;
	}
}

// Hook + action stubs. Other suites define zero-arg no-ops for add_action /
// add_filter, so B2 tests never assert on hook registration; `do_action` is
// recorded (Settings::reset_cache() fires Settings::RESET_ACTION).
$GLOBALS['__seo_actions'] = array();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['__seo_actions'][] = $hook;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook = '', $callback = null, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook = '', $callback = null, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return (int) ( $GLOBALS['__seo_did_action'][ $hook ] ?? 0 );
	}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return false;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://shop.test' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

// Request plumbing for Sitemap_Generator::maybe_render() tests.
$GLOBALS['__seo_query_vars'] = array();
$GLOBALS['__seo_status']     = array();

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $var, $default = '' ) {
		return array_key_exists( $var, $GLOBALS['__seo_query_vars'] ) ? $GLOBALS['__seo_query_vars'][ $var ] : $default;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {
		$GLOBALS['__seo_status'][] = (int) $code;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
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

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://shop.test' . $path;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://shop.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return 'https://shop.test';
	}
}

// Review fixes (2026-10-19): 404 flag for the bot logger, redirect bypass.
$GLOBALS['__seo_is_404'] = false;

if ( ! function_exists( 'is_404' ) ) {
	function is_404() {
		return ! empty( $GLOBALS['__seo_is_404'] );
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
// B5.
require_once $storedash_seo_root . 'Engine/Schema_Builder.php';
require_once $storedash_seo_root . 'Engine/Schema_Output.php';
require_once $storedash_seo_root . 'Engine/Bot_Crawl_Logger.php';
require_once $storedash_seo_root . 'Engine/Robots_Txt.php';
require_once $storedash_seo_root . 'Controllers/Seo_Catalog_Controller.php';
// B2.
require_once $storedash_seo_root . 'Engine/Sitemap_Generator.php';
require_once $storedash_seo_root . 'Engine/Redirect_Manager.php';
require_once $storedash_seo_root . 'Controllers/Seo_Redirects_Controller.php';
require_once $storedash_seo_root . 'Seo_Manager.php';

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

/**
 * Manager over fixtures: permalinks, post types / statuses, trash targets,
 * WC page paths; `respond()` records instead of exiting.
 */
class Seo_Test_Redirects extends \StoreDash\Seo\Engine\Redirect_Manager {
	/** @var array<int,string> post ID => permalink */
	public $permalinks = array();
	/** @var array<int,string> */
	public $types = array();
	/** @var array<int,string> */
	public $statuses = array();
	/** @var array<int,string> */
	public $trash_targets = array();
	/** @var string[] */
	public $wc_paths = array( '/cart', '/checkout', '/my-account' );
	/** @var array[] */
	public $responses = array();
	/** @var bool user_can_bypass() result. */
	public $can_bypass = false;

	public function __construct() {
		parent::__construct( 'https://shop.test' );
	}

	protected function user_can_bypass(): bool {
		return $this->can_bypass;
	}

	protected function permalink( $post ): string {
		if ( is_object( $post ) ) {
			return 'https://shop.test/' . $post->post_type . '/' . $post->post_name . '/';
		}
		return $this->permalinks[ (int) $post ] ?? '';
	}

	protected function post_type( int $post_id ): string {
		return $this->types[ $post_id ] ?? '';
	}

	protected function post_status( int $post_id ): string {
		return $this->statuses[ $post_id ] ?? '';
	}

	protected function trash_target( int $post_id ): string {
		return $this->trash_targets[ $post_id ] ?? '';
	}

	protected function wc_page_paths(): array {
		return $this->wc_paths;
	}

	protected function respond( int $status, string $target ): void {
		$this->responses[] = array( $status, $target );
	}
}
