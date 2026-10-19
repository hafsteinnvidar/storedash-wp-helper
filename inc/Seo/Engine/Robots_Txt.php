<?php
/**
 * robots.txt blocks + /llms.txt (plan §5 B5).
 *
 * - `robots_txt` filter (only when the module renders): per `settings.ai_bots`
 *   one `User-agent: <token>` / `Disallow: /` block for every bot set to
 *   `false`, plus `Sitemap: <home>/sitemap.xml` when B2's sitemap is active.
 * - `/llms.txt` (llmstxt.org format): `# <store>` / `> <description>` /
 *   `## Categories` (top-level product_cat with products, ≤ 50) / `## Catalog`
 *   linking `GET /storedash/v1/seo/catalog.txt`. Served from `template_redirect`
 *   by path match (no rewrite rules → nothing to flush, no collision with B2),
 *   cached 12 h in a transient that a settings PUT busts.
 *
 * @package StoreDash\Seo
 * @since   1.27.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Settings;

/**
 * robots.txt + llms.txt.
 *
 * @since 1.27.0
 */
class Robots_Txt {

	/**
	 * `ai_bots` slug => robots.txt User-agent token (vendor documented).
	 * `applebot` maps to `Applebot-Extended` (Apple's AI-training opt-out);
	 * blocking plain `Applebot` would also remove the store from Siri /
	 * Spotlight search. `google-extended` is likewise Google's AI-only token.
	 */
	const ROBOTS_TOKENS = array(
		'gptbot'             => 'GPTBot',
		'oai-searchbot'      => 'OAI-SearchBot',
		'chatgpt-user'       => 'ChatGPT-User',
		'claudebot'          => 'ClaudeBot',
		'claude-user'        => 'Claude-User',
		'perplexitybot'      => 'PerplexityBot',
		'perplexity-user'    => 'Perplexity-User',
		'google-extended'    => 'Google-Extended',
		'applebot'           => 'Applebot-Extended',
		'amazonbot'          => 'Amazonbot',
		'bytespider'         => 'Bytespider',
		'ccbot'              => 'CCBot',
		'meta-externalagent' => 'meta-externalagent',
	);

	/**
	 * llms.txt cache.
	 */
	const LLMS_TRANSIENT = 'storedash_seo_llms_txt';

	/**
	 * llms.txt cache TTL.
	 */
	const LLMS_TTL = 12 * 3600;

	/**
	 * Max categories listed.
	 */
	const MAX_CATEGORIES = 50;

	/**
	 * Normalized settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Sitemap URL resolver: fn(): string ('' = no sitemap line).
	 *
	 * @var callable
	 */
	private $sitemap_url;

	/**
	 * Constructor.
	 *
	 * @param array         $settings    Settings::get().
	 * @param callable|null $sitemap_url Sitemap URL resolver (default: B2 soft seam).
	 */
	public function __construct( array $settings, $sitemap_url = null ) {
		$this->settings    = $settings;
		$this->sitemap_url = $sitemap_url ? $sitemap_url : array( __CLASS__, 'b2_sitemap_url' );
	}

	/**
	 * Register hooks (only when Seo_Manager::should_render()).
	 */
	public function register_hooks(): void {
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );
		if ( ! empty( $this->settings['llms_txt_enabled'] ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve_llms_txt' ), 1 );
		}
	}

