<?php
/**
 * Redirect engine: option-stored table pushed by Storedash, request matcher
 * on `template_redirect`, hit counter, and automatic redirects for slug
 * changes and trashed products.
 *
 * Storage: option `storedash_seo_redirects` (NOT autoloaded; ≤ 5,000 rows,
 * ~500 KB serialized — far under MySQL's `max_allowed_packet`, 16–64 MB by
 * default) keyed by normalised source path:
 *   `path => { target_url, status_code, id?, auto?, reason?, post_id? }`
 * plus the autoloaded boolean `storedash_seo_redirects_active` so a request
 * with no rows costs one in-memory option read and nothing else.
 *
 * WP access goes through protected methods (`rows()`, `save_rows()`,
 * `permalink()`, `trash_target()`, `respond()`, …) that tests override; the
 * normaliser, validator and matcher are pure statics.
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
 * Redirect manager.
 *
 * @since 1.27.0
 */
class Redirect_Manager {

	/**
	 * Option holding the table (autoload = no).
	 */
	const OPTION = 'storedash_seo_redirects';

	/**
	 * Autoloaded '1' / '0': does the table have rows?
	 */
	const ACTIVE_OPTION = 'storedash_seo_redirects_active';

	/**
	 * Opaque hash of the last PUT (same contract as settings).
	 */
	const HASH_OPTION = 'storedash_seo_redirects_hash';

	/**
	 * Transient: `{ since: iso, hits: { path: int } }`.
	 */
	const HITS_TRANSIENT = 'storedash_seo_redirect_hits';

	/**
	 * Hit transient lifetime: 48 h (B5 drains hourly).
	 */
	const HITS_TTL = 172800;

	/**
	 * Max rows in the table (and max keys in the hit transient).
	 */
	const MAX_ROWS = 5000;

	/**
	 * Max length of `source_path` / `target_url`.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * Allowed status codes.
	 */
	const STATUS_CODES = array( 301, 302, 307, 308, 410 );

	/**
	 * Post types that get automatic slug-change redirects.
	 */
	const POST_TYPES = array( 'product', 'page', 'post' );

	/**
	 * Request paths never redirected (prefix match on the normalised path).
	 * WooCommerce cart / checkout / account pages are added at runtime.
	 */
	const PROTECTED_PREFIXES = array(
		'/wp-admin',
		'/wp-json',
		'/wp-login.php',
		'/wp-cron.php',
		'/xmlrpc.php',
		'/wp-includes',
		'/wp-content',
	);

	/**
	 * Primary category meta (plan §4.9; not written by anything yet).
	 */
	const PRIMARY_CATEGORY_META = '_storedash_seo_primary_category';

	/**
	 * `home_url()` override (tests). Null = read lazily.
	 *
	 * @var string|null
	 */
	private $home;

	/**
	 * Per-request cache of the table.
	 *
	 * @var array|null
	 */
	private $rows_cache = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $home Site URL (`home_url()`), injectable for tests.
	 */
	public function __construct( $home = null ) {
		$this->home = is_string( $home ) ? $home : null;
	}

	/**
	 * Register hooks. Called whenever the module is on (also in admin, AJAX
	 * and cron requests: slug changes and trashing happen there).
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ), 10, 2 );
		add_action( 'untrashed_post', array( $this, 'on_untrash_post' ), 10, 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Normalisation + validation (pure)                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise a source path or URL to the table key.
	 *
	 * Host and scheme are dropped (paths only), the path is percent-decoded,
	 * gets a leading `/`, repeated slashes collapse, the trailing slash is
	 * removed (except for the root), fragments are dropped and a query string
	 * is kept verbatim. '' when nothing is left.
	 *
	 * @param string $source Path or absolute URL.
	 * @return string
	 */
	public static function normalize_path( string $source ): string {
		$source = trim( $source );
		if ( '' === $source ) {
			return '';
		}
		$hash = strpos( $source, '#' );
		if ( false !== $hash ) {
			$source = trim( substr( $source, 0, $hash ) );
			if ( '' === $source ) {
				return '';
			}
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source ) || 0 === strpos( $source, '//' ) ) {
			$parts = wp_parse_url( 0 === strpos( $source, '//' ) ? 'http:' . $source : $source );
			$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			$query = is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';
		} else {
			$q     = strpos( $source, '?' );
			$path  = false === $q ? $source : substr( $source, 0, $q );
			$query = false === $q ? '' : substr( $source, $q + 1 );
		}

