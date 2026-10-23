<?php
/**
 * Checkout Permissions
 *
 * @package StoreDash\Checkout\Permissions
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;

/**
 * `manage_woocommerce` gate for every checkout route (same as the other
 * settings writers; requests authenticate via the WooCommerce consumer key).
 *
 * @since 1.29.0
 */
class Checkout_Permissions extends Base_Permissions {
}
