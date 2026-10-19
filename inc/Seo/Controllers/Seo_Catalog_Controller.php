<?php
/**
 * GET /storedash/v1/seo/catalog.txt — public plain-text product catalog
 * linked from /llms.txt (plan §5 B5).
 *
 * One product per line: `name | price currency | availability | permalink`.
 * Published, catalog-visible, non-variation products ordered by ID; 500 per
 * page (`?page=N`, ≤ 10,000), `X-Total-Count` + `Link: <…?page=N+1>; rel="next"`
 * headers. 404 unless the module and `llms_txt_enabled` are on. Pages are
 * cached 1 h in transients keyed by a catalog version that any product
 * create / update / delete / trash bumps (one bump per request).
 *
 * Public on purpose (`permission_callback => __return_true`): the data is the
 * public storefront; nothing beyond public product fields is exposed.
 *
 * @package StoreDash\Seo
 * @since   1.27.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Settings;

/**
 * Catalog controller.
 *
 * @since 1.27.0
 */
class Seo_Catalog_Controller {

	/**
	 * Products per page.
	 */
	const PER_PAGE = 500;

	/**
	 * Max page number.
	 */
	const MAX_PAGE = 10000;

	/**
	 * Transient prefix (`<prefix><version>_p<page>`).
	 */
	const TRANSIENT_PREFIX = 'storedash_seo_catalog_';

	/**
	 * Page cache TTL.
	 */
	const TTL = 3600;

	/**
	 * Option holding the catalog version (bumped on product writes).
	 */
	const VERSION_OPTION = 'storedash_seo_catalog_version';

	/**
	 * WC stock status → catalog word.
	 */
	const AVAILABILITY = array(
		'instock'     => 'in_stock',
		'outofstock'  => 'out_of_stock',
		'onbackorder' => 'backorder',
	);

	/**
	 * Fetcher: fn( int $page, int $per_page ): { rows: [ { name, price, currency, stock_status, url } ], total: int }.
	 *
	 * @var callable
	 */
	private $fetcher;

	/**
	 * Cache: fn( 'get', string $key ) | fn( 'set', string $key, mixed $value ).
	 *
	 * @var callable
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetcher Product fetcher (default wc_get_products).
	 * @param callable|null $cache   Page cache (default transients).
	 */
	public function __construct( $fetcher = null, $cache = null ) {
		$this->fetcher = $fetcher ? $fetcher : array( __CLASS__, 'wc_fetch' );
		$this->cache   = $cache ? $cache : array( __CLASS__, 'wp_cache' );
	}

	/**
	 * Route callback.
	 *
	 * @param \WP_REST_Request|object $request Request (needs get_param()).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_catalog( $request ) {
		if ( ! self::is_available() ) {
			return new \WP_Error( 'storedash_seo_catalog_disabled', 'Catalog is not enabled.', array( 'status' => 404 ) );
		}
		$page = self::page_number( is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'page' ) : 1 );
		if ( null === $page ) {
			return new \WP_Error( 'storedash_seo_catalog_bad_page', 'page must be an integer between 1 and ' . self::MAX_PAGE . '.', array( 'status' => 400 ) );
		}

		$result = $this->page( $page, self::base_url() );

		$response = new \WP_REST_Response( $result['body'], 200 );
		foreach ( $result['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_plain_text' ), 10, 4 );
		return $response;
	}

	/**
	 * One page (cached): body + headers.
	 *
	 * @param int    $page     Page number (validated).
	 * @param string $base_url Route URL for the Link header.
	 * @return array { body, headers }
	 */
	public function page( int $page, string $base_url ): array {
		$key    = self::TRANSIENT_PREFIX . self::version() . '_p' . $page;
		$cached = call_user_func( $this->cache, 'get', $key );
		if ( is_array( $cached ) && isset( $cached['body'], $cached['headers'] ) ) {
			return $cached;
		}
		$data   = call_user_func( $this->fetcher, $page, self::PER_PAGE );
		$rows   = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		$total  = isset( $data['total'] ) ? (int) $data['total'] : count( $rows );
		$result = self::build( $rows, $page, self::PER_PAGE, $total, $base_url );
		call_user_func( $this->cache, 'set', $key, $result );
		return $result;
	}