		$path = rawurldecode( $path );
		$path = (string) preg_replace( '#/+#', '/', '/' . ltrim( $path, '/' ) );
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}

		return '' !== $query ? $path . '?' . $query : $path;
	}

	/**
	 * Resolve a target to an absolute URL (site-relative → under `$home`).
	 *
	 * @param string $target Target as stored.
	 * @param string $home   Site URL.
	 * @return string
	 */
	public static function absolute_target( string $target, string $home ): string {
		$target = trim( $target );
		if ( '' === $target || Settings::is_absolute_url( $target ) ) {
			return $target;
		}
		return rtrim( $home, '/' ) . '/' . ltrim( $target, '/' );
	}

	/**
	 * Validate + normalise a PUT body's rows. Pure.
	 *
	 * Returns `{ rows: { path => { target_url, status_code, id? } }, errors:
	 * [ { index, reason } ] }`. Rows are deduplicated by normalised source
	 * (a duplicate inside the body is an error, not last-wins).
	 *
	 * @param array  $rows Body rows.
	 * @param string $home Site URL (for relative targets + loop detection).
	 * @return array
	 */
	public static function validate_rows( array $rows, string $home ): array {
		$out    = array();
		$errors = array();
		$home   = rtrim( $home, '/' );
		$host   = strtolower( self::url_part( $home . '/', 'host' ) );

		$index = -1;
		foreach ( $rows as $row ) {
			++$index;
			if ( ! is_array( $row ) ) {
				$errors[] = self::row_error( $index, 'row must be an object' );
				continue;
			}

			$source = isset( $row['source_path'] ) && is_string( $row['source_path'] ) ? $row['source_path'] : '';
			if ( '' === trim( $source ) ) {
				$errors[] = self::row_error( $index, 'source_path is required' );
				continue;
			}
			if ( strlen( $source ) > self::MAX_LENGTH ) {
				$errors[] = self::row_error( $index, 'source_path must be at most ' . self::MAX_LENGTH . ' characters' );
				continue;
			}
			$path = self::normalize_path( $source );
			if ( '' === $path ) {
				$errors[] = self::row_error( $index, 'source_path is not a path' );
				continue;
			}

			$status = isset( $row['status_code'] ) ? $row['status_code'] : null;
			if ( is_string( $status ) && ctype_digit( $status ) ) {
				$status = (int) $status;
			}
			if ( ! is_int( $status ) || ! in_array( $status, self::STATUS_CODES, true ) ) {
				$errors[] = self::row_error( $index, 'status_code must be one of ' . implode( ', ', self::STATUS_CODES ) );
				continue;
			}

			$target = isset( $row['target_url'] ) && is_string( $row['target_url'] ) ? trim( $row['target_url'] ) : '';
			if ( 410 === $status ) {
				$target = '';
			} elseif ( '' === $target ) {
				$errors[] = self::row_error( $index, 'target_url is required' );
				continue;
			} elseif ( strlen( $target ) > self::MAX_LENGTH ) {
				$errors[] = self::row_error( $index, 'target_url must be at most ' . self::MAX_LENGTH . ' characters' );
				continue;
			} elseif ( ! self::is_valid_target( $target ) ) {
				$errors[] = self::row_error( $index, 'target_url must be an absolute http(s) URL or a site-relative path' );
				continue;
			} elseif ( self::is_loop( $path, $target, $home, $host ) ) {
				$errors[] = self::row_error( $index, 'target_url resolves to source_path (redirect loop)' );
				continue;
			}

			$id = null;
			if ( isset( $row['id'] ) && null !== $row['id'] ) {
				if ( is_string( $row['id'] ) && ctype_digit( $row['id'] ) ) {
					$row['id'] = (int) $row['id'];
				}
				if ( ! is_int( $row['id'] ) || $row['id'] <= 0 ) {
					$errors[] = self::row_error( $index, 'id must be a positive integer' );
					continue;
				}
				$id = $row['id'];
			}

			if ( isset( $out[ $path ] ) ) {
				$errors[] = self::row_error( $index, 'duplicate source_path ' . $path );
				continue;
			}

			$clean = array(
				'target_url'  => $target,
				'status_code' => $status,
			);
			if ( null !== $id ) {
				$clean['id'] = $id;
			}
			$out[ $path ] = $clean;
		}

		return array(
			'rows'   => $out,
			'errors' => $errors,
		);
	}

	/**
	 * Absolute http(s) URL or a site-relative path (`/…`, not `//…`).
	 *
	 * @param string $target Target.
	 * @return bool
	 */
	public static function is_valid_target( string $target ): bool {
		if ( Settings::is_absolute_url( $target ) ) {
			return true;
		}
		return '/' === substr( $target, 0, 1 ) && '//' !== substr( $target, 0, 2 ) && ! preg_match( '/[\s<>"]/', $target );
	}

	/**
	 * Does the target land on its own source (same host, same normalised path)?
	 *
	 * @param string $path   Normalised source.
	 * @param string $target Target as given.
	 * @param string $home   Site URL (no trailing slash).
	 * @param string $host   Lower-cased site host.
	 * @return bool
	 */
	public static function is_loop( string $path, string $target, string $home, string $host ): bool {
		$absolute    = self::absolute_target( $target, $home );
		$target_host = strtolower( self::url_part( $absolute, 'host' ) );
		if ( '' !== $host && $target_host !== $host ) {
			return false;
		}
		$target_path = self::normalize_path( $absolute );
		if ( $target_path === $path ) {
			return true;
		}
		// Source without a query matches the target with one stripped (matcher order 3).
		$q = strpos( $target_path, '?' );
		return false !== $q && substr( $target_path, 0, $q ) === $path;
	}

	/**
	 * One component of a URL ('' when absent).
	 *
	 * @param string $url  URL.
	 * @param string $part `host` | `path` | `query`.
	 * @return string
	 */
	public static function url_part( string $url, string $part ): string {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts[ $part ] ) ? (string) $parts[ $part ] : '';
	}

	/**
	 * Row error shape.
	 *
	 * @param int    $index  Body index.
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function row_error( int $index, string $reason ): array {
		return array(
			'index'  => $index,
			'reason' => $reason,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Matching (pure)                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Candidate table keys for a request URI, in match order: exact
	 * normalised path + query, then the path without the query. The
	 * trailing-slash variant is covered by normalisation (both `/a` and
	 * `/a/` normalise to `/a`). On a sub-directory install the same
	 * candidates are added with the home path stripped.
	 *
	 * @param string $request_uri `REQUEST_URI`.
	 * @param string $home_path   Path of `home_url()` ('' or '/' = root).
	 * @return string[]
	 */
	public static function candidates( string $request_uri, string $home_path = '' ): array {
		$full = self::normalize_path( $request_uri );
		if ( '' === $full ) {
			return array();
		}
		$q    = strpos( $full, '?' );
		$path = false === $q ? $full : substr( $full, 0, $q );

		$list = array( $full );
		if ( false !== $q ) {
			$list[] = $path;
		}

		$home_path = self::normalize_path( $home_path );
		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$stripped = substr( $path, strlen( $home_path ) );
			$list[]   = false === $q ? $stripped : $stripped . substr( $full, $q );
			if ( false !== $q ) {
				$list[] = $stripped;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * First matching row for a request, with `source_path` added; null when
	 * nothing matches.
	 *
	 * @param string $request_uri `REQUEST_URI`.
	 * @param array  $rows        Table (path => row).
	 * @param string $home_path   Path of `home_url()`.
	 * @return array|null
	 */
	public static function match( string $request_uri, array $rows, string $home_path = '' ): ?array {
		if ( ! $rows ) {
			return null;
		}
		foreach ( self::candidates( $request_uri, $home_path ) as $key ) {
			if ( isset( $rows[ $key ] ) && is_array( $rows[ $key ] ) ) {
				$row                = $rows[ $key ];
				$row['source_path'] = $key;
				return $row;
			}
		}
		return null;
	}

	/**
	 * Is this request path off-limits (admin, REST, login, cron, WC cart /
	 * checkout / account)?
	 *
	 * @param string   $path           Normalised request path (no query).
	 * @param string   $query          Query string ('' when none).
	 * @param string[] $wc_page_paths  Normalised WC page paths.
	 * @return bool
	 */
	public static function is_protected( string $path, string $query, array $wc_page_paths ): bool {
		foreach ( self::PROTECTED_PREFIXES as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) || 0 === strpos( $path, $prefix . '.' ) ) {
				return true;
			}
		}
		if ( '' !== $query && preg_match( '/(^|&)rest_route=/', $query ) ) {
			return true;
		}
		foreach ( $wc_page_paths as $wc ) {
			if ( '' !== $wc && '/' !== $wc && ( $path === $wc || 0 === strpos( $path, $wc . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Request handling                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * `template_redirect` priority 1. Both WP's `redirect_canonical` and
	 * WooCommerce's `wc_template_redirect` run at 10, so ours runs before both;
	 * WC's pages are protected paths so its own redirects stay authoritative.
	 */
	public function handle_request(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against constants only.
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used as a lookup key only, never output.
		if ( '' === $uri ) {
			return;
		}

		$row = self::match( $uri, $this->rows(), self::url_part( $this->home(), 'path' ) );
		if ( null === $row ) {
			return;
		}

		$full  = self::normalize_path( $uri );
		$q     = strpos( $full, '?' );
		$path  = false === $q ? $full : substr( $full, 0, $q );
		$query = false === $q ? '' : substr( $full, $q + 1 );
		if ( self::is_protected( $path, $query, $this->wc_page_paths() ) ) {
			return;
		}

		$this->count_hit( (string) $row['source_path'] );
		$this->respond( (int) $row['status_code'], self::absolute_target( (string) $row['target_url'], $this->home() ) );
	}

	/**
	 * Send the redirect / 410 and stop. Overridable for tests.
	 *
	 * @param int    $status Status code.
	 * @param string $target Absolute target ('' for 410).
	 */
	protected function respond( int $status, string $target ): void {
		nocache_headers();
		if ( 410 === $status || '' === $target ) {
			status_header( 410 );
			exit;
		}
		wp_redirect( $target, $status, 'StoreDash' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- merchant-configured targets may be external by design.
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Hits                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Increment the hit counter for a source path (≤ MAX_ROWS keys; new keys
	 * beyond that are dropped). Two concurrent hits may lose one increment —
	 * accepted, these are analytics.
	 *
	 * @param string $source Normalised source path.
	 */
	public function count_hit( string $source ): void {
		$data = get_transient( self::HITS_TRANSIENT );
		if ( ! is_array( $data ) || ! isset( $data['hits'] ) || ! is_array( $data['hits'] ) ) {
			$data = array(
				'since' => gmdate( 'c' ),
				'hits'  => array(),
			);
		}
		if ( ! isset( $data['hits'][ $source ] ) ) {
			if ( count( $data['hits'] ) >= self::MAX_ROWS ) {
				return;
			}
			$data['hits'][ $source ] = 0;
		}
		++$data['hits'][ $source ];
		set_transient( self::HITS_TRANSIENT, $data, self::HITS_TTL );
	}

	/**
	 * Return and clear the hit counter. Shared with B5's hourly flush.
	 *
	 * @return array `{ hits: { path: int }, since: iso|null }`
	 */
	public static function drain_hits(): array {
		$data = get_transient( self::HITS_TRANSIENT );
		if ( is_array( $data ) ) {
			delete_transient( self::HITS_TRANSIENT );
		}
		$hits = is_array( $data ) && isset( $data['hits'] ) && is_array( $data['hits'] ) ? $data['hits'] : array();
		$out  = array();
		foreach ( $hits as $path => $n ) {
			$out[ (string) $path ] = (int) $n;
		}
		return array(
			'hits'  => $out,
			'since' => is_array( $data ) && isset( $data['since'] ) && is_string( $data['since'] ) ? $data['since'] : null,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Table                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Are there rows? Autoloaded flag, no table read.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return '1' === (string) get_option( self::ACTIVE_OPTION, '0' );
	}

	/**
	 * The table (`path => row`), read once per request.
	 *
	 * @return array
	 */
	public function rows(): array {
		if ( null === $this->rows_cache ) {
			$rows             = get_option( self::OPTION, array() );
			$this->rows_cache = is_array( $rows ) ? $rows : array();
		}
		return $this->rows_cache;
	}

	/**
	 * Rows for the REST response: `{ source_path, target_url, status_code,
	 * auto, id? }` list, merchant rows first (by insertion), then auto rows.
	 *
	 * @return array[]
	 */
	public function export(): array {
		$out = array();
		foreach ( $this->rows() as $path => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = array(
				'source_path' => (string) $path,
				'target_url'  => isset( $row['target_url'] ) ? (string) $row['target_url'] : '',
				'status_code' => isset( $row['status_code'] ) ? (int) $row['status_code'] : 301,
				'auto'        => ! empty( $row['auto'] ),
			);
			if ( isset( $row['id'] ) && is_int( $row['id'] ) ) {
				$item['id'] = $row['id'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Replace the merchant rows with a validated set. Auto rows survive
	 * unless the body carries the same source path. Returns false (nothing
	 * stored) when the merged table would exceed MAX_ROWS.
	 *
	 * @param array  $merchant Validated rows (`path => { target_url, status_code, id? }`).
	 * @param string $hash     Opaque hash to store.
	 * @return bool
	 */
	public function replace_merchant_rows( array $merchant, string $hash ): bool {
		$kept = array();
		foreach ( $this->rows() as $path => $row ) {
			if ( is_array( $row ) && ! empty( $row['auto'] ) && ! isset( $merchant[ $path ] ) ) {
				$kept[ $path ] = $row;
			}
		}
		if ( count( $merchant ) + count( $kept ) > self::MAX_ROWS ) {
			return false;
		}
		$this->save_rows( $merchant + $kept );
		update_option( self::HASH_OPTION, $hash, true );
		return true;
	}

	/**
	 * Stored hash or null.
	 *
	 * @return string|null
	 */
	public function hash(): ?string {
		$hash = get_option( self::HASH_OPTION, '' );
		return is_string( $hash ) && '' !== $hash ? $hash : null;
	}

	/**
	 * Persist the table (autoload = no) and the autoloaded "has rows" flag.
	 *
	 * @param array $rows Table.
	 */
	protected function save_rows( array $rows ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $rows, '', false );
		} else {
			update_option( self::OPTION, $rows, false );
		}
		update_option( self::ACTIVE_OPTION, $rows ? '1' : '0', true );
		$this->rows_cache = $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Auto redirects                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * `post_updated`: a published product / page / post changed its slug →
	 * `old path → new permalink` (301, auto). Both states must be
	 * `publish` (a draft's old slug was never public; untrash restores the
	 * `__trashed` name and must not create a row).
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  After.
	 * @param \WP_Post $post_before Before.
	 */
	public function on_post_updated( $post_id, $post_after = null, $post_before = null ): void {
		if ( ! is_object( $post_after ) || ! is_object( $post_before ) ) {
			return;
		}
		if ( ! in_array( (string) $post_after->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}
		$old_name = isset( $post_before->post_name ) ? (string) $post_before->post_name : '';
		$new_name = isset( $post_after->post_name ) ? (string) $post_after->post_name : '';
		if ( '' === $old_name || $old_name === $new_name ) {
			return;
		}
		$this->add_auto_redirect( $this->permalink( $post_before ), $this->permalink( $post_after ), (int) $post_id, 'slug' );
	}

	/**
	 * `wp_trash_post` (fires before the `__trashed` suffix is added, so the
	 * permalink is still the live one): published product → primary category,
	 * else first category, else the shop page (301, auto).
	 *
	 * @param int         $post_id         Post ID.
	 * @param string|null $previous_status Status before trashing (WP 6.3+; read when absent).
	 */
	public function on_trash_post( $post_id, $previous_status = null ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || 'product' !== $this->post_type( $post_id ) ) {
			return;
		}
		$status = is_string( $previous_status ) && '' !== $previous_status ? $previous_status : $this->post_status( $post_id );
		if ( 'publish' !== $status ) {
			return;
		}
		$target = $this->trash_target( $post_id );
		if ( '' === $target ) {
			return;
		}
		$this->add_auto_redirect( $this->permalink( $post_id ), $target, $post_id, 'trash' );
	}

	/**
	 * `untrashed_post`: drop the trash row for this product.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_untrash_post( $post_id ): void {
		$post_id = (int) $post_id;
		$rows    = $this->rows();
		$changed = false;
		foreach ( $rows as $path => $row ) {
			if ( is_array( $row ) && ! empty( $row['auto'] ) && isset( $row['reason'], $row['post_id'] ) && 'trash' === $row['reason'] && (int) $row['post_id'] === $post_id ) {
				unset( $rows[ $path ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$this->save_rows( $rows );
		}
	}

	/**
	 * Add an auto row `normalize(old_url) → new_url`.
	 *
	 * - An existing row for the source wins (merchant or auto) → false.
	 * - An auto row whose source is the new live path is removed (it would
	 *   redirect the page away); merchant rows are left alone.
	 * - Auto rows pointing at the old URL are re-pointed to the new one (no
	 *   301 chains).
	 * - At the cap nothing is dropped; the row is skipped (logged on WP_DEBUG).
	 *
	 * @param string $old_url Old permalink.
	 * @param string $new_url New permalink / target.
	 * @param int    $post_id Post ID.
	 * @param string $reason  'slug' | 'trash'.
	 * @return bool Added.
	 */
	public function add_auto_redirect( string $old_url, string $new_url, int $post_id, string $reason ): bool {
		$source  = self::normalize_path( $old_url );
		$target  = self::normalize_path( $new_url );
		$new_url = trim( $new_url );
		if ( '' === $source || '/' === $source || '' === $new_url || $source === $target ) {
			return false;
		}

		$rows    = $this->rows();
		$changed = false;

		if ( isset( $rows[ $target ] ) && is_array( $rows[ $target ] ) && ! empty( $rows[ $target ]['auto'] ) ) {
			unset( $rows[ $target ] );
			$changed = true;
		}
		foreach ( $rows as $path => $row ) {
			if ( is_array( $row ) && ! empty( $row['auto'] ) && isset( $row['target_url'] ) && self::normalize_path( (string) $row['target_url'] ) === $source ) {
				$rows[ $path ]['target_url'] = $new_url;
				$changed                     = true;
			}
		}

		$added = false;
		if ( isset( $rows[ $source ] ) ) {
			// Existing row wins.
			$added = false;
		} elseif ( count( $rows ) >= self::MAX_ROWS ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[StoreDash SEO] redirect table full (' . self::MAX_ROWS . '); auto redirect skipped for ' . $source );
			}
		} else {
			$rows[ $source ] = array(
				'target_url'  => $new_url,
				'status_code' => 301,
				'auto'        => true,
				'reason'      => $reason,
				'post_id'     => $post_id,
			);
			$added           = true;
			$changed         = true;
		}

		if ( $changed ) {
			$this->save_rows( $rows );
		}
		return $added;
	}

	/* ------------------------------------------------------------------ */
	/* WordPress access (overridden in tests)                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Site URL (`home_url()`), no trailing slash.
	 *
	 * @return string
	 */
	public function home(): string {
		if ( null === $this->home ) {
			$this->home = (string) home_url();
		}
		return rtrim( $this->home, '/' );
	}

	/**
	 * Permalink of a post (ID or object; an object's own `post_name` is used,
	 * which is how the pre-update permalink is derived).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	protected function permalink( $post ): string {
		$link = get_permalink( $post );
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Post type.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function post_type( int $post_id ): string {
		return (string) get_post_type( $post_id );
	}

	/**
	 * Post status.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function post_status( int $post_id ): string {
		return (string) get_post_status( $post_id );
	}

	/**
	 * Where a trashed product should redirect: `_storedash_seo_primary_category`
	 * term, else the first `product_cat` term (skipping `uncategorized` when
	 * there is another), else the shop page.
	 *
	 * @param int $post_id Product ID.
	 * @return string Absolute URL or ''.
	 */
	protected function trash_target( int $post_id ): string {
		$primary = (int) get_post_meta( $post_id, self::PRIMARY_CATEGORY_META, true );
		if ( $primary > 0 ) {
			$link = get_term_link( $primary, 'product_cat' );
			if ( is_string( $link ) ) {
				return $link;
			}
		}

		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( is_array( $terms ) && $terms ) {
			$pick = null;
			foreach ( $terms as $term ) {
				if ( isset( $term->slug ) && 'uncategorized' !== $term->slug ) {
					$pick = $term;
					break;
				}
			}
			$pick = $pick ? $pick : reset( $terms );
			$link = get_term_link( $pick );
			if ( is_string( $link ) ) {
				return $link;
			}
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( is_string( $shop ) && '' !== $shop ) {
				return $shop;
			}
		}
		return $this->home() . '/';
	}

	/**
	 * Normalised paths of the WC cart / checkout / account pages (only
	 * consulted after a match, so the cost is paid on redirects only).
	 *
	 * @return string[]
	 */
	protected function wc_page_paths(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$paths = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 ) {
				$link = get_permalink( $id );
				if ( is_string( $link ) && '' !== $link ) {
					$paths[] = self::normalize_path( $link );
				}
			}
		}
		return $paths;
	}
}
