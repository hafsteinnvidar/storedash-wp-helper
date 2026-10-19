<?php
/**
 * Prints SEO head tags for products, product/post terms, the shop page, pages
 * and posts from Storedash SEO meta (plan §4.9) + store settings.
 *
 * Split in two halves:
 * - WP-facing: `resolve_context()` / `query_flags()` read the main query once
 *   per request and are memoized; hook callbacks are thin.
 * - Pure: `document_title()`, `build_tags()`, `robots_directives()`,
 *   `merge_robots()`, `render_tags()`, `paginate_url()` take plain arrays and
 *   are unit tested.
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Head renderer. Only registered when the module is enabled and no other SEO
 * plugin is active (see Seo_Manager).
 *
 * @since 1.26.0
 */
class Head_Renderer {

	/**
	 * Filter over the final tag list so later steps (B5) can extend it.
	 */
	const TAGS_FILTER = 'storedash_seo_head_tags';

	/**
	 * Fallback description length.
	 */
	const DESCRIPTION_LENGTH = 160;

	/**
	 * Supported term taxonomies (plus any `pa_*` attribute taxonomy).
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand', 'pwb-brand', 'category', 'post_tag' );

	/**
	 * Brand taxonomies, first one present wins.
	 */
	const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand' );

	/**
	 * Query args that only re-sort / filter an archive.
	 */
	const FILTER_ARGS = array( 'orderby', 'min_price', 'max_price', 'rating_filter' );

	/**
	 * Meta reader.
	 *
	 * @var Meta_Reader
	 */
	private $meta_reader;

	/**
	 * Normalized settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Memoized context (false = not resolved yet, null = unsupported page).
	 *
	 * @var array|null|false
	 */
	private $context = false;

	/**
	 * Constructor.
	 *
	 * @param Meta_Reader $meta_reader Meta reader.
	 * @param array       $settings    Normalized settings (Settings::get()).
	 */
	public function __construct( Meta_Reader $meta_reader, array $settings ) {
		$this->meta_reader = $meta_reader;
		$this->settings    = $settings;
	}

	/**
	 * Register front-end hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_filter( 'wp_robots', array( $this, 'filter_wp_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'maybe_remove_core_canonical' ), 0 );
		add_action( 'wp_head', array( $this, 'print_head' ), 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Hook callbacks (thin)                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * `pre_get_document_title`: SEO title meta, else the settings template.
	 *
	 * A non-empty return short-circuits `wp_get_document_title()`, which skips
	 * core's `esc_html`, so the title is escaped here (the pure
	 * `document_title()` builder stays unescaped).
	 *
	 * @param string $title Title so far (empty = let WP build it).
	 * @return string
	 */
	public function filter_document_title( $title ) {
		$ctx = $this->context();
		if ( ! $ctx ) {
			return $title;
		}
		$ours = $this->document_title( $ctx );
		return '' !== $ours ? esc_html( $ours ) : $title;
	}

	/**
	 * `wp_robots`: merge meta robots + settings noindex rules. Only ever adds
	 * restrictions, so WP's own noindex (blog_public = 0, WC cart/checkout) stays.
	 *
	 * @param array $robots Directives so far.
	 * @return array
	 */
	public function filter_wp_robots( $robots ) {
		$robots = is_array( $robots ) ? $robots : array();
		$ctx    = $this->context();
		$meta   = $ctx ? $ctx['meta']['robots'] : array();
		return self::merge_robots( $robots, $this->robots_directives( $meta, $this->query_flags() ) );
	}

