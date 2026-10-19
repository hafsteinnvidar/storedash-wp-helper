<?php
/**
 * WP / WooCommerce glue for the JSON-LD graph (plan §5 B5).
 *
 * Collects the Schema_Builder context from the live request — reusing the
 * Head_Renderer context (resolved once per request, one meta read) — prints
 * the `<script type="application/ld+json">` in `wp_head` right after B1's
 * tags, suppresses WooCommerce's own structured data for the pieces we emit,
 * and in override mode strips Yoast's Product nodes.
 *
 * Modes (plan §6.4; option `storedash_schema_override`):
 * - normal   — module on, no SEO plugin: Organization, WebSite, BreadcrumbList,
 *              ItemList (archives), Product / ProductGroup. WC's `product`,
 *              `website`, `breadcrumblist` structured data is removed.
 * - override — Yoast active AND `storedash_schema_override` truthy: only
 *              Product / ProductGroup from us; Yoast keeps Organization,
 *              WebSite, Breadcrumb, WebPage; Yoast's own Product / Offer nodes
 *              are removed via `wpseo_schema_graph`. WC's `product` data removed.
 *
 * Extra queries on a product page (plan §6.5): approved reviews (≤ 10, only
 * when reviews are enabled and the cached count > 0) and variations (≤ 50
 * object loads, or the cached price range above that).
 *
 * @package StoreDash\Seo
 * @since   1.27.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema output.
 *
 * @since 1.27.0
 */
class Schema_Output {

	/**
	 * Option: emit our Product graph even while Yoast is active (default off;
	 * Storedash will set it later, no UI now).
	 */
	const OVERRIDE_OPTION = 'storedash_schema_override';

	/**
	 * Product taxonomies whose archives get an ItemList.
	 */
	const LIST_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand', 'pwb-brand' );

	/**
	 * Head renderer (context source).
	 *
	 * @var Head_Renderer
	 */
	private $renderer;

	/**
	 * Normalized settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * 'normal' | 'override'.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Memoized pieces (false = not built yet).
	 *
	 * @var array|false
	 */
	private $pieces = false;

	/**
	 * Constructor.
	 *
	 * @param Head_Renderer $renderer Renderer whose context() is reused (hooks need not be registered).
	 * @param array         $settings Settings::get().
	 * @param string        $mode     'normal' | 'override'.
	 */
	public function __construct( Head_Renderer $renderer, array $settings, string $mode = 'normal' ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
		$this->mode     = 'override' === $mode ? 'override' : 'normal';
	}

	/**
	 * Register hooks. Must run after Head_Renderer::register_hooks() so the
	 * graph prints after the meta tags (same `wp_head` priority 1).
	 */
	public function register_hooks(): void {
		add_action( 'wp_head', array( $this, 'print_schema' ), 1 );
		add_filter( 'woocommerce_structured_data_type_for_page', array( $this, 'suppress_wc_types' ), 20 );
		if ( 'override' === $this->mode ) {
			add_filter( 'wpseo_schema_graph', array( $this, 'filter_yoast_graph' ), 99, 2 );
		}
	}

