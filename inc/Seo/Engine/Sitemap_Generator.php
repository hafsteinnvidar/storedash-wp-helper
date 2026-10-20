<?php
/**
 * XML sitemap: `/sitemap.xml` index + `/sitemap-<type>-<n>.xml` pages.
 *
 * Bespoke (not WP core's `WP_Sitemaps` provider framework): core serves its
 * files at `/wp-sitemap-*.xml`, 2,000 URLs per page, and its renderer only
 * prints flat `<url>` children, so image entries (`<image:image>`) and the
 * `/sitemap.xml` URL would both need the renderer and the rewrite layer
 * replaced anyway. Rewrite rules + two query vars + `template_redirect` is
 * fewer moving parts. When this module is active core's sitemap is switched
 * off (`wp_sitemaps_enabled` → false) so a store has exactly one sitemap.
 *
 * Split like Head_Renderer: WP access goes through protected methods
 * (`count_posts()`, `fetch_post_ids()`, `post_records()`, `count_terms()`,
 * `term_records()`, …) that tests override; everything else is pure.
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
 * Sitemap generator.
 *
 * @since 1.27.0
 */
class Sitemap_Generator {

	/**
	 * Query var carrying the sitemap type (`index`, `products`, `product_cat`, …).
	 */
	const QUERY_VAR = 'storedash_sitemap';

	/**
	 * Query var carrying the 1-based page number.
	 */
	const PAGE_VAR = 'storedash_sitemap_page';

	/**
	 * URLs per sitemap page.
	 */
	const PER_PAGE = 1000;

	/**
	 * Max `<image:image>` entries per product (main image + gallery).
	 */
	const MAX_IMAGES = 10;

	/**
	 * Transient lifetime: 12 hours.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Transient key prefix (`storedash_sitemap_<type>_<n>`, `storedash_sitemap_index`).
	 */
	const TRANSIENT_PREFIX = 'storedash_sitemap_';

	/**
	 * Option (not autoloaded) tracking the transient keys currently set, so
	 * busting is a list of `delete_transient()` calls, never a `LIKE` delete.
	 */
	const KEYS_OPTION = 'storedash_sitemap_keys';

	/**
	 * Rewrite rule for the index. Also the key looked up in the stored
	 * `rewrite_rules` option to detect whether a flush is pending.
	 */
	const INDEX_RULE = '^sitemap\.xml$';

	/**
	 * Rewrite rule for pages.
	 */
	const PAGE_RULE = '^sitemap-([a-z_]+)-([0-9]+)\.xml$';

	/**
	 * Sitemap protocol namespace.
	 */
	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * Google image sitemap extension namespace.
	 */
	const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

	/**
	 * Sitemap type => post type.
	 */
	const POST_TYPES = array(
		'products' => 'product',
		'pages'    => 'page',
		'posts'    => 'post',
	);

	/**
	 * Taxonomy sitemaps (type slug = taxonomy).
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * WC catalog-visibility terms. A product is "hidden" (no public listing
	 * anywhere) only when it carries both; "search results only" products
	 * have just `exclude-from-catalog` and stay in the sitemap.
	 */
	const HIDDEN_TERM = 'exclude-from-catalog';

	/**
	 * Second WC visibility term (see HIDDEN_TERM).
	 */
	const HIDDEN_SEARCH_TERM = 'exclude-from-search';

	/**
	 * Whether register_hooks() ran on this request (read by the status route
	 * and by B5's robots.txt `Sitemap:` line).
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Normalized settings (Settings::get()).
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Normalized settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Is our sitemap serving on this request?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Clear the per-request active flag (tests; a deactivator could too).
	 */
	public static function reset_active(): void {
		self::$active = false;
	}

	/**
	 * Register hooks (only when the module is on and no other SEO plugin is
	 * active — Seo_Manager decides). Safe to call in admin / cron requests:
	 * the cache-bust hooks must see product saves made in wp-admin.
	 */
	public function register_hooks(): void {
		self::$active = true;

		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );

