<?php
declare(strict_types=1);
/**
 * Sale Check Trait
 *
 * Shared logic to detect merchant-set sale prices (not StoreDash discounts).
 *
 * @package StoreDash\Discounts\Engine
 * @since   2.3.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides is_product_on_merchant_sale() to discount engines.
 *
 * Expects $this->db_handler to be a Discount_DB_Handler instance.
 *
 * @since 2.3.0
 */
trait Trait_Sale_Check {

	/**
	 * Check if product has a merchant-set sale price (not from our discounts).
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool True if product has merchant sale price.
	 */
	protected function is_product_on_merchant_sale( \WC_Product $product ): bool {
		$sale_price = $product->get_sale_price();
		return ! ( empty( $sale_price ) || (float) $sale_price <= 0 );
	}
}