	/**
	 * `wp_head` priority 0: drop core `rel_canonical` when we print our own.
	 */
	public function maybe_remove_core_canonical(): void {
		if ( $this->context() ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	/**
	 * `wp_head` priority 1: print the tags.
	 */
	public function print_head(): void {
		$ctx = $this->context();
		if ( ! $ctx ) {
			return;
		}
		$tags = apply_filters( 'storedash_seo_head_tags', $this->build_tags( $ctx ), $ctx );
		echo self::render_tags( is_array( $tags ) ? $tags : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per attribute in render_tags().
	}

	/* ------------------------------------------------------------------ */
	/* Pure logic                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Document title for a context: meta title, else the matching template.
	 *
	 * A meta title that uses template variables (`{product} {sep} {store}`,
	 * e.g. migrated from Yoast) is rendered like a template; otherwise it is
	 * printed verbatim.
	 *
	 * @param array $ctx Context (see resolve_context()).
	 * @return string '' = no opinion.
	 */
	public function document_title( array $ctx ): string {
		$title    = $ctx['meta']['title'];
		$template = '' !== $title ? $title : $this->template_for( $ctx['template'] );
		if ( '' !== $title && ! Title_Template::placeholders( $title ) ) {
			return $title;
		}
		$vars        = $ctx['vars'];
		$vars['sep'] = $this->settings['title_separator'];
		return Title_Template::render( $template, $vars );
	}

	/**
	 * Template string for a template key ('product' | 'category' | 'page').
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	public function template_for( string $key ): string {
		$setting = 'title_template_' . $key;
		return isset( $this->settings[ $setting ] ) ? (string) $this->settings[ $setting ] : '';
	}

	/**
	 * Build the ordered tag list.
	 *
	 * Each tag: `array( 'tag' => 'meta'|'link', 'attrs' => array(...), 'url_attr' => ?string )`.
	 * `url_attr` names the attribute escaped with esc_url (others esc_attr).
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public function build_tags( array $ctx ): array {
		$meta  = $ctx['meta'];
		$title = $this->document_title( $ctx );
		if ( '' === $title ) {
			$title = (string) $ctx['name'];
		}

		$description = '' !== $meta['description'] ? $meta['description'] : self::excerpt( (string) $ctx['description'], self::DESCRIPTION_LENGTH );
		$canonical   = '' !== $meta['canonical'] ? $meta['canonical'] : self::paginate_url( (string) $ctx['url'], (int) $ctx['paged'], (bool) $ctx['pretty_permalinks'] );

		$og_title       = '' !== $meta['og_title'] ? $meta['og_title'] : $title;
		$og_description = '' !== $meta['og_description'] ? $meta['og_description'] : $description;
		$image          = '' !== (string) $ctx['image'] ? (string) $ctx['image'] : (string) $this->settings['default_og_image_url'];
		$is_product     = 'product' === $ctx['type'];

		$tags   = array();
		$tags[] = self::meta( 'name', 'description', $description );
		$tags[] = array(
			'tag'      => 'link',
			'attrs'    => array(
				'rel'  => 'canonical',
				'href' => $canonical,
			),
			'url_attr' => 'href',
		);
		$tags[] = self::meta( 'property', 'og:type', $is_product ? 'product' : 'website' );
		$tags[] = self::meta( 'property', 'og:title', $og_title );
		$tags[] = self::meta( 'property', 'og:description', $og_description );
		$tags[] = self::meta( 'property', 'og:url', $canonical, true );
		$tags[] = self::meta( 'property', 'og:image', $image, true );
		$tags[] = self::meta( 'property', 'og:site_name', (string) $ctx['site_name'] );
		$tags[] = self::meta( 'property', 'og:locale', (string) $ctx['locale'] );

		if ( $is_product && ! empty( $ctx['product'] ) ) {
			$tags[] = self::meta( 'property', 'product:price:amount', (string) $ctx['product']['price'] );
			$tags[] = self::meta( 'property', 'product:price:currency', (string) $ctx['product']['currency'] );
			$tags[] = self::meta( 'property', 'product:availability', self::availability( (string) $ctx['product']['stock_status'] ) );
		}

		$tags[] = self::meta( 'name', 'twitter:card', 'summary_large_image' );
		$tags[] = self::meta( 'name', 'twitter:title', '' !== $meta['twitter_title'] ? $meta['twitter_title'] : $og_title );
		$tags[] = self::meta( 'name', 'twitter:description', '' !== $meta['twitter_description'] ? $meta['twitter_description'] : $og_description );
		$tags[] = self::meta( 'name', 'twitter:image', $image, true );

		return $tags;
	}

	/**
	 * Robots directives to add.
	 *
	 * @param array $meta_robots Parsed `_storedash_seo_robots` (empty = all allowed).
	 * @param array $flags       Query flags: search, paged, tag, attribute, filtered.
	 * @return array<string,bool>
	 */
	public function robots_directives( array $meta_robots, array $flags ): array {
		$out = array();
		$map = array(
			'index'      => 'noindex',
			'follow'     => 'nofollow',
			'imageindex' => 'noimageindex',
			'archive'    => 'noarchive',
			'snippet'    => 'nosnippet',
		);
		foreach ( $map as $allow => $deny ) {
			if ( isset( $meta_robots[ $allow ] ) && false === $meta_robots[ $allow ] ) {
				$out[ $deny ] = true;
			}
		}

		$s       = $this->settings;
		$noindex = ( ! empty( $flags['search'] ) && ! empty( $s['noindex_search'] ) )
			|| ( ! empty( $flags['paged'] ) && (int) $flags['paged'] > 1 && ! empty( $s['noindex_paginated'] ) )
			|| ( ! empty( $flags['tag'] ) && ! empty( $s['noindex_tags'] ) )
			|| ( ! empty( $flags['attribute'] ) && ! empty( $s['noindex_attributes'] ) )
			|| ( ! empty( $flags['filtered'] ) && ! empty( $s['noindex_filtered'] ) );

		if ( $noindex ) {
			$out['noindex'] = true;
			// Rule-based noindex keeps link equity flowing unless meta says nofollow.
			if ( empty( $out['nofollow'] ) ) {
				$out['follow'] = true;
			}
		}
		return $out;
	}

	/**
	 * Merge directives into WP's robots array, removing contradicting ones.
	 *
	 * @param array $robots     WP robots array.
	 * @param array $directives From robots_directives().
	 * @return array
	 */
	public static function merge_robots( array $robots, array $directives ): array {
		$conflicts = array(
			'noindex'      => array( 'index' ),
			'nofollow'     => array( 'follow' ),
			'noimageindex' => array( 'max-image-preview' ),
			'nosnippet'    => array( 'max-snippet' ),
		);
		foreach ( $directives as $directive => $on ) {
			if ( ! $on ) {
				continue;
			}
			$robots[ $directive ] = true;
			if ( isset( $conflicts[ $directive ] ) ) {
				foreach ( $conflicts[ $directive ] as $drop ) {
					unset( $robots[ $drop ] );
				}
			}
		}
		if ( ! empty( $robots['nofollow'] ) ) {
			unset( $robots['follow'] );
		}
		return $robots;
	}

	/**
	 * Render tags to HTML. Tags whose value attribute is empty are skipped.
	 *
	 * @param array[] $tags Tag list.
	 * @return string
	 */
	public static function render_tags( array $tags ): string {
		$html = '';
		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['attrs'] ) || ! is_array( $tag['attrs'] ) ) {
				continue;
			}
			$name = ( isset( $tag['tag'] ) && 'link' === $tag['tag'] ) ? 'link' : 'meta';
			$main = 'link' === $name ? 'href' : 'content';
			if ( ! isset( $tag['attrs'][ $main ] ) || '' === trim( (string) $tag['attrs'][ $main ] ) ) {
				continue;
			}
			$url_attr = isset( $tag['url_attr'] ) ? $tag['url_attr'] : null;
			$parts    = array();
			foreach ( $tag['attrs'] as $attr => $value ) {
				$escaped = ( $attr === $url_attr ) ? esc_url( (string) $value ) : esc_attr( (string) $value );
				if ( $attr === $main && '' === $escaped ) {
					continue 2;
				}
				$parts[] = preg_replace( '/[^a-z:_-]/', '', strtolower( (string) $attr ) ) . '="' . $escaped . '"';
			}
			$html .= '<' . $name . ' ' . implode( ' ', $parts ) . " />\n";
		}
		return '' === $html ? '' : "<!-- Storedash SEO -->\n" . $html . "<!-- /Storedash SEO -->\n";
	}

