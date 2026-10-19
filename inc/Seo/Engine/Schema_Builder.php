<?php
/**
 * Pure JSON-LD `@graph` builder (plan §5 B5).
 *
 * `build( array $context ): array` takes a plain array (see CONTEXT SHAPE
 * below) and returns the list of graph pieces; `render()` wraps them in one
 * `<script type="application/ld+json">`. No WordPress / WooCommerce calls —
 * `Schema_Output` collects the context from the live request.
 *
 * CONTEXT SHAPE (every key optional unless stated):
 *   type          'product' | 'term' | 'shop' | 'page' | 'post' | 'home'   (required)
 *   mode          'normal' | 'override'   (override = Yoast active + storedash_schema_override:
 *                 only Product / ProductGroup pieces are emitted, Yoast keeps the rest)
 *   url           canonical URL of the current page
 *   today         'Y-m-d' (UTC) used for priceValidUntil (default gmdate)
 *   site          { name, url, search_url }   (search_url contains the literal {search_term_string})
 *   organization  Settings::normalize_schema_org() output (rendered only when name !== '')
 *   breadcrumbs   [ { name, url }, … ] in order, last = current page (url may be '')
 *   items         [ url, … ] products on an archive page (page 1 only — the caller decides)
 *   product       { id, url, name, description, images[], sku, gtin, mpn, brand, category,
 *                   currency, price, stock_status, sale_to ('Y-m-d' | ''), type ('simple'|'variable'|…),
 *                   rating { value, count }, reviews [ { author, date ('Y-m-d'), rating, body } ],
 *                   variations [ { id, sku, gtin, name, price, stock_status, url, image, attributes { key: value } } ],
 *                   variation_count, price_range { min, max } }
 *
 * @package StoreDash\Seo
 * @since   1.27.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Graph builder.
 *
 * @since 1.27.0
 */
class Schema_Builder {

	/**
	 * Max approved reviews embedded in the Product piece.
	 */
	const MAX_REVIEWS = 10;

	/**
	 * Variable products with more variations than this get an AggregateOffer
	 * instead of a ProductGroup + hasVariant[].
	 */
	const MAX_VARIANTS = 50;

	/**
	 * Max products in an archive ItemList.
	 */
	const MAX_LIST_ITEMS = 24;

	/**
	 * Plain-text description cap (schema.org / Google tolerate 5,000).
	 */
	const MAX_DESCRIPTION = 5000;

	/**
	 * WC stock status → schema.org ItemAvailability URL.
	 */
	const AVAILABILITY = array(
		'instock'     => 'https://schema.org/InStock',
		'outofstock'  => 'https://schema.org/OutOfStock',
		'onbackorder' => 'https://schema.org/BackOrder',
	);

	/**
	 * Variation attribute (normalized key) → schema.org `variesBy` property.
	 * Only these four are expressed on the variants; others only feed the name.
	 */
	const VARIES_BY = array(
		'color'    => 'color',
		'colour'   => 'color',
		'size'     => 'size',
		'material' => 'material',
		'pattern'  => 'pattern',
	);

	/**
	 * Build the graph pieces for a context.
	 *
	 * @param array $ctx Context (see file header).
	 * @return array[] Graph pieces in output order.
	 */
	public static function build( array $ctx ): array {
		$type     = isset( $ctx['type'] ) ? (string) $ctx['type'] : '';
		$override = isset( $ctx['mode'] ) && 'override' === $ctx['mode'];
		$site     = isset( $ctx['site'] ) && is_array( $ctx['site'] ) ? $ctx['site'] : array();
		$home     = isset( $site['url'] ) ? (string) $site['url'] : '';
		$pieces   = array();

		if ( ! $override ) {
			$org = isset( $ctx['organization'] ) && is_array( $ctx['organization'] ) ? self::organization( $ctx['organization'], $home ) : null;
			if ( $org ) {
				$pieces[] = $org;
			}
			if ( '' !== $home ) {
				$pieces[] = self::website( $site, $org ? $org['@id'] : '' );
			}
			if ( ! empty( $ctx['breadcrumbs'] ) && is_array( $ctx['breadcrumbs'] ) ) {
				$crumbs = self::breadcrumbs( $ctx['breadcrumbs'], isset( $ctx['url'] ) ? (string) $ctx['url'] : '' );
				if ( $crumbs ) {
					$pieces[] = $crumbs;
				}
			}
			if ( 'term' === $type && ! empty( $ctx['items'] ) && is_array( $ctx['items'] ) ) {
				$list = self::item_list( $ctx['items'], isset( $ctx['url'] ) ? (string) $ctx['url'] : '' );
				if ( $list ) {
					$pieces[] = $list;
				}
			}
		}

		if ( 'product' === $type && ! empty( $ctx['product'] ) && is_array( $ctx['product'] ) ) {
			$today    = isset( $ctx['today'] ) && '' !== $ctx['today'] ? (string) $ctx['today'] : gmdate( 'Y-m-d' );
			$pieces[] = self::product( $ctx['product'], $today );
		}

		return $pieces;
	}