	/**
	 * Pure: body + headers for a page.
	 *
	 * @param array  $rows     Product rows.
	 * @param int    $page     Page.
	 * @param int    $per_page Page size.
	 * @param int    $total    Total products.
	 * @param string $base_url Route URL.
	 * @return array { body, headers }
	 */
	public static function build( array $rows, int $page, int $per_page, int $total, string $base_url ): array {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$line = self::line( $row );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
		}
		$headers = array(
			'Content-Type'  => 'text/plain; charset=UTF-8',
			'X-Total-Count' => (string) max( 0, $total ),
			'X-Robots-Tag'  => 'noindex',
			'Cache-Control' => 'public, max-age=3600',
		);
		if ( $page * $per_page < $total && $page < self::MAX_PAGE ) {
			$headers['Link'] = '<' . self::page_url( $base_url, $page + 1 ) . '>; rel="next"';
		}
		return array(
			'body'    => $lines ? implode( "\n", $lines ) . "\n" : '',
			'headers' => $headers,
		);
	}

	/**
	 * Pure: one catalog line.
	 *
	 * @param array $row { name, price, currency, stock_status, url }.
	 * @return string '' when name or URL is missing.
	 */
	public static function line( array $row ): string {
		$name = isset( $row['name'] ) ? trim( (string) preg_replace( '/[\s|]+/u', ' ', str_replace( '|', '/', (string) $row['name'] ) ) ) : '';
		$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		if ( '' === $name || '' === $url ) {
			return '';
		}
		$price    = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
		$currency = isset( $row['currency'] ) ? trim( (string) $row['currency'] ) : '';
		$money    = '' !== $price ? trim( $price . ' ' . $currency ) : 'n/a';
		$status   = isset( $row['stock_status'] ) ? (string) $row['stock_status'] : '';
		$avail    = isset( self::AVAILABILITY[ $status ] ) ? self::AVAILABILITY[ $status ] : 'unknown';
		return $name . ' | ' . $money . ' | ' . $avail . ' | ' . $url;
	}

	/**
	 * Validate `?page`.
	 *
	 * @param mixed $raw Raw param (null = page 1).
	 * @return int|null Null when invalid.
	 */
	public static function page_number( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return 1;
		}
		if ( is_int( $raw ) ) {
			$page = $raw;
		} elseif ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$page = (int) $raw;
		} else {
			return null;
		}
		return ( $page >= 1 && $page <= self::MAX_PAGE ) ? $page : null;
	}

	/**
	 * `<base>?page=N`.
	 *
	 * @param string $base_url Route URL.
	 * @param int    $page     Page.
	 * @return string
	 */
	public static function page_url( string $base_url, int $page ): string {
		return $base_url . ( false === strpos( $base_url, '?' ) ? '?' : '&' ) . 'page=' . $page;
	}

	/**
	 * Module on + llms_txt_enabled.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( ! Settings::is_enabled() ) {
			return false;
		}
		$settings = Settings::get();
		return ! empty( $settings['llms_txt_enabled'] );
	}

	/**
	 * `rest_pre_serve_request`: emit text/plain responses raw (not JSON).
	 *
	 * @param bool   $served  Already served.
	 * @param mixed  $result  WP_REST_Response.
	 * @param mixed  $request Request.
	 * @param mixed  $server  Server.
	 * @return bool
	 */
	public static function serve_plain_text( $served, $result, $request = null, $server = null ) {
		if ( $served || ! is_object( $result ) || ! method_exists( $result, 'get_headers' ) ) {
			return $served;
		}
		$headers = $result->get_headers();
		if ( empty( $headers['Content-Type'] ) || 0 !== strpos( (string) $headers['Content-Type'], 'text/plain' ) ) {
			return $served;
		}
		$data = $result->get_data();
		echo is_string( $data ) ? $data : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built by build()/line() from stripped strings.
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Cache versioning                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Register product-write hooks that bump the catalog version. Called on
	 * every request by Seo_Manager (admin + REST writes must bust too).
	 */
	public static function register_cache_busting(): void {
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_untrash_product' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump_version' ) );
		}
	}

	/**
	 * Bump the catalog version once per request.
	 */
	public static function bump_version(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		update_option( self::VERSION_OPTION, (string) time(), false );
	}

	/**
	 * Current catalog version.
	 *
	 * @return string
	 */
	public static function version(): string {
		$v = get_option( self::VERSION_OPTION, '0' );
		return is_scalar( $v ) ? (string) $v : '0';
	}

	/**
	 * Route URL.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return rest_url( 'storedash/v1/seo/catalog.txt' );
	}

	/* ------------------------------------------------------------------ */
	/* WP defaults                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Default fetcher (wc_get_products, paginated).
	 *
	 * @param int $page     Page.
	 * @param int $per_page Page size.
	 * @return array { rows, total }
	 */
	public static function wc_fetch( int $page, int $per_page ): array {
		$result   = wc_get_products(
			array(
				'status'     => 'publish',
				'type'       => array( 'simple', 'variable', 'grouped', 'external' ),
				'visibility' => 'visible',
				'limit'      => $per_page,
				'page'       => $page,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'paginate'   => true,
				'return'     => 'objects',
			)
		);
		$rows     = array();
		$decimals = wc_get_price_decimals();
		$currency = get_woocommerce_currency();
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : array();
		foreach ( $products as $product ) {
			$price  = (string) $product->get_price();
			$rows[] = array(
				'name'         => wp_specialchars_decode( (string) $product->get_name(), ENT_QUOTES ),
				'price'        => '' !== $price ? wc_format_decimal( wc_get_price_to_display( $product ), $decimals ) : '',
				'currency'     => $currency,
				'stock_status' => (string) $product->get_stock_status(),
				'url'          => (string) $product->get_permalink(),
			);
		}
		return array(
			'rows'  => $rows,
			'total' => is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $rows ),
		);
	}

	/**
	 * Default page cache (transients).
	 *
	 * @param string $op    get | set.
	 * @param string $key   Key.
	 * @param mixed  $value Value for set.
	 * @return mixed
	 */
	public static function wp_cache( string $op, string $key, $value = null ) {
		if ( 'get' === $op ) {
			return get_transient( $key );
		}
		return set_transient( $key, $value, self::TTL );
	}
}