	/**
	 * Archive URL for page N ("/shop/" → "/shop/page/2/", or "?paged=2").
	 *
	 * @param string $base   Page-1 URL.
	 * @param int    $page   Page number.
	 * @param bool   $pretty Pretty permalinks on.
	 * @return string
	 */
	public static function paginate_url( string $base, int $page, bool $pretty ): string {
		if ( $page <= 1 || '' === $base ) {
			return $base;
		}
		$parts = explode( '?', $base, 2 );
		$query = isset( $parts[1] ) ? '?' . $parts[1] : '';
		if ( $pretty ) {
			$slash = '/' === substr( $parts[0], -1 ) ? '/' : '';
			return rtrim( $parts[0], '/' ) . '/page/' . $page . $slash . $query;
		}
		return $base . ( '' === $query ? '?' : '&' ) . 'paged=' . $page;
	}

	/**
	 * WC stock status → OG `product:availability`.
	 *
	 * @param string $stock_status instock | outofstock | onbackorder.
	 * @return string
	 */
	public static function availability( string $stock_status ): string {
		$map = array(
			'instock'     => 'instock',
			'outofstock'  => 'oos',
			'onbackorder' => 'pending',
		);
		return isset( $map[ $stock_status ] ) ? $map[ $stock_status ] : '';
	}

