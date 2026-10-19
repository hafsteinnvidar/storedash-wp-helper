<?php
/**
 * Schema_Builder: graph pieces per page, offers, variants, ratings, escaping.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Schema_Builder;
use StoreDash\Seo\Engine\Schema_Output;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Schema_Builder
 * @covers \StoreDash\Seo\Engine\Schema_Output::strip_wc_types
 * @covers \StoreDash\Seo\Engine\Schema_Output::strip_yoast_product
 * @covers \StoreDash\Seo\Settings::normalize_schema_org
 */
class Schema_BuilderTest extends TestCase {

	const TODAY = '2026-10-19';

	public static function product_ctx( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 10,
				'url'          => 'https://shop.test/product/blue-shirt/',
				'name'         => 'Blue Shirt',
				'description'  => '<p>A soft <strong>cotton</strong> shirt.</p>',
				'images'       => array( 'https://shop.test/img/shirt.jpg', 'https://shop.test/img/shirt-2.jpg', 'https://shop.test/img/shirt.jpg' ),
				'sku'          => 'BS-001',
				'gtin'         => '4006381333931',
				'mpn'          => '',
				'brand'        => 'Acme',
				'category'     => 'Shirts',
				'currency'     => 'USD',
				'price'        => '19.90',
				'stock_status' => 'instock',
				'sale_to'      => '',
				'type'         => 'simple',
				'rating'       => array(
					'value' => 4.5,
					'count' => 12,
				),
				'reviews'      => array(
					array(
						'author' => 'Ann',
						'date'   => '2026-10-01',
						'rating' => 5,
						'body'   => 'Great <b>shirt</b>!',
					),
					array(
						'author' => 'Bob',
						'date'   => '2026-09-01',
						'rating' => 4,
						'body'   => '',
					),
				),
				'variations'   => array(),
			),
			$overrides
		);
	}

	public static function full_ctx( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'         => 'product',
				'mode'         => 'normal',
				'url'          => 'https://shop.test/product/blue-shirt/',
				'today'        => self::TODAY,
				'site'         => array(
					'name'       => 'My Store',
					'url'        => 'https://shop.test/',
					'search_url' => 'https://shop.test/?s={search_term_string}&post_type=product',
				),
				'organization' => Settings::normalize_schema_org(
					array(
						'name'   => 'My Store Ltd',
						'logo'   => 'https://shop.test/logo.png',
						'sameAs' => array( 'https://instagram.com/mystore', 'not a url', 'https://instagram.com/mystore' ),
						'email'  => 'hi@shop.test',
					)
				),
				'breadcrumbs'  => array(
					array(
						'name' => 'My Store',
						'url'  => 'https://shop.test/',
					),
					array(
						'name' => 'Shirts',
						'url'  => 'https://shop.test/product-category/shirts/',
					),
					array(
						'name' => 'Blue Shirt',
						'url'  => 'https://shop.test/product/blue-shirt/',
					),
				),
				'product'      => self::product_ctx(),
			),
			$overrides
		);
	}

	private function by_type( array $pieces, string $type ) {
		foreach ( $pieces as $piece ) {
			if ( $type === $piece['@type'] ) {
				return $piece;
			}
		}
		return null;
	}

	private function types( array $pieces ): array {
		return array_map( static function ( $p ) {
			return $p['@type'];
		}, $pieces );
	}

	public function test_simple_product_page_graph() {
		$pieces = Schema_Builder::build( self::full_ctx() );

		$this->assertSame( array( 'Organization', 'WebSite', 'BreadcrumbList', 'Product' ), $this->types( $pieces ) );

		$org = $this->by_type( $pieces, 'Organization' );
		$this->assertSame( 'https://shop.test/#organization', $org['@id'] );
		$this->assertSame( 'My Store Ltd', $org['name'] );
		$this->assertSame( 'https://shop.test/', $org['url'] );
		$this->assertSame( array( '@type' => 'ImageObject', 'url' => 'https://shop.test/logo.png' ), $org['logo'] );
		$this->assertSame( array( 'https://instagram.com/mystore' ), $org['sameAs'], 'invalid + duplicate sameAs dropped' );
		$this->assertSame( 'hi@shop.test', $org['email'] );
		$this->assertArrayNotHasKey( 'telephone', $org );

		$site = $this->by_type( $pieces, 'WebSite' );
		$this->assertSame( 'https://shop.test/#website', $site['@id'] );
		$this->assertSame( array( '@id' => 'https://shop.test/#organization' ), $site['publisher'] );
		$this->assertSame( 'SearchAction', $site['potentialAction']['@type'] );
		$this->assertSame( 'https://shop.test/?s={search_term_string}&post_type=product', $site['potentialAction']['target']['urlTemplate'] );
		$this->assertSame( 'required name=search_term_string', $site['potentialAction']['query-input'] );

		$product = $this->by_type( $pieces, 'Product' );
		$this->assertSame( 'https://shop.test/product/blue-shirt/#product', $product['@id'] );
		$this->assertSame( 'A soft cotton shirt.', $product['description'] );
		$this->assertSame( array( 'https://shop.test/img/shirt.jpg', 'https://shop.test/img/shirt-2.jpg' ), $product['image'], 'duplicates dropped' );
		$this->assertSame( 'BS-001', $product['sku'] );
		$this->assertSame( '4006381333931', $product['gtin13'] );
		$this->assertArrayNotHasKey( 'gtin', $product );
		$this->assertArrayNotHasKey( 'mpn', $product );
		$this->assertSame( array( '@type' => 'Brand', 'name' => 'Acme' ), $product['brand'] );
		$this->assertSame( 'Shirts', $product['category'] );
		$this->assertSame(
			array(
				'@type'           => 'Offer',
				'price'           => '19.90',
				'priceCurrency'   => 'USD',
				'priceValidUntil' => '2027-10-19',
				'itemCondition'   => 'https://schema.org/NewCondition',
				'url'             => 'https://shop.test/product/blue-shirt/',
				'availability'    => 'https://schema.org/InStock',
			),
			$product['offers']
		);
		$this->assertSame( '4.5', $product['aggregateRating']['ratingValue'] );
		$this->assertSame( 12, $product['aggregateRating']['reviewCount'] );
		$this->assertCount( 2, $product['review'] );
		$this->assertSame( 'Ann', $product['review'][0]['author']['name'] );
		$this->assertSame( '5', $product['review'][0]['reviewRating']['ratingValue'] );
		$this->assertSame( 'Great shirt!', $product['review'][0]['reviewBody'] );
		$this->assertSame( '2026-10-01', $product['review'][0]['datePublished'] );
		$this->assertArrayNotHasKey( 'reviewBody', $product['review'][1] );
	}

	public function test_breadcrumbs_last_item_has_no_link_and_single_crumb_is_dropped() {
		$crumbs = $this->by_type( Schema_Builder::build( self::full_ctx() ), 'BreadcrumbList' );
		$this->assertSame( 'https://shop.test/product/blue-shirt/#breadcrumb', $crumbs['@id'] );
		$this->assertCount( 3, $crumbs['itemListElement'] );
		$this->assertSame( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'My Store', 'item' => 'https://shop.test/' ), $crumbs['itemListElement'][0] );
		$this->assertSame( 2, $crumbs['itemListElement'][1]['position'] );
		$this->assertSame( array( '@type' => 'ListItem', 'position' => 3, 'name' => 'Blue Shirt' ), $crumbs['itemListElement'][2] );

		$this->assertNull( Schema_Builder::breadcrumbs( array( array( 'name' => 'Home', 'url' => 'https://shop.test/' ) ), 'https://shop.test/' ) );
		$this->assertNotContains( 'BreadcrumbList', $this->types( Schema_Builder::build( self::full_ctx( array( 'breadcrumbs' => array() ) ) ) ) );
	}

	public function test_organization_absent_without_name() {
		$pieces = Schema_Builder::build( self::full_ctx( array( 'organization' => Settings::normalize_schema_org( array( 'logo' => 'https://shop.test/logo.png' ) ) ) ) );
		$this->assertSame( array( 'WebSite', 'BreadcrumbList', 'Product' ), $this->types( $pieces ) );
		$this->assertArrayNotHasKey( 'publisher', $this->by_type( $pieces, 'WebSite' ) );
	}

	public function test_normalize_schema_org_shape() {
		$out = Settings::normalize_schema_org(
			array(
				'name'      => ' <b>Shop</b> ',
				'logo'      => 'ftp://x/logo.png',
				'url'       => 'https://brand.test',
				'sameAs'    => 'https://not-a-list',
				'telephone' => '+354 555 1234',
				'unknown'   => 'x',
			)
		);
		$this->assertSame(
			array(
				'name'        => 'Shop',
				'logo'        => '',
				'url'         => 'https://brand.test',
				'description' => '',
				'sameAs'      => array(),
				'telephone'   => '+354 555 1234',
				'email'       => '',
			),
			$out
		);
		$this->assertSame( $out, Settings::normalize( array( 'schema_org' => array( 'name' => 'Shop', 'logo' => 'ftp://x/logo.png', 'url' => 'https://brand.test', 'sameAs' => 'https://not-a-list', 'telephone' => '+354 555 1234', 'unknown' => 'x' ) ) )['schema_org'] );
	}

	/**
	 * @dataProvider availability_cases
	 */
	public function test_availability_map( string $status, string $expected ) {
		$this->assertSame( $expected, Schema_Builder::availability( $status ) );
	}

	public function availability_cases(): array {
		return array(
			array( 'instock', 'https://schema.org/InStock' ),
			array( 'outofstock', 'https://schema.org/OutOfStock' ),
			array( 'onbackorder', 'https://schema.org/BackOrder' ),
			array( 'weird', '' ),
		);
	}

	public function test_unknown_stock_status_omits_availability() {
		$offer = Schema_Builder::offer( '5.00', 'EUR', 'weird', 'https://x.test/p/', '', self::TODAY );
		$this->assertArrayNotHasKey( 'availability', $offer );
	}

	/**
	 * @dataProvider price_valid_until_cases
	 */
	public function test_price_valid_until( string $sale_to, string $expected ) {
		$this->assertSame( $expected, Schema_Builder::price_valid_until( $sale_to, self::TODAY ) );
	}

	public function price_valid_until_cases(): array {
		return array(
			'no sale'        => array( '', '2027-10-19' ),
			'future sale'    => array( '2026-12-24', '2026-12-24' ),
			'sale ends today' => array( '2026-10-19', '2026-10-19' ),
			'past sale'      => array( '2026-01-01', '2027-10-19' ),
			'garbage'        => array( 'soon', '2027-10-19' ),
		);
	}

	/**
	 * @dataProvider gtin_cases
	 */
	public function test_gtin_property_by_length( string $raw, array $expected ) {
		$this->assertSame( $expected, Schema_Builder::gtin( $raw ) );
	}

	public function gtin_cases(): array {
		return array(
			array( '12345678', array( 'gtin8' => '12345678' ) ),
			array( '012345678905', array( 'gtin12' => '012345678905' ) ),
			array( '4006381333931', array( 'gtin13' => '4006381333931' ) ),
			array( '1 4006381333931', array( 'gtin14' => '14006381333931' ) ),
			array( 'ABC-123', array() ),
			array( '', array() ),
		);
	}

	public function test_no_brand_category_gtin_or_reviews() {
		$product = $this->by_type(
			Schema_Builder::build(
				self::full_ctx(
					array(
						'product' => self::product_ctx(
							array(
								'brand'    => '',
								'category' => '',
								'gtin'     => '',
								'sku'      => '',
								'rating'   => array( 'value' => 0, 'count' => 0 ),
								'reviews'  => array( array( 'author' => 'Ghost', 'rating' => 5 ) ),
							)
						),
					)
				)
			),
			'Product'
		);
		foreach ( array( 'brand', 'category', 'gtin13', 'sku', 'aggregateRating', 'review' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $product, $key );
		}
		$this->assertSame( 'Offer', $product['offers']['@type'] );
	}

	public function test_reviews_capped_and_unrated_skipped() {
		$reviews = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$reviews[] = array( 'author' => 'R' . $i, 'rating' => 0 === $i % 2 ? 5 : 0 );
		}
		$reviews[] = array( 'author' => '', 'rating' => 5 );
		$reviews[] = array( 'author' => 'Six', 'rating' => 6 );
		$out = Schema_Builder::reviews( $reviews );
		$this->assertCount( 8, $out, '8 of 15 have a 1-5 rating; 10 is the cap' );
		$this->assertCount( 10, Schema_Builder::reviews( array_fill( 0, 30, array( 'author' => 'A', 'rating' => 3 ) ) ) );
	}

	public function test_variable_product_becomes_product_group_with_variants() {
		$variations = array(
			array( 'id' => 11, 'sku' => 'BS-001-S-RED', 'gtin' => '12345678', 'price' => '19.90', 'stock_status' => 'instock', 'url' => 'https://shop.test/product/blue-shirt/?attribute_pa_color=red&attribute_size=S', 'image' => 'https://shop.test/img/red.jpg', 'attributes' => array( 'attribute_pa_color' => 'Red', 'attribute_size' => 'S', 'attribute_pa_fit' => 'Slim' ) ),
			array( 'id' => 12, 'sku' => 'BS-001-M-BLUE', 'price' => '21.00', 'stock_status' => 'onbackorder', 'url' => '', 'image' => '', 'attributes' => array( 'attribute_pa_color' => 'Blue', 'attribute_size' => 'M' ), 'sale_to' => '2026-11-01' ),
			array( 'id' => 13, 'price' => '', 'attributes' => array() ),
		);
		$pieces = Schema_Builder::build( self::full_ctx( array( 'product' => self::product_ctx( array( 'type' => 'variable', 'variations' => $variations ) ) ) ) );
		$group  = $this->by_type( $pieces, 'ProductGroup' );

		$this->assertNotNull( $group );
		$this->assertNull( $this->by_type( $pieces, 'Product' ), 'no top-level Product next to the group' );
		$this->assertSame( 'https://shop.test/product/blue-shirt/#product', $group['@id'] );
		$this->assertSame( 'BS-001', $group['productGroupID'] );
		$this->assertArrayNotHasKey( 'gtin13', $group );
		$this->assertArrayNotHasKey( 'offers', $group );
		$this->assertSame( array( 'https://schema.org/color', 'https://schema.org/size' ), $group['variesBy'], 'fit is not a Google variesBy property' );
		$this->assertSame( '4.5', $group['aggregateRating']['ratingValue'], 'rating stays on the group' );
		$this->assertCount( 2, $group['hasVariant'], 'variation without a price is skipped' );

		$v1 = $group['hasVariant'][0];
		$this->assertSame( 'Product', $v1['@type'] );
		$this->assertSame( 'https://shop.test/product/blue-shirt/#variation-11', $v1['@id'] );
		$this->assertSame( 'Blue Shirt - Red, S, Slim', $v1['name'] );
		$this->assertSame( 'BS-001-S-RED', $v1['sku'] );
		$this->assertSame( '12345678', $v1['gtin8'] );
		$this->assertSame( 'https://shop.test/img/red.jpg', $v1['image'] );
		$this->assertSame( 'Red', $v1['color'] );
		$this->assertSame( 'S', $v1['size'] );
		$this->assertArrayNotHasKey( 'fit', $v1 );
		$this->assertSame( 'https://shop.test/product/blue-shirt/?attribute_pa_color=red&attribute_size=S', $v1['offers']['url'] );
		$this->assertSame( '19.90', $v1['offers']['price'] );

		$v2 = $group['hasVariant'][1];
		$this->assertSame( 'https://shop.test/product/blue-shirt/', $v2['url'], 'falls back to the parent URL' );
		$this->assertSame( 'https://shop.test/img/shirt.jpg', $v2['image'], 'falls back to the parent main image' );
		$this->assertSame( 'https://schema.org/BackOrder', $v2['offers']['availability'] );
		$this->assertSame( '2026-11-01', $v2['offers']['priceValidUntil'] );
	}

	public function test_variable_product_above_cap_uses_aggregate_offer() {
		$product = $this->by_type(
			Schema_Builder::build(
				self::full_ctx(
					array(
						'product' => self::product_ctx(
							array(
								'type'            => 'variable',
								'variations'      => array(),
								'variation_count' => 51,
								'price_range'     => array( 'min' => '10.00', 'max' => '25.00' ),
								'stock_status'    => 'instock',
							)
						),
					)
				)
			),
			'Product'
		);
		$this->assertSame(
			array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => '10.00',
				'highPrice'     => '25.00',
				'priceCurrency' => 'USD',
				'offerCount'    => 51,
				'availability'  => 'https://schema.org/InStock',
				'url'           => 'https://shop.test/product/blue-shirt/',
			),
			$product['offers']
		);
		$this->assertSame( '4006381333931', $product['gtin13'], 'parent gtin kept on a plain Product' );
	}

	public function test_item_list_on_term_archive_only_and_capped() {
		$urls = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$urls[] = 'https://shop.test/product/p' . $i . '/';
		}
		$term = Schema_Builder::build(
			array(
				'type'  => 'term',
				'url'   => 'https://shop.test/product-category/shirts/',
				'site'  => array( 'name' => 'S', 'url' => 'https://shop.test/' ),
				'items' => $urls,
			)
		);
		$list = $this->by_type( $term, 'ItemList' );
		$this->assertSame( array( 'WebSite', 'ItemList' ), $this->types( $term ) );
		$this->assertSame( 'https://shop.test/product-category/shirts/#itemlist', $list['@id'] );
		$this->assertCount( 24, $list['itemListElement'] );
		$this->assertSame( array( '@type' => 'ListItem', 'position' => 24, 'url' => 'https://shop.test/product/p24/' ), $list['itemListElement'][23] );

		// Same items on a non-term page: no list (the caller only passes items on page 1).
		$page = Schema_Builder::build( array( 'type' => 'page', 'site' => array( 'name' => 'S', 'url' => 'https://shop.test/' ), 'items' => $urls ) );
		$this->assertSame( array( 'WebSite' ), $this->types( $page ) );
	}

	public function test_override_mode_emits_only_product_pieces() {
		$pieces = Schema_Builder::build( self::full_ctx( array( 'mode' => 'override', 'items' => array( 'https://shop.test/product/x/' ) ) ) );
		$this->assertSame( array( 'Product' ), $this->types( $pieces ) );

		$group = Schema_Builder::build( self::full_ctx( array( 'mode' => 'override', 'product' => self::product_ctx( array( 'type' => 'variable', 'variations' => array( array( 'id' => 1, 'price' => '1.00' ) ) ) ) ) ) );
		$this->assertSame( array( 'ProductGroup' ), $this->types( $group ) );

		$this->assertSame( array(), Schema_Builder::build( array( 'type' => 'page', 'mode' => 'override', 'site' => array( 'name' => 'S', 'url' => 'https://shop.test/' ) ) ), 'nothing from us on non-product pages in override mode' );
	}

	public function test_render_wraps_graph_and_escapes_script_terminator() {
		$pieces = Schema_Builder::build( self::full_ctx( array( 'product' => self::product_ctx( array( 'description' => 'Evil </script><script>alert(1)</script> & more' ) ) ) ) );
		$html   = Schema_Builder::render( $pieces );

		$this->assertStringStartsWith( '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[', $html );
		$this->assertStringEndsWith( "</script>\n", $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script' ), 'only our own closing tag' );
		$this->assertStringContainsString( '</script>', $html );
		$this->assertStringNotContainsString( '\\/', $html, 'slashes unescaped' );

		$decoded = json_decode( substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( "</script>\n" ) ), true );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
		$this->assertCount( 4, $decoded['@graph'] );
		$this->assertSame( 'Evil alert(1) & more', $decoded['@graph'][3]['description'] );

		$this->assertSame( '', Schema_Builder::render( array() ) );
	}

	public function test_description_is_plain_text_capped_at_5000() {
		$long    = str_repeat( 'word ', 1200 );
		$product = Schema_Builder::product( self::product_ctx( array( 'description' => '[shortcode x="1"]' . $long ) ), self::TODAY );
		$this->assertLessThanOrEqual( 5000, mb_strlen( $product['description'] ) );
		$this->assertStringEndsWith( '…', $product['description'] );
		$this->assertStringNotContainsString( '[shortcode', $product['description'] );
		$this->assertSame( 'Tom & Jerry', Schema_Builder::plain_text( 'Tom &amp; Jerry', 100 ) );
	}

	public function test_strip_wc_types_removes_only_what_we_emit() {
		$types = array( 'product', 'review', 'website', 'breadcrumblist', 'order', 'onlinestore' );
		$this->assertSame( array( 'order', 'onlinestore' ), Schema_Output::strip_wc_types( $types, array( 'Organization', 'WebSite', 'BreadcrumbList', 'Product' ) ) );
		$this->assertSame( array( 'website', 'breadcrumblist', 'order', 'onlinestore' ), Schema_Output::strip_wc_types( $types, array( 'ProductGroup' ) ), 'override mode: WC product data only' );
		$this->assertSame( $types, Schema_Output::strip_wc_types( $types, array() ), 'nothing from us → WC untouched' );
	}

	public function test_strip_yoast_product_drops_product_nodes_and_main_entity() {
		$graph = array(
			array( '@type' => 'Organization', '@id' => 'https://shop.test/#organization' ),
			array( '@type' => array( 'WebPage', 'ItemPage' ), '@id' => 'https://shop.test/product/x/', 'mainEntity' => array( '@id' => 'https://shop.test/product/x/#product' ) ),
			array( '@type' => 'Product', '@id' => 'https://shop.test/product/x/#product' ),
			array( '@type' => 'Offer', '@id' => 'https://shop.test/product/x/#offer' ),
			array( '@type' => 'BreadcrumbList', '@id' => 'https://shop.test/product/x/#breadcrumb' ),
		);
		$out = Schema_Output::strip_yoast_product( $graph );
		$this->assertSame( array( 'Organization', array( 'WebPage', 'ItemPage' ), 'BreadcrumbList' ), array_column( $out, '@type' ) );
		$this->assertArrayNotHasKey( 'mainEntity', $out[1] );
	}
}