	/**
	 * Cache busting for a settings push (`update_option_storedash_seo_settings`).
	 * Registered on every request by Seo_Manager.
	 */
	public static function register_cache_busting(): void {
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'bust_cache' ) );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'bust_cache' ) );
	}

	/**
	 * Drop the llms.txt cache.
	 */
	public static function bust_cache(): void {
		delete_transient( self::LLMS_TRANSIENT );
	}

	/* ------------------------------------------------------------------ */
	/* robots.txt                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * `robots_txt` filter.
	 *
	 * @param string $output Output so far.
	 * @param bool   $public blog_public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public = true ) {
		$output = (string) $output;
		if ( ! $public ) {
			return $output; // Site asks not to be indexed; WP already said "Disallow: /".
		}
		$ai_bots = isset( $this->settings['ai_bots'] ) && is_array( $this->settings['ai_bots'] ) ? $this->settings['ai_bots'] : array();
		return $output . self::robots_blocks( $ai_bots, (string) call_user_func( $this->sitemap_url ) );
	}

	/**
	 * Pure: text appended to robots.txt.
	 *
	 * @param array  $ai_bots     `{ slug: bool }` (Settings::normalize_ai_bots()).
	 * @param string $sitemap_url '' = no Sitemap line.
	 * @return string
	 */
	public static function robots_blocks( array $ai_bots, string $sitemap_url ): string {
		$out = '';
		foreach ( self::ROBOTS_TOKENS as $slug => $token ) {
			if ( array_key_exists( $slug, $ai_bots ) && false === $ai_bots[ $slug ] ) {
				$out .= "\nUser-agent: " . $token . "\nDisallow: /\n";
			}
		}
		if ( '' !== $sitemap_url ) {
			$out .= "\nSitemap: " . $sitemap_url . "\n";
		}
		return $out;
	}

	/**
	 * B2 soft seam: `<home>/sitemap.xml` when `Sitemap_Generator` exists and
	 * (if it has one) its static `is_active()` returns true.
	 *
	 * @return string
	 */
	public static function b2_sitemap_url(): string {
		$class = __NAMESPACE__ . '\\Sitemap_Generator';
		if ( ! class_exists( $class ) ) {
			return '';
		}
		if ( method_exists( $class, 'is_active' ) ) {
			try {
				if ( ! call_user_func( array( $class, 'is_active' ) ) ) {
					return '';
				}
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		return home_url( '/sitemap.xml' );
	}

	/* ------------------------------------------------------------------ */
	/* llms.txt                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Is this request for `/llms.txt` (relative to the home path)?
	 *
	 * @param string $request_uri REQUEST_URI.
	 * @param string $home_path   Path part of home_url() ('' or '/shop').
	 * @return bool
	 */
	public static function is_llms_request( string $request_uri, string $home_path ): bool {
		$path = strtok( $request_uri, '?#' );
		$path = false === $path ? '' : rtrim( $path, '/' );
		$home = rtrim( $home_path, '/' );
		return strtolower( $path ) === strtolower( $home . '/llms.txt' );
	}

	/**
	 * `template_redirect`: serve llms.txt and exit.
	 */
	public function maybe_serve_llms_txt(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a constant path only.
		if ( ! self::is_llms_request( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ) {
			return;
		}
		$body = get_transient( self::LLMS_TRANSIENT );
		if ( ! is_string( $body ) || '' === $body ) {
			$body = self::llms_txt( $this->llms_data() );
			set_transient( self::LLMS_TRANSIENT, $body, self::LLMS_TTL );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built by llms_txt() from sanitized strings.
		exit;
	}

	/**
	 * Pure: llms.txt body (llmstxt.org: H1, blockquote, H2 sections of links).
	 *
	 * @param array $data { name, description, categories: [ { name, url, count } ], catalog_url, home_url }.
	 * @return string
	 */
	public static function llms_txt( array $data ): string {
		$name = self::line( isset( $data['name'] ) ? (string) $data['name'] : '' );
		$desc = self::line( isset( $data['description'] ) ? (string) $data['description'] : '' );
		$out  = '# ' . ( '' !== $name ? $name : 'Store' ) . "\n\n";
		if ( '' !== $desc ) {
			$out .= '> ' . $desc . "\n\n";
		}
		if ( ! empty( $data['home_url'] ) ) {
			$out .= 'Online store. Product pages, categories and prices are public; prices are in the store currency.' . "\n\n";
		}

		$categories = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
		if ( $categories ) {
			$out .= "## Categories\n\n";
			$n    = 0;
			foreach ( $categories as $cat ) {
				$cat_name = self::line( isset( $cat['name'] ) ? (string) $cat['name'] : '' );
				$cat_url  = isset( $cat['url'] ) ? trim( (string) $cat['url'] ) : '';
				if ( '' === $cat_name || '' === $cat_url ) {
					continue;
				}
				$count = isset( $cat['count'] ) ? (int) $cat['count'] : 0;
				$out  .= '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), $cat_name ) . '](' . $cat_url . ')' . ( $count > 0 ? ': ' . $count . ' products' : '' ) . "\n";
				if ( ++$n >= self::MAX_CATEGORIES ) {
					break;
				}
			}
			$out .= "\n";
		}

		if ( ! empty( $data['catalog_url'] ) ) {
			$out .= "## Catalog\n\n";
			$out .= '- [Product catalog (plain text, 500 per page, ?page=N)](' . trim( (string) $data['catalog_url'] ) . '): one product per line — name | price currency | availability | URL' . "\n";
		}
		return $out;
	}

	/**
	 * Live data for llms_txt().
	 *
	 * @return array
	 */
	private function llms_data(): array {
		$org  = isset( $this->settings['schema_org'] ) && is_array( $this->settings['schema_org'] ) ? $this->settings['schema_org'] : array();
		$desc = ! empty( $org['description'] ) ? (string) $org['description'] : wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );
		$name = ! empty( $org['name'] ) ? (string) $org['name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$categories = array();
		if ( taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => 0,
					'hide_empty' => true,
					'number'     => self::MAX_CATEGORIES,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( 'uncategorized' === $term->slug ) {
					continue;
				}
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$categories[] = array(
					'name'  => wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'url'   => (string) $link,
					'count' => (int) $term->count,
				);
			}
		}

		return array(
			'name'        => $name,
			'description' => $desc,
			'home_url'    => home_url( '/' ),
			'categories'  => $categories,
			'catalog_url' => rest_url( 'storedash/v1/seo/catalog.txt' ),
		);
	}

	/**
	 * One-line plain text.
	 *
	 * @param string $text Raw.
	 * @return string
	 */
	private static function line( string $text ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback outside WP.
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