	/**
	 * `wp_head`: print the graph.
	 */
	public function print_schema(): void {
		echo Schema_Builder::render( $this->pieces() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG in Schema_Builder::render().
	}

	/**
	 * `woocommerce_structured_data_type_for_page`: drop the WC pieces we replace
	 * so there is never a second Product / WebSite / BreadcrumbList on the page.
	 *
	 * @param mixed $types WC data types for this page.
	 * @return array
	 */
	public function suppress_wc_types( $types ) {
		$types = is_array( $types ) ? $types : array();
		return self::strip_wc_types( $types, self::piece_types( $this->pieces() ) );
	}

	/**
	 * Pure: remove WC types covered by our pieces.
	 *
	 * @param array    $types       WC types (product, website, breadcrumblist, review, order, onlinestore …).
	 * @param string[] $piece_types Our `@type` list.
	 * @return array
	 */
	public static function strip_wc_types( array $types, array $piece_types ): array {
		$drop = array();
		if ( in_array( 'Product', $piece_types, true ) || in_array( 'ProductGroup', $piece_types, true ) ) {
			$drop[] = 'product';
			$drop[] = 'review';
		}
		if ( in_array( 'WebSite', $piece_types, true ) ) {
			$drop[] = 'website';
		}
		if ( in_array( 'BreadcrumbList', $piece_types, true ) ) {
			$drop[] = 'breadcrumblist';
		}
		return array_values( array_diff( $types, $drop ) );
	}

	/**
	 * `wpseo_schema_graph` (override mode): remove Yoast's Product / Offer
	 * nodes and the WebPage → Product `mainEntity` reference.
	 *
	 * @param mixed $graph   Yoast graph (list of nodes).
	 * @param mixed $context Yoast context (unused).
	 * @return mixed
	 */
	public function filter_yoast_graph( $graph, $context = null ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		$ours = self::piece_types( $this->pieces() );
		if ( ! in_array( 'Product', $ours, true ) && ! in_array( 'ProductGroup', $ours, true ) ) {
			return $graph;
		}
		return self::strip_yoast_product( $graph );
	}

	/**
	 * Pure: drop graph nodes typed Product / ProductGroup / Offer / AggregateOffer
	 * and any `mainEntity` / `mainEntityOfPage` that pointed at them.
	 *
	 * @param array $graph Node list.
	 * @return array
	 */
	public static function strip_yoast_product( array $graph ): array {
		$types   = array( 'Product', 'ProductGroup', 'Offer', 'AggregateOffer' );
		$dropped = array();
		$out     = array();
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				$node_types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
				if ( array_intersect( $types, array_map( 'strval', $node_types ) ) ) {
					if ( isset( $node['@id'] ) ) {
						$dropped[] = (string) $node['@id'];
					}
					continue;
				}
			}
			$out[] = $node;
		}
		foreach ( $out as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( array( 'mainEntity', 'mainEntityOfPage' ) as $ref ) {
				if ( isset( $node[ $ref ]['@id'] ) && in_array( (string) $node[ $ref ]['@id'], $dropped, true ) ) {
					unset( $node[ $ref ] );
				}
			}
		}
		unset( $node );
		return $out;
	}

	/**
	 * `@type` of each piece.
	 *
	 * @param array $pieces Pieces.
	 * @return string[]
	 */
	private static function piece_types( array $pieces ): array {
		$types = array();
		foreach ( $pieces as $piece ) {
			if ( isset( $piece['@type'] ) ) {
				$types[] = (string) $piece['@type'];
			}
		}
		return $types;
	}

	/**
	 * Memoized pieces for this request.
	 *
	 * @return array
	 */
	public function pieces(): array {
		if ( false === $this->pieces ) {
			$ctx          = $this->collect_context();
			$this->pieces = $ctx ? Schema_Builder::build( $ctx ) : array();
		}
		return $this->pieces;
	}

	/* ------------------------------------------------------------------ */
	/* Context collection (WP-bound)                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the Schema_Builder context from the renderer context + WC extras.
	 *
	 * @return array Empty when the page is unsupported.
	 */
	public function collect_context(): array {
		$base = $this->renderer->context();
		if ( ! $base ) {
			return array();
		}
		$home = home_url( '/' );
		$type = 'product' === $base['type'] ? 'product' : ( 'term' === $base['type'] ? 'term' : ( 'shop' === $base['type'] ? 'shop' : 'page' ) );

		$ctx = array(
			'type'         => $type,
			'mode'         => $this->mode,
			'url'          => (string) $base['url'],
			'today'        => gmdate( 'Y-m-d' ),
			'site'         => array(
				'name'       => (string) $base['site_name'],
				'url'        => $home,
				'search_url' => home_url( '/?s={search_term_string}&post_type=product' ),
			),
			'organization' => isset( $this->settings['schema_org'] ) && is_array( $this->settings['schema_org'] ) ? $this->settings['schema_org'] : array(),
			'breadcrumbs'  => array(),
			'items'        => array(),
		);

		if ( 'product' === $type ) {
			$ctx['product'] = $this->product_context( $base );
		}
		if ( ! empty( $this->settings['breadcrumbs_enabled'] ) ) {
			$ctx['breadcrumbs'] = $this->breadcrumbs( $base, $home );
		}
		if ( 'term' === $type && (int) $base['paged'] <= 1 && 'override' !== $this->mode ) {
			$taxonomy = isset( $base['taxonomy'] ) ? (string) $base['taxonomy'] : '';
			if ( in_array( $taxonomy, self::LIST_TAXONOMIES, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
				$ctx['items'] = self::loop_product_urls();
			}
		}
		return $ctx;
	}

	/**
	 * Product fields (one wc_get_product, object cache already primed by B1).
	 *
	 * @param array $base Renderer context.
	 * @return array
	 */
	private function product_context( array $base ): array {
		$id      = (int) $base['id'];
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return array();
		}
		$decimals = wc_get_price_decimals();
		$images   = array();
		if ( '' !== (string) $base['image'] ) {
			$images[] = (string) $base['image'];
		}
		foreach ( (array) $product->get_gallery_image_ids() as $attachment_id ) {
			$url = wp_get_attachment_image_url( (int) $attachment_id, 'full' );
			if ( $url ) {
				$images[] = (string) $url;
			}
		}

		$short   = (string) $product->get_short_description();
		$desc    = '' !== $base['meta']['description'] ? $base['meta']['description'] : ( '' !== trim( $short ) ? $short : (string) $product->get_description() );
		$sale_to = '';
		if ( $product->is_on_sale() && $product->get_date_on_sale_to() ) {
			$sale_to = $product->get_date_on_sale_to()->date( 'Y-m-d' );
		}

		$out = array(
			'id'           => $id,
			'url'          => (string) $base['url'],
			'name'         => (string) $base['name'],
			'description'  => $desc,
			'images'       => $images,
			'sku'          => (string) $product->get_sku(),
			'gtin'         => method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '',
			'mpn'          => '',
			'brand'        => self::first_term_name( $id, Head_Renderer::BRAND_TAXONOMIES ),
			'category'     => self::first_term_name( $id, array( 'product_cat' ) ),
			'currency'     => (string) $base['product']['currency'],
			'price'        => (string) $base['product']['price'],
			'stock_status' => (string) $base['product']['stock_status'],
			'sale_to'      => $sale_to,
			'type'         => $product->is_type( 'variable' ) ? 'variable' : (string) $product->get_type(),
			'rating'       => array(
				'value' => 0,
				'count' => 0,
			),
			'reviews'      => array(),
			'variations'   => array(),
		);

		if ( 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) && (int) $product->get_review_count() > 0 ) {
			$out['rating']  = array(
				'value' => (float) $product->get_average_rating(),
				'count' => (int) $product->get_review_count(),
			);
			$out['reviews'] = $this->reviews( $id );
		}

		if ( 'variable' === $out['type'] ) {
			$children               = (array) $product->get_visible_children();
			$out['variation_count'] = count( $children );
			if ( count( $children ) <= Schema_Builder::MAX_VARIANTS ) {
				$out['variations'] = $this->variations( $children, $decimals );
			} else {
				$prices = $product->get_variation_prices( true );
				$list   = isset( $prices['price'] ) && is_array( $prices['price'] ) ? array_map( 'floatval', $prices['price'] ) : array();
				if ( $list ) {
					$out['price_range'] = array(
						'min' => wc_format_decimal( min( $list ), $decimals ),
						'max' => wc_format_decimal( max( $list ), $decimals ),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Approved, rated, top-level reviews (newest first).
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function reviews( int $product_id ): array {
		$comments = get_comments(
			array(
				'post_id'      => $product_id,
				'status'       => 'approve',
				'parent'       => 0,
				'number'       => Schema_Builder::MAX_REVIEWS,
				'orderby'      => 'comment_date_gmt',
				'order'        => 'DESC',
				'meta_key'     => 'rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ≤ 10 rows, product page only.
				'meta_value'   => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '>',
			)
		);
		$out      = array();
		foreach ( is_array( $comments ) ? $comments : array() as $comment ) {
			$out[] = array(
				'author' => (string) $comment->comment_author,
				'date'   => gmdate( 'Y-m-d', strtotime( (string) $comment->comment_date_gmt ) ),
				'rating' => (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
				'body'   => (string) $comment->comment_content,
			);
		}
		return $out;
	}

	/**
	 * Variation contexts.
	 *
	 * @param int[] $ids      Variation IDs.
	 * @param int   $decimals Price decimals.
	 * @return array
	 */
	private function variations( array $ids, int $decimals ): array {
		$out = array();
		foreach ( $ids as $vid ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation || '' === (string) $variation->get_price() ) {
				continue;
			}
			$attributes = array();
			foreach ( (array) $variation->get_attributes() as $key => $value ) {
				$value = (string) $value;
				if ( '' === $value ) {
					continue;
				}
				if ( 0 === strpos( (string) $key, 'pa_' ) && taxonomy_exists( (string) $key ) ) {
					$term  = get_term_by( 'slug', $value, (string) $key );
					$value = ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : $value;
				}
				$attributes[ (string) $key ] = $value;
			}
			$sale_to = '';
			if ( $variation->is_on_sale() && $variation->get_date_on_sale_to() ) {
				$sale_to = $variation->get_date_on_sale_to()->date( 'Y-m-d' );
			}
			$image_id = (int) $variation->get_image_id();
			$image    = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';
			$out[]    = array(
				'id'           => (int) $vid,
				'sku'          => (string) $variation->get_sku(),
				'gtin'         => method_exists( $variation, 'get_global_unique_id' ) ? (string) $variation->get_global_unique_id() : '',
				'name'         => '',
				'price'        => wc_format_decimal( wc_get_price_to_display( $variation ), $decimals ),
				'stock_status' => (string) $variation->get_stock_status(),
				'sale_to'      => $sale_to,
				'url'          => (string) $variation->get_permalink(),
				'image'        => $image,
				'attributes'   => $attributes,
			);
		}
		return $out;
	}

	/**
	 * Breadcrumb trail: home → category chain → current.
	 *
	 * @param array  $base Renderer context.
	 * @param string $home Home URL.
	 * @return array [ { name, url } ].
	 */
	private function breadcrumbs( array $base, string $home ): array {
		$crumbs   = array(
			array(
				'name' => (string) $base['site_name'],
				'url'  => $home,
			),
		);
		$current  = isset( $base['meta']['breadcrumb_title'] ) && '' !== $base['meta']['breadcrumb_title'] ? (string) $base['meta']['breadcrumb_title'] : (string) $base['name'];
		$term_ids = array();
		$taxonomy = '';

		if ( 'product' === $base['type'] ) {
			$shop = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop > 0 ) {
				$crumbs[] = array(
					'name' => wp_specialchars_decode( get_the_title( $shop ), ENT_QUOTES ),
					'url'  => (string) get_post_type_archive_link( 'product' ),
				);
			}
			$terms = taxonomy_exists( 'product_cat' ) ? get_the_terms( (int) $base['id'], 'product_cat' ) : array();
			if ( is_array( $terms ) && $terms ) {
				$term       = reset( $terms );
				$taxonomy   = 'product_cat';
				$term_ids   = array_reverse( (array) get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );
				$term_ids[] = (int) $term->term_id;
			}
		} elseif ( 'term' === $base['type'] ) {
			$taxonomy = (string) $base['taxonomy'];
			$term_ids = array_reverse( (array) get_ancestors( (int) $base['id'], $taxonomy, 'taxonomy' ) );
		}

		foreach ( $term_ids as $term_id ) {
			$term = get_term( (int) $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$link     = get_term_link( $term );
			$crumbs[] = array(
				'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
				'url'  => is_wp_error( $link ) ? '' : (string) $link,
			);
		}

		$crumbs[] = array(
			'name' => $current,
			'url'  => (string) $base['url'],
		);
		return $crumbs;
	}

	/**
	 * Permalinks of the products in the main query (archive page 1).
	 *
	 * @return string[]
	 */
	private static function loop_product_urls(): array {
		$urls = array();
		if ( empty( $GLOBALS['wp_query']->posts ) || ! is_array( $GLOBALS['wp_query']->posts ) ) {
			return $urls;
		}
		foreach ( $GLOBALS['wp_query']->posts as $post ) {
			if ( isset( $post->post_type ) && 'product' === $post->post_type ) {
				$urls[] = (string) get_permalink( $post );
			}
			if ( count( $urls ) >= Schema_Builder::MAX_LIST_ITEMS ) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * First term name in the first taxonomy that has one (term cache primed).
	 *
	 * @param int      $post_id    Post ID.
	 * @param string[] $taxonomies Taxonomies in priority order.
	 * @return string
	 */
	private static function first_term_name( int $post_id, array $taxonomies ): string {
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				return wp_specialchars_decode( reset( $terms )->name, ENT_QUOTES );
			}
		}
		return '';
	}

	/**
	 * Whether override mode applies on this request: Yoast is the conflict,
	 * the module is enabled and `storedash_schema_override` is truthy.
	 *
	 * @param string|null $conflict Plugin_Conflict_Guard::conflict().
	 * @return bool
	 */
	public static function override_active( $conflict ): bool {
		if ( 'yoast' !== $conflict ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ! \StoreDash\Seo\Settings::is_enabled() ) {
			return false;
		}
		return \StoreDash\Seo\Settings::to_bool( get_option( self::OVERRIDE_OPTION, false ) );
	}
}
