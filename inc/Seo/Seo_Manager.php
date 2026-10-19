<?php
/**
 * Native SEO module coordinator.
 *
 * Constructed by StoreDash_API on every request (like Credit_Manager). `init()`
 * always exposes the conflict filter (read by B3's status endpoint), then
 * registers front-end hooks only when Storedash switched the module on
 * (`storedash_seo_enabled`) AND no other SEO plugin is active (plan §6.4).
 *
 * REST routes (B3) register on every request via Route_Registry. Later steps
 * drop in here: sitemap and redirects (B2), JSON-LD + bot crawl log (B5).
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Head_Renderer;
use StoreDash\Seo\Engine\Meta_Reader;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;

/**
 * Module manager.
 *
 * @since 1.26.0
 */
class Seo_Manager {

	/**
	 * Filter exposing the detected conflicting SEO plugin slug (or null).
	 */
	const CONFLICT_FILTER = 'storedash_seo_conflict';

	/**
	 * Conflict guard.
	 *
	 * @var Plugin_Conflict_Guard
	 */
	protected $guard;

	/**
	 * Head renderer (null when not active on this request).
	 *
	 * @var Head_Renderer|null
	 */
	protected $renderer = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin_Conflict_Guard|null $guard Guard (injectable for tests).
	 */
	public function __construct( $guard = null ) {
		$this->guard = $guard instanceof Plugin_Conflict_Guard ? $guard : new Plugin_Conflict_Guard();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( self::CONFLICT_FILTER, array( $this, 'filter_conflict' ) );
		// Always: Storedash needs status + migrate while Yoast / RankMath is active.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( ! $this->should_render() ) {
			return;
		}

		$this->renderer = new Head_Renderer( new Meta_Reader(), Settings::get() );
		$this->renderer->register_hooks();
	}

	/**
	 * `rest_api_init` callback.
	 */
	public function register_routes(): void {
		( new Route_Registry( $this->guard ) )->register_routes( 'storedash/v1' );
	}

	/**
	 * Front-end request, module enabled, no other SEO plugin.
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ! Settings::is_enabled() ) {
			return false;
		}
		return null === $this->guard->conflict();
	}

	/**
	 * `storedash_seo_conflict` callback.
	 *
	 * @param mixed $value Ignored.
	 * @return string|null
	 */
	public function filter_conflict( $value = null ) {
		return $this->guard->conflict();
	}

	/**
	 * The guard (for B3's status endpoint `detected_plugins`).
	 *
	 * @return Plugin_Conflict_Guard
	 */
	public function get_guard(): Plugin_Conflict_Guard {
		return $this->guard;
	}
}