	/**
	 * Wrap pieces in the `<script>` tag.
	 *
	 * `JSON_HEX_TAG` turns `<` / `>` into `<` / `>`, so `</script`
	 * can never appear inside the element. `wp_json_encode` is used when WP is
	 * loaded (it sanitizes invalid UTF-8), plain `json_encode` otherwise.
	 *
	 * @param array[] $pieces Graph pieces.
	 * @return string '' when there is nothing to print.
	 */
	public static function render( array $pieces ): string {
		if ( ! $pieces ) {
			return '';
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $pieces ),
		);
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $graph, $flags ) : json_encode( $graph, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback outside WP.
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}
		return '<script type="application/ld+json">' . $json . "</script>\n";
	}

	/* ------------------------------------------------------------------ */
	/* Pieces                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Organization from `settings.schema_org`.
	 *
	 * @param array  $org  Normalized schema_org.
	 * @param string $home Home URL.
	 * @return array|null Null when no name.
	 */
	public static function organization( array $org, string $home ) {
		$name = isset( $org['name'] ) ? trim( (string) $org['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		$url = ! empty( $org['url'] ) ? (string) $org['url'] : $home;
		$out = array(
			'@type' => 'Organization',
			'@id'   => self::fragment( $home, 'organization' ),
			'name'  => $name,
			'url'   => $url,
		);
		if ( ! empty( $org['logo'] ) ) {
			$out['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => (string) $org['logo'],
			);
		}
		if ( ! empty( $org['description'] ) ) {
			$out['description'] = (string) $org['description'];
		}
		if ( ! empty( $org['sameAs'] ) && is_array( $org['sameAs'] ) ) {
			$out['sameAs'] = array_values( array_map( 'strval', $org['sameAs'] ) );
		}
		if ( ! empty( $org['telephone'] ) ) {
			$out['telephone'] = (string) $org['telephone'];
		}
		if ( ! empty( $org['email'] ) ) {
			$out['email'] = (string) $org['email'];
		}
		return $out;
	}

	/**
	 * WebSite + SearchAction.
	 *
	 * @param array  $site   { name, url, search_url }.
	 * @param string $org_id Organization @id ('' = none).
	 * @return array
	 */
	public static function website( array $site, string $org_id = '' ): array {
		$home = (string) $site['url'];
		$out  = array(
			'@type' => 'WebSite',
			'@id'   => self::fragment( $home, 'website' ),
			'url'   => $home,
			'name'  => isset( $site['name'] ) ? (string) $site['name'] : '',
		);
		if ( '' !== $org_id ) {
			$out['publisher'] = array( '@id' => $org_id );
		}
		if ( ! empty( $site['search_url'] ) ) {
			$out['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => (string) $site['search_url'],
				),
				'query-input' => 'required name=search_term_string',
			);
		}
		return $out;
	}

	/**
	 * BreadcrumbList. The last crumb (current page) carries no `item`.
	 *
	 * @param array  $crumbs [ { name, url } ].
	 * @param string $url    Current page URL (for the @id).
	 * @return array|null
	 */
	public static function breadcrumbs( array $crumbs, string $url ) {
		$elements = array();
		$count    = count( $crumbs );
		$position = 0;
		foreach ( array_values( $crumbs ) as $i => $crumb ) {
			$name = isset( $crumb['name'] ) ? trim( (string) $crumb['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			++$position;
			$element = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $name,
			);
			if ( $i < $count - 1 && ! empty( $crumb['url'] ) ) {
				$element['item'] = (string) $crumb['url'];
			}
			$elements[] = $element;
		}
		if ( count( $elements ) < 2 ) {
			return null;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => self::fragment( $url, 'breadcrumb' ),
			'itemListElement' => $elements,
		);
	}

	/**
	 * ItemList of product URLs on an archive page.
	 *
	 * @param string[] $urls Product permalinks in page order.
	 * @param string   $url  Archive URL.
	 * @return array|null
	 */
	public static function item_list( array $urls, string $url ) {
		$elements = array();
		foreach ( $urls as $item_url ) {
			$item_url = trim( (string) $item_url );
			if ( '' === $item_url ) {
				continue;
			}
			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => count( $elements ) + 1,
				'url'      => $item_url,
			);
			if ( count( $elements ) >= self::MAX_LIST_ITEMS ) {
				break;
			}
		}
		if ( ! $elements ) {
			return null;
		}
		return array(
			'@type'           => 'ItemList',
			'@id'             => self::fragment( $url, 'itemlist' ),
			'itemListElement' => $elements,
		);
	}

	/**
	 * Product, or ProductGroup + hasVariant[] for variable products with
	 * ≤ MAX_VARIANTS variations (Google's 2024+ variant guidance), or Product +
	 * AggregateOffer above that.
	 *
	 * @param array  $p     Product context.
	 * @param string $today 'Y-m-d'.
	 * @return array
	 */
	public static function product( array $p, string $today ): array {
		$url  = isset( $p['url'] ) ? (string) $p['url'] : '';
		$type = isset( $p['type'] ) ? (string) $p['type'] : 'simple';
		$out  = array(
			'@type' => 'Product',
			'@id'   => self::fragment( $url, 'product' ),
			'name'  => isset( $p['name'] ) ? (string) $p['name'] : '',
			'url'   => $url,
		);

		$description = isset( $p['description'] ) ? self::plain_text( (string) $p['description'], self::MAX_DESCRIPTION ) : '';
		if ( '' !== $description ) {
			$out['description'] = $description;
		}
		$images = self::urls( isset( $p['images'] ) ? $p['images'] : array() );
		if ( $images ) {
			$out['image'] = $images;
		}
		if ( ! empty( $p['sku'] ) ) {
			$out['sku'] = (string) $p['sku'];
		}
		$out += self::gtin( isset( $p['gtin'] ) ? (string) $p['gtin'] : '' );
		if ( ! empty( $p['mpn'] ) ) {
			$out['mpn'] = (string) $p['mpn'];
		}
		if ( ! empty( $p['brand'] ) ) {
			$out['brand'] = array(
				'@type' => 'Brand',
				'name'  => (string) $p['brand'],
			);
		}
		if ( ! empty( $p['category'] ) ) {
			$out['category'] = (string) $p['category'];
		}

		$rating = self::aggregate_rating( isset( $p['rating'] ) ? $p['rating'] : array() );
		if ( $rating ) {
			$out['aggregateRating'] = $rating;
			$reviews                = self::reviews( isset( $p['reviews'] ) ? $p['reviews'] : array() );
			if ( $reviews ) {
				$out['review'] = $reviews;
			}
		}

		$currency   = isset( $p['currency'] ) ? (string) $p['currency'] : '';
		$variations = isset( $p['variations'] ) && is_array( $p['variations'] ) ? $p['variations'] : array();
		$var_count  = isset( $p['variation_count'] ) ? (int) $p['variation_count'] : count( $variations );

		if ( 'variable' === $type && $variations && $var_count <= self::MAX_VARIANTS ) {
			$out['@type']          = 'ProductGroup';
			$out['productGroupID'] = ! empty( $p['sku'] ) ? (string) $p['sku'] : (string) ( isset( $p['id'] ) ? $p['id'] : '' );
			unset( $out['gtin'], $out['gtin8'], $out['gtin12'], $out['gtin13'], $out['gtin14'], $out['mpn'] );

			$varies_by = array();
			$variants  = array();
			foreach ( $variations as $v ) {
				$variant = self::variant( $v, $p, $currency, $today, $varies_by );
				if ( $variant ) {
					$variants[] = $variant;
				}
			}
			if ( $varies_by ) {
				$out['variesBy'] = array_values(
					array_unique(
						array_map(
							static function ( $prop ) {
								return 'https://schema.org/' . $prop;
							},
							$varies_by
						)
					)
				);
			}
			$out['hasVariant'] = $variants;
			return $out;
		}

		if ( 'variable' === $type && ! empty( $p['price_range'] ) && is_array( $p['price_range'] ) ) {
			$range = $p['price_range'];
			$offer = array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => (string) $range['min'],
				'highPrice'     => (string) $range['max'],
				'priceCurrency' => $currency,
				'offerCount'    => max( 1, $var_count ),
			);
			$avail = self::availability( isset( $p['stock_status'] ) ? (string) $p['stock_status'] : '' );
			if ( '' !== $avail ) {
				$offer['availability'] = $avail;
			}
			$offer['url']  = $url;
			$out['offers'] = $offer;
			return $out;
		}

		if ( isset( $p['price'] ) && '' !== (string) $p['price'] ) {
			$out['offers'] = self::offer(
				(string) $p['price'],
				$currency,
				isset( $p['stock_status'] ) ? (string) $p['stock_status'] : '',
				$url,
				isset( $p['sale_to'] ) ? (string) $p['sale_to'] : '',
				$today
			);
		}
		return $out;
	}

	/**
	 * One Offer.
	 *
	 * @param string $price        Decimal string.
	 * @param string $currency     ISO 4217.
	 * @param string $stock_status WC stock status.
	 * @param string $url          Offer URL.
	 * @param string $sale_to      Sale end 'Y-m-d' or ''.
	 * @param string $today        'Y-m-d'.
	 * @return array
	 */
	public static function offer( string $price, string $currency, string $stock_status, string $url, string $sale_to, string $today ): array {
		$offer = array(
			'@type'           => 'Offer',
			'price'           => $price,
			'priceCurrency'   => $currency,
			'priceValidUntil' => self::price_valid_until( $sale_to, $today ),
			'itemCondition'   => 'https://schema.org/NewCondition',
			'url'             => $url,
		);
		$avail = self::availability( $stock_status );
		if ( '' !== $avail ) {
			$offer['availability'] = $avail;
		}
		return $offer;
	}

	/**
	 * Variant Product inside a ProductGroup.
	 *
	 * @param array  $v         Variation context.
	 * @param array  $parent    Parent product context.
	 * @param string $currency  Currency.
	 * @param string $today     'Y-m-d'.
	 * @param array  $varies_by Collects schema.org property names (by reference).
	 * @return array|null
	 */
	private static function variant( array $v, array $parent, string $currency, string $today, array &$varies_by ) {
		if ( ! isset( $v['price'] ) || '' === (string) $v['price'] ) {
			return null;
		}
		$attributes = isset( $v['attributes'] ) && is_array( $v['attributes'] ) ? $v['attributes'] : array();
		$values     = array();
		$props      = array();
		foreach ( $attributes as $key => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$values[] = $value;
			$norm     = self::attribute_key( (string) $key );
			if ( isset( self::VARIES_BY[ $norm ] ) ) {
				$prop           = self::VARIES_BY[ $norm ];
				$props[ $prop ] = $value;
				if ( ! in_array( $prop, $varies_by, true ) ) {
					$varies_by[] = $prop;
				}
			}
		}

		$name = ! empty( $v['name'] ) ? (string) $v['name'] : (string) ( isset( $parent['name'] ) ? $parent['name'] : '' );
		if ( $values && empty( $v['name'] ) ) {
			$name .= ' - ' . implode( ', ', $values );
		}
		$url = ! empty( $v['url'] ) ? (string) $v['url'] : (string) ( isset( $parent['url'] ) ? $parent['url'] : '' );

		$out = array(
			'@type' => 'Product',
			'@id'   => self::fragment( isset( $parent['url'] ) ? (string) $parent['url'] : '', 'variation-' . ( isset( $v['id'] ) ? (int) $v['id'] : count( $varies_by ) ) ),
			'name'  => $name,
			'url'   => $url,
		);
		if ( ! empty( $v['sku'] ) ) {
			$out['sku'] = (string) $v['sku'];
		}
		$out  += self::gtin( isset( $v['gtin'] ) ? (string) $v['gtin'] : '' );
		$image = ! empty( $v['image'] ) ? (string) $v['image'] : ( ! empty( $parent['images'][0] ) ? (string) $parent['images'][0] : '' );
		if ( '' !== $image ) {
			$out['image'] = $image;
		}
		foreach ( $props as $prop => $value ) {
			$out[ $prop ] = $value;
		}
		$out['offers'] = self::offer(
			(string) $v['price'],
			$currency,
			isset( $v['stock_status'] ) ? (string) $v['stock_status'] : '',
			$url,
			isset( $v['sale_to'] ) ? (string) $v['sale_to'] : '',
			$today
		);
		return $out;
	}

	/**
	 * AggregateRating when count > 0.
	 *
	 * @param mixed $rating { value, count }.
	 * @return array|null
	 */
	public static function aggregate_rating( $rating ) {
		if ( ! is_array( $rating ) || empty( $rating['count'] ) || (int) $rating['count'] <= 0 ) {
			return null;
		}
		$value = isset( $rating['value'] ) ? (float) $rating['value'] : 0.0;
		if ( $value <= 0 ) {
			return null;
		}
		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ),
			'reviewCount' => (int) $rating['count'],
			'bestRating'  => '5',
			'worstRating' => '1',
		);
	}

	/**
	 * Review[] (≤ MAX_REVIEWS, only rated reviews with an author).
	 *
	 * @param mixed $reviews [ { author, date, rating, body } ].
	 * @return array[]
	 */
	public static function reviews( $reviews ): array {
		$out = array();
		if ( ! is_array( $reviews ) ) {
			return $out;
		}
		foreach ( $reviews as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$author = isset( $r['author'] ) ? trim( (string) $r['author'] ) : '';
			$rating = isset( $r['rating'] ) ? (int) $r['rating'] : 0;
			if ( '' === $author || $rating < 1 || $rating > 5 ) {
				continue;
			}
			$review = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => $author,
				),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
			);
			if ( ! empty( $r['date'] ) ) {
				$review['datePublished'] = (string) $r['date'];
			}
			$body = isset( $r['body'] ) ? self::plain_text( (string) $r['body'], 1000 ) : '';
			if ( '' !== $body ) {
				$review['reviewBody'] = $body;
			}
			$out[] = $review;
			if ( count( $out ) >= self::MAX_REVIEWS ) {
				break;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * WC stock status → ItemAvailability URL ('' = unknown, omit).
	 *
	 * @param string $stock_status instock | outofstock | onbackorder.
	 * @return string
	 */
	public static function availability( string $stock_status ): string {
		return isset( self::AVAILABILITY[ $stock_status ] ) ? self::AVAILABILITY[ $stock_status ] : '';
	}

	/**
	 * `priceValidUntil`: the sale end date when set and not past, else today + 1 year.
	 *
	 * @param string $sale_to 'Y-m-d' or ''.
	 * @param string $today   'Y-m-d'.
	 * @return string 'Y-m-d'.
	 */
	public static function price_valid_until( string $sale_to, string $today ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sale_to ) && strcmp( $sale_to, $today ) >= 0 ) {
			return $sale_to;
		}
		$ts = strtotime( $today . ' +1 year UTC' );
		return false === $ts ? $today : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * GTIN property by digit count (Google wants the most specific type).
	 *
	 * @param string $raw Raw identifier (WC `global_unique_id`).
	 * @return array Empty when invalid.
	 */
	public static function gtin( string $raw ): array {
		$digits = preg_replace( '/[^0-9]/', '', $raw );
		switch ( strlen( (string) $digits ) ) {
			case 8:
				return array( 'gtin8' => $digits );
			case 12:
				return array( 'gtin12' => $digits );
			case 13:
				return array( 'gtin13' => $digits );
			case 14:
				return array( 'gtin14' => $digits );
			default:
				return array();
		}
	}

	/**
	 * Plain text (tags, shortcodes, entities stripped) cut at a word boundary.
	 *
	 * @param string $text   HTML / text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function plain_text( string $text, int $length ): string {
		$text = (string) preg_replace( '/\[[^\]]*\]/', ' ', $text );
		$text = (string) preg_replace( '#</(?:p|div|li|h[1-6]|tr|blockquote)>|<br\s*/?>#i', ' ', $text ); // Block ends → space so words do not fuse.
		$text = (string) preg_replace( '/<[^>]*>/', '', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $length ) {
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
	 * Absolute http(s) URLs only, de-duplicated, order kept.
	 *
	 * @param mixed $urls Candidate list.
	 * @return string[]
	 */
	private static function urls( $urls ): array {
		$out = array();
		foreach ( is_array( $urls ) ? $urls : array() as $url ) {
			$url = trim( (string) $url );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/**
	 * `attribute_pa_color` / `pa_color` / `Color` → `color`.
	 *
	 * @param string $key Attribute key or label.
	 * @return string
	 */
	private static function attribute_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = (string) preg_replace( '/^attribute_/', '', $key );
		$key = (string) preg_replace( '/^pa_/', '', $key );
		return str_replace( array( '-', '_', ' ' ), '', $key );
	}

	/**
	 * `<url>#<fragment>` — the `#` replaces any existing fragment.
	 *
	 * @param string $url      Base URL.
	 * @param string $fragment Fragment.
	 * @return string
	 */
	private static function fragment( string $url, string $fragment ): string {
		$base = strtok( $url, '#' );
		return ( false === $base ? '' : $base ) . '#' . $fragment;
	}
}