		// Cache busting.
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'bust_cache' ) );
		add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( Settings::RESET_ACTION, array( $this, 'bust_cache' ) );

		$this->register_rewrites();
	}

	/**
	 * Rewrite rules. Seo_Manager boots on `plugins_loaded`, before
	 * `$wp_rewrite` exists (WP creates it in `setup_theme`), so the rules are
	 * added on `init` (priority 0) unless `init` already ran. Rules are
	 * compiled lazily by WP_Rewrite, so `init` is early enough.
	 */
	public function register_rewrites(): void {
		if ( did_action( 'init' ) ) {
			$this->add_rewrite_rules();
			return;
		}
		add_action( 'init', array( $this, 'add_rewrite_rules' ), 0 );
	}

	/**
	 * `init` callback: register the two sitemap rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( self::INDEX_RULE, 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule(
			self::PAGE_RULE,
			'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::PAGE_VAR . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Flush the stored rewrite rules once per on/off transition.
	 *
	 * Reads the autoloaded `rewrite_rules` option and compares "our rule is
	 * stored" with "the module is on". Only on a mismatch a soft flush is
	 * scheduled for `wp_loaded` (after every plugin added its rules). Self-
	 * healing: a flush by someone else that drops our rule is repaired on the
	 * next request. No flag option needed.
	 *
	 * @param bool $active Module on (sitemap should be served).
	 * @return bool A flush was scheduled.
	 */
	public static function sync_rewrite_rules( bool $active ): bool {
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return false; // Plain permalinks: no rewrite rules exist at all.
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! $rules ) {
			return false; // Not generated yet: WP compiles them (with ours) on first use.
		}
		if ( isset( $rules[ self::INDEX_RULE ] ) === $active ) {
			return false;
		}
		add_action(
			'wp_loaded',
			static function () {
				flush_rewrite_rules( false );
			}
		);
		return true;
	}

	/**
	 * `query_vars` filter.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PAGE_VAR;
		return $vars;
	}

	/**
	 * `template_redirect` priority 0: serve the sitemap, or 404 for an unknown
	 * type / page out of range.
	 */
	public function maybe_render(): void {
		$type = (string) get_query_var( self::QUERY_VAR, '' );
		if ( '' === $type ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a fixed pattern only.
			if ( self::is_core_sitemap_request( $uri, (string) wp_parse_url( $this->home( '/' ), PHP_URL_PATH ) ) ) {
				$this->redirect_to_index();
			}
			return;
		}
		$page = (int) get_query_var( self::PAGE_VAR, 1 );
		$xml  = 'index' === $type ? $this->index_xml() : $this->page_xml( $type, $page );

		if ( null === $xml ) {
			global $wp_query;
			if ( isset( $wp_query ) && is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
			return;
		}

		$this->output( $xml );
	}

	/**
	 * Is this a request for WP core's sitemap (`/wp-sitemap.xml`,
	 * `/wp-sitemap-posts-post-1.xml`, …) relative to the home path? Core's
	 * sitemap is off while ours is on, so those URLs 301 to `/sitemap.xml`.
	 *
	 * @param string $request_uri REQUEST_URI.
	 * @param string $home_path   Path of home_url() ('' / '/' = root).
	 * @return bool
	 */
	public static function is_core_sitemap_request( string $request_uri, string $home_path ): bool {
		$path = strtok( $request_uri, '?#' );
		$path = false === $path ? '' : $path;
		$home = rtrim( $home_path, '/' );
		if ( '' !== $home ) {
			if ( 0 !== strpos( $path, $home . '/' ) ) {
				return false;
			}
			$path = substr( $path, strlen( $home ) );
		}
		return 1 === preg_match( '#^/wp-sitemap(-[a-z0-9_-]+)?\.xml$#i', $path );
	}

	/**
	 * 301 to the index and stop. Overridable for tests.
	 */
	protected function redirect_to_index(): void {
		wp_safe_redirect( $this->index_url(), 301, 'StoreDash' );
		exit;
	}

	/**
	 * Send the XML and stop. Overridable for tests.
	 *
	 * @param string $xml Document.
	 */
	protected function output( string $xml ): void {
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is XML-escaped in render_*().
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Types + index                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Sitemap types served on this store, in index order.
	 *
	 * `product_tag` only when `noindex_tags` is false; `product_brand` only
	 * when the taxonomy exists.
	 *
	 * @return string[]
	 */
	public function types(): array {
		$types = array( 'products', 'product_cat' );
		if ( empty( $this->settings['noindex_tags'] ) ) {
			$types[] = 'product_tag';
		}
		if ( $this->taxonomy_exists( 'product_brand' ) ) {
			$types[] = 'product_brand';
		}
		$types[] = 'pages';
		$types[] = 'posts';
		return $types;
	}

	/**
	 * Number of pages for a type (one count query).
	 *
	 * @param string $type Sitemap type.
	 * @return int
	 */
	public function page_count( string $type ): int {
		if ( isset( self::POST_TYPES[ $type ] ) ) {
			$count = $this->count_posts( self::POST_TYPES[ $type ] );
		} elseif ( in_array( $type, self::TAXONOMIES, true ) ) {
			$count = $this->count_terms( $type );
		} else {
			return 0;
		}
		return (int) ceil( max( 0, $count ) / self::PER_PAGE );
	}

	/**
	 * Index entries: one `{ loc }` per page of every served type.
	 *
	 * @return array[]
	 */
	public function index_entries(): array {
		$entries = array();
		foreach ( $this->types() as $type ) {
			$pages = $this->page_count( $type );
			for ( $n = 1; $n <= $pages; $n++ ) {
				$entries[] = array( 'loc' => $this->page_url( $type, $n ) );
			}
		}
		return $entries;
	}

	/**
	 * Absolute URL of a sitemap page.
	 *
	 * @param string $type Type.
	 * @param int    $n    Page (1-based).
	 * @return string
	 */
	public function page_url( string $type, int $n ): string {
		return $this->home( '/sitemap-' . $type . '-' . $n . '.xml' );
	}

	/**
	 * Absolute URL of the index.
	 *
	 * @return string
	 */
	public function index_url(): string {
		return $this->home( '/sitemap.xml' );
	}

	/* ------------------------------------------------------------------ */
	/* Page entries (pure over records)                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * URL entries for one page: `{ loc, lastmod?, images? }` each, after
	 * exclusions. Unknown type / page out of range → null.
	 *
	 * Post pages: one ID query (`fetch_post_ids`) + one priming pass
	 * (`post_records`) — no per-item queries. Term pages: one `get_terms`.
	 *
	 * @param string $type Sitemap type.
	 * @param int    $page Page (1-based).
	 * @return array[]|null
	 */
	public function page_entries( string $type, int $page ) {
		if ( $page < 1 || ! in_array( $type, $this->types(), true ) || $page > $this->page_count( $type ) ) {
			return null;
		}
		$offset = ( $page - 1 ) * self::PER_PAGE;

		if ( isset( self::POST_TYPES[ $type ] ) ) {
			$ids = array_map( 'intval', $this->fetch_post_ids( self::POST_TYPES[ $type ], $offset, self::PER_PAGE ) );
			if ( 'pages' === $type ) {
				// WC's cart / checkout / my-account are noindex (wc_page_noindex); listing them is a GSC error.
				$ids = array_values( array_diff( $ids, $this->noindex_page_ids() ) );
			}
			$records     = $ids ? $this->post_records( $ids ) : array();
			$with_images = 'products' === $type;
		} else {
			$records     = $this->term_records( $type, $offset, self::PER_PAGE );
			$with_images = false;
		}

		$entries = self::filter_records( $records, $with_images );
		if ( 'pages' === $type && 1 === $page ) {
			$entries = self::with_home_first( $entries, $this->home( '/' ) );
		}
		return $entries;
	}

	/**
	 * Pure: the home URL as the first entry (moved there when a static front
	 * page already listed it, added when the front page shows latest posts).
	 *
	 * @param array[] $entries Entries.
	 * @param string  $home    home_url( '/' ).
	 * @return array[]
	 */
	public static function with_home_first( array $entries, string $home ): array {
		$home_entry = array( 'loc' => $home );
		$rest       = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['loc'] ) && untrailingslashit( (string) $entry['loc'] ) === untrailingslashit( $home ) ) {
				$home_entry = $entry;
				continue;
			}
			$rest[] = $entry;
		}
		array_unshift( $rest, $home_entry );
		return $rest;
	}

	/**
	 * Apply the exclusion rules to raw records. Pure.
	 *
	 * Record: `{ loc, lastmod?: 'Y-m-d H:i:s' (GMT), password?: bool,
	 * hidden?: bool, index?: bool, canonical?: string, images?: string[] }`.
	 *
	 * Skipped: empty loc, password-protected, hidden from catalog, robots
	 * `index: false`, a canonical pointing at a different URL (duplicate).
	 *
	 * @param array[] $records     Raw records.
	 * @param bool    $with_images Keep image URLs (products only).
	 * @return array[]
	 */
	public static function filter_records( array $records, bool $with_images ): array {
		$out = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$loc = isset( $record['loc'] ) ? trim( (string) $record['loc'] ) : '';
			if ( '' === $loc ) {
				continue;
			}
			if ( ! empty( $record['password'] ) || ! empty( $record['hidden'] ) ) {
				continue;
			}
			if ( array_key_exists( 'index', $record ) && false === (bool) $record['index'] ) {
				continue;
			}
			$canonical = isset( $record['canonical'] ) ? trim( (string) $record['canonical'] ) : '';
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $loc ) ) {
				continue;
			}

			$entry = array( 'loc' => $loc );
			if ( ! empty( $record['lastmod'] ) ) {
				$lastmod = self::w3c_datetime( (string) $record['lastmod'] );
				if ( '' !== $lastmod ) {
					$entry['lastmod'] = $lastmod;
				}
			}
			if ( $with_images && ! empty( $record['images'] ) && is_array( $record['images'] ) ) {
				$images = array();
				foreach ( $record['images'] as $image ) {
					$image = trim( (string) $image );
					if ( '' !== $image && ! in_array( $image, $images, true ) ) {
						$images[] = $image;
					}
					if ( count( $images ) >= self::MAX_IMAGES ) {
						break;
					}
				}
				if ( $images ) {
					$entry['images'] = $images;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * MySQL GMT datetime → W3C datetime (`2026-10-19T12:00:00Z`). '' when
	 * unparsable or the zero date.
	 *
	 * @param string $mysql_gmt `post_modified_gmt`.
	 * @return string
	 */
	public static function w3c_datetime( string $mysql_gmt ): string {
		$mysql_gmt = trim( $mysql_gmt );
		if ( '' === $mysql_gmt || 0 === strpos( $mysql_gmt, '0000-00-00' ) ) {
			return '';
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/* ------------------------------------------------------------------ */
	/* XML (pure)                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Sitemap index document.
	 *
	 * @param array[] $entries `{ loc, lastmod? }` list.
	 * @return string
	 */
	public static function render_index( array $entries ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";
		foreach ( $entries as $entry ) {
			$xml .= '  <sitemap><loc>' . self::xml( $entry['loc'] ) . '</loc>';
			if ( ! empty( $entry['lastmod'] ) ) {
				$xml .= '<lastmod>' . self::xml( $entry['lastmod'] ) . '</lastmod>';
			}
			$xml .= '</sitemap>' . "\n";
		}
		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/**
	 * URL set document. The image namespace is declared only when an entry
	 * carries images.
	 *
	 * @param array[] $entries `{ loc, lastmod?, images? }` list.
	 * @return string
	 */
	public static function render_urlset( array $entries ): string {
		$has_images = false;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['images'] ) ) {
				$has_images = true;
				break;
			}
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( $has_images ) {
			$xml .= ' xmlns:image="' . self::NS_IMAGE . '"';
		}
		$xml .= '>' . "\n";

		foreach ( $entries as $entry ) {
			$xml .= '  <url>' . "\n";
			$xml .= '    <loc>' . self::xml( $entry['loc'] ) . '</loc>' . "\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				$xml .= '    <lastmod>' . self::xml( $entry['lastmod'] ) . '</lastmod>' . "\n";
			}
			if ( ! empty( $entry['images'] ) ) {
				foreach ( $entry['images'] as $image ) {
					$xml .= '    <image:image><image:loc>' . self::xml( $image ) . '</image:loc></image:image>' . "\n";
				}
			}
			$xml .= '  </url>' . "\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/**
	 * XML text escape (also drops control characters XML 1.0 forbids).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function xml( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		// ENT_SUBSTITUTE: invalid UTF-8 becomes U+FFFD instead of an empty string.
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/* ------------------------------------------------------------------ */
	/* Cache                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Index XML (transient `storedash_sitemap_index`).
	 *
	 * @return string
	 */
	public function index_xml(): string {
		$key = self::TRANSIENT_PREFIX . 'index';
		$xml = get_transient( $key );
		if ( is_string( $xml ) && '' !== $xml ) {
			return $xml;
		}
		$xml = self::render_index( $this->index_entries() );
		$this->remember( $key, $xml );
		return $xml;
	}

	/**
	 * Page XML (transient `storedash_sitemap_<type>_<n>`), null when the
	 * type / page does not exist. The range check (a count query) only runs
	 * on a cache miss.
	 *
	 * @param string $type Type.
	 * @param int    $page Page.
	 * @return string|null
	 */
	public function page_xml( string $type, int $page ) {
		if ( $page < 1 || ! preg_match( '/^[a-z_]+$/', $type ) ) {
			return null;
		}
		$key = self::TRANSIENT_PREFIX . $type . '_' . $page;
		$xml = get_transient( $key );
		if ( is_string( $xml ) && '' !== $xml ) {
			return $xml;
		}
		$entries = $this->page_entries( $type, $page );
		if ( null === $entries ) {
			return null;
		}
		$xml = self::render_urlset( $entries );
		$this->remember( $key, $xml );
		return $xml;
	}

	/**
	 * Store a transient and track its key.
	 *
	 * @param string $key Transient key.
	 * @param string $xml Document.
	 */
	protected function remember( string $key, string $xml ): void {
		set_transient( $key, $xml, self::CACHE_TTL );
		$keys = get_option( self::KEYS_OPTION, array() );
		$keys = is_array( $keys ) ? $keys : array();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			if ( false === get_option( self::KEYS_OPTION, false ) ) {
				add_option( self::KEYS_OPTION, $keys, '', false );
			} else {
				update_option( self::KEYS_OPTION, $keys, false );
			}
		}
	}

	/**
	 * Delete every tracked sitemap transient. Cheap when nothing is cached
	 * (one option read that WP caches as a miss for the rest of the request).
	 */
	public function bust_cache(): void {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				if ( is_string( $key ) && 0 === strpos( $key, self::TRANSIENT_PREFIX ) ) {
					delete_transient( $key );
				}
			}
		}
		if ( false !== get_option( self::KEYS_OPTION, false ) ) {
			delete_option( self::KEYS_OPTION );
		}
	}

	/**
	 * `save_post` / `deleted_post`: bust for products, pages, posts only
	 * (skips revisions, variations, attachments, …).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public function on_save_post( $post_id, $post = null ): void {
		$type = is_object( $post ) && isset( $post->post_type ) ? (string) $post->post_type : (string) get_post_type( (int) $post_id );
		if ( in_array( $type, self::POST_TYPES, true ) ) {
			$this->bust_cache();
		}
	}

	/**
	 * `created_term` / `edited_term` / `delete_term`: bust for our taxonomies.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		if ( in_array( (string) $taxonomy, self::TAXONOMIES, true ) ) {
			$this->bust_cache();
		}
	}

	/* ------------------------------------------------------------------ */
	/* WordPress access (overridden in tests)                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Absolute URL on this site.
	 *
	 * @param string $path Path starting with `/`.
	 * @return string
	 */
	protected function home( string $path = '' ): string {
		return (string) home_url( $path );
	}

	/**
	 * Page IDs that never belong in a sitemap: WooCommerce cart, checkout and
	 * my-account (WC prints noindex on them).
	 *
	 * @return int[]
	 */
	protected function noindex_page_ids(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Does a taxonomy exist?
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	protected function taxonomy_exists( string $taxonomy ): bool {
		return taxonomy_exists( $taxonomy );
	}

	/**
	 * Published count for a post type (`wp_count_posts`, cached by WP).
	 *
	 * Counts every published post; exclusions are applied per page, so pages
	 * are offsets over this same set and every URL lands on exactly one page.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	protected function count_posts( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Published post IDs for a page, ID ascending (one query, no found rows).
	 *
	 * @param string $post_type Post type.
	 * @param int    $offset    Offset.
	 * @param int    $limit     Count.
	 * @return int[]
	 */
	protected function fetch_post_ids( string $post_type, int $offset, int $limit ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Records for a list of post IDs from primed caches.
	 *
	 * Primes posts + terms + meta for the page in one pass, collects the
	 * attachment IDs (thumbnail + gallery, capped) and primes those in a
	 * second pass; every read after that is a cache hit.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[]
	 */
	protected function post_records( array $ids ): array {
		_prime_post_caches( $ids, true, true );

		$records        = array();
		$attachment_ids = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$meta   = get_post_meta( $id );
			$meta   = is_array( $meta ) ? $meta : array();
			$images = array();
			if ( 'product' === $post->post_type ) {
				$thumb = (int) Meta_Reader::first( $meta, '_thumbnail_id' );
				if ( $thumb > 0 ) {
					$images[] = $thumb;
				}
				$gallery = Meta_Reader::first( $meta, '_product_image_gallery' );
				foreach ( explode( ',', is_scalar( $gallery ) ? (string) $gallery : '' ) as $gid ) {
					$gid = (int) trim( $gid );
					if ( $gid > 0 && ! in_array( $gid, $images, true ) ) {
						$images[] = $gid;
					}
				}
				$images = array_slice( $images, 0, self::MAX_IMAGES );
				foreach ( $images as $aid ) {
					$attachment_ids[ $aid ] = true;
				}
			}

			$records[ $id ] = array(
				'loc'       => (string) get_permalink( $post ),
				'lastmod'   => (string) $post->post_modified_gmt,
				'password'  => '' !== (string) $post->post_password,
				'hidden'    => 'product' === $post->post_type && $this->is_hidden_product( $post ),
				'index'     => Meta_Reader::robots( Meta_Reader::first( $meta, Meta_Reader::KEYS['robots'] ) )['index'],
				'canonical' => Settings::http_url( (string) Meta_Reader::first( $meta, Meta_Reader::KEYS['canonical'] ) ),
				'images'    => $images,
			);
		}

		if ( $attachment_ids ) {
			$attachment_ids = array_map( 'intval', array_keys( $attachment_ids ) );
			_prime_post_caches( $attachment_ids, false, true );
			$urls = array();
			foreach ( $attachment_ids as $aid ) {
				$url          = wp_get_attachment_image_url( $aid, 'full' );
				$urls[ $aid ] = is_string( $url ) ? $url : '';
			}
			foreach ( $records as $id => $record ) {
				$records[ $id ]['images'] = array_values(
					array_filter(
						array_map(
							static function ( $aid ) use ( $urls ) {
								return isset( $urls[ $aid ] ) ? $urls[ $aid ] : '';
							},
							$record['images']
						)
					)
				);
			}
		}

		return array_values( $records );
	}

	/**
	 * Hidden from the catalog (`product_visibility` term, primed by
	 * `_prime_post_caches( …, true, … )`).
	 *
	 * @param \WP_Post $post Product post.
	 * @return bool
	 */
	protected function is_hidden_product( $post ): bool {
		$terms = get_the_terms( $post, 'product_visibility' );
		return self::is_hidden_visibility( is_array( $terms ) ? wp_list_pluck( $terms, 'slug' ) : array() );
	}

	/**
	 * Pure: WC visibility "hidden" = both exclude terms. "Search results
	 * only" (`exclude-from-catalog` alone) and "Shop only"
	 * (`exclude-from-search` alone) products still have public URLs.
	 *
	 * @param string[] $slugs `product_visibility` term slugs.
	 * @return bool
	 */
	public static function is_hidden_visibility( array $slugs ): bool {
		return in_array( self::HIDDEN_TERM, $slugs, true ) && in_array( self::HIDDEN_SEARCH_TERM, $slugs, true );
	}

	/**
	 * Non-empty term count for a taxonomy (one query).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	protected function count_terms( string $taxonomy ): int {
		if ( ! $this->taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Records for a taxonomy page (one `get_terms`, term meta primed).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $offset   Offset.
	 * @param int    $limit    Count.
	 * @return array[]
	 */
	protected function term_records( string $taxonomy, int $offset, int $limit ): array {
		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'hide_empty'             => true,
				'orderby'                => 'term_id',
				'order'                  => 'ASC',
				'number'                 => $limit,
				'offset'                 => $offset,
				'update_term_meta_cache' => true,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$records = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( ! is_string( $link ) ) {
				continue;
			}
			$meta      = get_term_meta( (int) $term->term_id );
			$meta      = is_array( $meta ) ? $meta : array();
			$records[] = array(
				'loc'       => $link,
				'index'     => Meta_Reader::robots( Meta_Reader::first( $meta, Meta_Reader::KEYS['robots'] ) )['index'],
				'canonical' => Settings::http_url( (string) Meta_Reader::first( $meta, Meta_Reader::KEYS['canonical'] ) ),
			);
		}
		return $records;
	}
}
