<?php
/**
 * Native SEO module coordinator.
 *
 * Constructed by StoreDash_API on every request (like Credit_Manager). `init()`
 * always exposes the conflict filter (read by B3's status endpoint), then
 * registers front-end hooks only when Storedash switched the module on
 * (`storedash_seo_enabled`) AND no other SEO plugin is active (plan §6.4).
 *
 * REST routes (B3) register on every request via Route_Registry. B2's sitemap
 * and redirects boot whenever the module is on (`is_module_on()`, also in
 * admin / AJAX / cron so product saves bust the cache and create auto
 * redirects). B5's bot-crawl cron + cache busting run on every request; the
 * head renderer, JSON-LD graph, bot front hooks and robots.txt only on
 * front-end requests (`should_render()`).
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Controllers\Seo_Catalog_Controller;
use StoreDash\Seo\Engine\Bot_Crawl_Logger;
use StoreDash\Seo\Engine\Head_Renderer;
use StoreDash\Seo\Engine\Meta_Reader;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;
use StoreDash\Seo\Engine\Redirect_Manager;
use StoreDash\Seo\Engine\Robots_Txt;
use StoreDash\Seo\Engine\Schema_Output;
use StoreDash\Seo\Engine\Sitemap_Generator;

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
	 * Sitemap (null when the module is off on this request).
	 *
	 * @var Sitemap_Generator|null
	 */
	protected $sitemap = null;

	/**
	 * Redirects (null when the module is off on this request).
	 *
	 * @var Redirect_Manager|null
	 */
	protected $redirects = null;

	/**
	 * Bot crawl logger (always constructed: owns the cron handler).
	 *
	 * @var Bot_Crawl_Logger|null
	 */
	protected $bot_logger = null;

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

		$module_on = $this->is_module_on();
		// One soft rewrite flush per on/off transition (sitemap rules), never per request.
		Sitemap_Generator::sync_rewrite_rules( $module_on );

		// B5 plumbing on every request (no output): the hourly crawl flush
		// handler + schedule self-heal (follows storedash_seo_enabled), and the
		// cache busting for /llms.txt (settings push) and catalog.txt (product writes).
		$this->bot_logger = new Bot_Crawl_Logger();
		$this->bot_logger->register_cron();
		Robots_Txt::register_cache_busting();
		Seo_Catalog_Controller::register_cache_busting();

		// B2: sitemap + redirects (+ their admin-side write hooks) whenever the module is on.
		if ( $module_on ) {
			$this->sitemap = new Sitemap_Generator( Settings::get() );
			$this->sitemap->register_hooks();
			$this->redirects = new Redirect_Manager();
			$this->redirects->register_hooks();
		}

		if ( $this->should_render() ) {
			$settings       = Settings::get();
			$this->renderer = new Head_Renderer( new Meta_Reader(), $settings );
			$this->renderer->register_hooks();
			// Order matters: the graph prints after the meta tags (same wp_head priority).
			( new Schema_Output( $this->renderer, $settings, 'normal' ) )->register_hooks();
			$this->bot_logger->register_front_hooks();
			( new Robots_Txt( $settings ) )->register_hooks();
			return;
		}

		// Plan §6.4: schema override is the one opt-in exception to "render nothing
		// while another SEO plugin is active" — Yoast only, Product pieces only.
		if ( Schema_Output::override_active( $this->guard->conflict() ) ) {
			$renderer = new Head_Renderer( new Meta_Reader(), Settings::get() ); // Context source only; no head hooks.
			( new Schema_Output( $renderer, Settings::get(), 'override' ) )->register_hooks();
		}
	}

	/**
	 * `rest_api_init` callback.
	 */
	public function register_routes(): void {
		( new Route_Registry( $this->guard ) )->register_routes( 'storedash/v1' );
	}

	/**
	 * Module enabled by Storedash and no other SEO plugin active (any request
	 * type). Gate for the sitemap, redirects and their write hooks.
	 *
	 * @return bool
	 */
	public function is_module_on(): bool {
		if ( ! Settings::is_enabled() ) {
			return false;
		}
		return null === $this->guard->conflict();
	}

	/**
	 * Front-end request, module enabled, no other SEO plugin. Gate for the
	 * head renderer.
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		return $this->is_module_on();
	}

	/**
	 * Sitemap generator booted on this request, or null.
	 *
	 * @return Sitemap_Generator|null
	 */
	public function get_sitemap(): ?Sitemap_Generator {
		return $this->sitemap;
	}

	/**
	 * Redirect manager booted on this request, or null.
	 *
	 * @return Redirect_Manager|null
	 */
	public function get_redirects(): ?Redirect_Manager {
		return $this->redirects;
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