	/**
	 * Plain-text excerpt cut at a word boundary.
	 *
	 * @param string $text   Source text / HTML.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function excerpt( string $text, int $length ): string {
		$text = preg_replace( '/\[[^\]]*\]/', ' ', $text ); // Shortcodes.
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text || ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) <= $length ) {
			return $text;
		}
		$cut   = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > $length / 2 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, ' ,.;:-' ) . '…';
	}

	/**
	 * Meta tag helper.
	 *
	 * @param string $attr    'name' | 'property'.
	 * @param string $key     Tag name.
	 * @param string $content Value.
	 * @param bool   $is_url  Escape content as URL.
	 * @return array
	 */
	private static function meta( string $attr, string $key, string $content, bool $is_url = false ): array {
		return array(
			'tag'      => 'meta',
			'attrs'    => array(
				$attr     => $key,
				'content' => $content,
			),
			'url_attr' => $is_url ? 'content' : null,
		);
	}

	/* ------------------------------------------------------------------ */
	/* WP-facing context (memoized, one meta read)                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Memoized context for the current request, or null when unsupported.
	 *
	 * @return array|null
	 */
	public function context() {
		if ( false === $this->context ) {
			$this->context = $this->resolve_context();
		}
		return $this->context;
	}

	/**
	 * Read the main query into a context array.
	 *
	 * @return array|null
	 */
	private function resolve_context() {
		if ( is_admin() || is_feed() || is_404() ) {
			return null;
		}

		$base = array(
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'locale'            => get_locale(),
			'paged'             => 1,
			'pretty_permalinks' => (bool) get_option( 'permalink_structure' ),
			'product'           => null,
			'image'             => '',
			'description'       => '',
		);

		if ( function_exists( 'is_product' ) && is_product() ) {
			return $this->product_context( (int) get_queried_object_id(), $base );
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id <= 0 ) {
				return null;
			}
			$ctx          = $this->post_context( get_post( $shop_id ), $base );
			$ctx['url']   = (string) get_post_type_archive_link( 'product' );
			$ctx['paged'] = max( 1, (int) get_query_var( 'paged' ) );
			$ctx['type']  = 'shop';
			return $ctx;
		}

