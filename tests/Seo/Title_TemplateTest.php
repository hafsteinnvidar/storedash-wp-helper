<?php
/**
 * Title_Template: variables, unknown vars, separator collapse.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Title_Template;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Title_Template
 */
class Title_TemplateTest extends TestCase {

	public function test_default_product_template() {
		$this->assertSame(
			'Blue Shirt | My Store',
			Title_Template::render( '{product} {sep} {store}', array( 'product' => 'Blue Shirt', 'store' => 'My Store', 'sep' => '|' ) )
		);
	}

	public function test_all_variables_render() {
		$vars = array(
			'product'  => 'Shirt',
			'store'    => 'Shop',
			'category' => 'Tops',
			'price'    => '1.990 kr.',
			'sku'      => 'SH-1',
			'page'     => 'About',
			'brand'    => 'Acme',
			'sep'      => '-',
		);
		$this->assertSame(
			'Shirt (SH-1) - Acme - Tops - 1.990 kr. - About - Shop',
			Title_Template::render( '{product} ({sku}) {sep} {brand} {sep} {category} {sep} {price} {sep} {page} {sep} {store}', $vars )
		);
	}

	public function test_unknown_and_missing_variables_render_empty() {
		$this->assertSame( 'Shirt | Shop', Title_Template::render( '{product} {foo}{sep} {store}', array( 'product' => 'Shirt', 'store' => 'Shop', 'sep' => '|' ) ) );
		$this->assertSame( 'Shirt', Title_Template::render( '{product} {sep} {brand}', array( 'product' => 'Shirt', 'sep' => '|' ) ) );
	}

	public function test_doubled_separators_collapse_when_a_variable_is_empty() {
		$this->assertSame(
			'Shirt | Shop',
			Title_Template::render( '{product} {sep} {brand} {sep} {store}', array( 'product' => 'Shirt', 'brand' => '', 'store' => 'Shop', 'sep' => '|' ) )
		);
		$this->assertSame(
			'Shirt | Shop',
			Title_Template::render( '{sep}{sep} {product} {sep}{sep}{sep} {store} {sep}', array( 'product' => 'Shirt', 'store' => 'Shop', 'sep' => '|' ) )
		);
	}

	public function test_separator_inside_a_value_is_kept() {
		$this->assertSame(
			'A | B | Shop',
			Title_Template::render( '{product} {sep} {store}', array( 'product' => 'A | B', 'store' => 'Shop', 'sep' => '|' ) )
		);
	}

	public function test_empty_separator_and_html_in_values() {
		$this->assertSame( 'Shirt Shop', Title_Template::render( '{product} {sep} {store}', array( 'product' => '<b>Shirt</b>', 'store' => 'Shop', 'sep' => '' ) ) );
	}

	public function test_all_empty_renders_empty() {
		$this->assertSame( '', Title_Template::render( '{product} {sep} {store}', array( 'sep' => '|' ) ) );
	}

	public function test_placeholders_lists_known_variables_only() {
		$this->assertSame( array( 'product', 'sep', 'price' ), Title_Template::placeholders( '{product} {sep} {price} {nope}' ) );
		$this->assertSame( array(), Title_Template::placeholders( 'static' ) );
	}

	public function test_price_text_strips_wc_price_html() {
		$html = '<span class="woocommerce-Price-amount amount"><bdi>1.990&nbsp;<span class="woocommerce-Price-currencySymbol">kr.</span></bdi></span>';
		$this->assertSame( '1.990 kr.', Title_Template::price_text( $html ) );
		$this->assertSame( '$12.00', Title_Template::price_text( '<span>&#36;12.00</span>' ) );
	}
}