		if ( is_singular( array( 'page', 'post' ) ) ) {
			return $this->post_context( get_queried_object(), $base );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! ( $term instanceof \WP_Term ) || ! self::is_supported_taxonomy( $term->taxonomy ) ) {
				return null;
			}
			return $this->term_context( $term, $base );
		}

		return null;
	}

	/**
	 * Product context.
	 *
	 * @param int   $id   Product ID.
	 * @param array $base Shared fields.
	 * @return array|null
	 */
	private function product_context( int $id, array $base ) {
		$product = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return null;
		}
		$meta     = $this->meta_reader->for_post( $id );
		$template = '' !== $meta['title'] ? $meta['title'] : $this->template_for( 'product' );
		$needed   = Title_Template::placeholders( $template );

		$price_raw = '' !== (string) $product->get_price() ? wc_get_price_to_display( $product ) : '';
		$vars      = array(
			'product' => $product->get_name(),
			'store'   => $base['site_name'],
		);
		if ( in_array( 'price', $needed, true ) && '' !== $price_raw ) {
			$vars['price'] = Title_Template::price_text( wc_price( $price_raw ) );
		}
		if ( in_array( 'sku', $needed, true ) ) {
			$vars['sku'] = $product->get_sku();
		}
		if ( in_array( 'category', $needed, true ) ) {
			$vars['category'] = self::first_term_name( $id, array( 'product_cat' ) );
		}
		if ( in_array( 'brand', $needed, true ) ) {
			$vars['brand'] = self::first_term_name( $id, self::BRAND_TAXONOMIES );
		}

		$short = (string) $product->get_short_description();

		return array_merge(
			$base,
			array(
				'type'        => 'product',
				'template'    => 'product',
				'id'          => $id,
				'name'        => $product->get_name(),
				'url'         => (string) get_permalink( $id ),
				'meta'        => $meta,
				'vars'        => $vars,
				'description' => '' !== trim( $short ) ? $short : (string) $product->get_description(),
				'image'       => $this->image_url( $meta['og_image'], (int) $product->get_image_id() ),
				'product'     => array(
					'price'        => '' !== $price_raw ? wc_format_decimal( $price_raw, wc_get_price_decimals() ) : '',
					'currency'     => get_woocommerce_currency(),
					'stock_status' => (string) $product->get_stock_status(),
				),
			)
		);
	}

	/**
	 * Page / post context.
	 *
	 * @param mixed $post WP_Post.
	 * @param array $base Shared fields.
	 * @return array|null
	 */
	private function post_context( $post, array $base ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}
		$meta = $this->meta_reader->for_post( (int) $post->ID );
		$name = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );

		return array_merge(
			$base,
			array(
				'type'        => $post->post_type,
				'template'    => 'page',
				'id'          => (int) $post->ID,
				'name'        => $name,
				'url'         => (string) get_permalink( $post ),
				'meta'        => $meta,
				'vars'        => array(
					'page'  => $name,
					'store' => $base['site_name'],
				),
				'description' => '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content,
				'image'       => $this->image_url( $meta['og_image'], $meta['thumbnail_id'] ),
			)
		);
	}

	/**
	 * Term archive context.
	 *
	 * @param \WP_Term $term Term.
	 * @param array    $base Shared fields.
	 * @return array|null
	 */
	private function term_context( $term, array $base ) {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return null;
		}
		$meta = $this->meta_reader->for_term( (int) $term->term_id );
		$name = wp_specialchars_decode( $term->name, ENT_QUOTES );

		return array_merge(
			$base,
			array(
				'type'        => 'term',
				'template'    => 'category',
				'id'          => (int) $term->term_id,
				'taxonomy'    => $term->taxonomy,
				'name'        => $name,
				'url'         => (string) $url,
				'paged'       => max( 1, (int) get_query_var( 'paged' ) ),
				'meta'        => $meta,
				'vars'        => array(
					'category' => $name,
					'store'    => $base['site_name'],
				),
				'description' => (string) $term->description,
				'image'       => $this->image_url( $meta['og_image'], $meta['thumbnail_id'] ),
			)
		);
	}

	/**
	 * Query flags for robots rules (cheap conditionals, no DB).
	 *
	 * @return array
	 */
	private function query_flags(): array {
		$taxonomy = '';
		if ( is_tax() ) {
			$term     = get_queried_object();
			$taxonomy = ( $term instanceof \WP_Term ) ? $term->taxonomy : '';
		}

		$filtered = false;
		foreach ( array_keys( $_GET ) as $arg ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keys only, read-only.
			$arg = (string) $arg;
			if ( in_array( $arg, self::FILTER_ARGS, true ) || 0 === strpos( $arg, 'filter_' ) || 0 === strpos( $arg, 'query_type_' ) ) {
				$filtered = true;
				break;
			}
		}

		return array(
			'search'    => is_search(),
			'paged'     => max( 1, (int) get_query_var( 'paged' ) ),
			'tag'       => is_tag() || 'product_tag' === $taxonomy,
			'attribute' => 0 === strpos( $taxonomy, 'pa_' ),
			'filtered'  => $filtered,
		);
	}

	/**
	 * Whether a taxonomy gets head tags.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function is_supported_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, self::TAXONOMIES, true ) || 0 === strpos( $taxonomy, 'pa_' );
	}

	/**
	 * Name of the first term in the first taxonomy that has one (object term
	 * cache is already primed by the main query).
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
	 * OG image: meta value (URL or attachment ID) else the object's own image.
	 *
	 * @param string $meta_image   `_storedash_seo_og_image`.
	 * @param int    $fallback_id  Featured / product / category image ID.
	 * @return string
	 */
	private function image_url( string $meta_image, int $fallback_id ): string {
		if ( '' !== $meta_image && ! ctype_digit( $meta_image ) ) {
			return $meta_image;
		}
		$id = '' !== $meta_image ? (int) $meta_image : $fallback_id;
		if ( $id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		return $url ? (string) $url : '';
	}
}
