<?php
declare(strict_types=1);

/**
 * Variation swatches module.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges Storedash to whichever "Variation Swatches" plugin the store runs
 * (Emran Ahmed's or CartFlows'). Wires:
 *
 * - `storedash_swatch` REST field on `wc/v3/products/attributes/{id}/terms`
 * - `storedash/v1/swatches/*` routes
 * - Store API product extension `storedash_swatches`
 *
 * Everything is inert (null / `[]` / 409) when neither plugin is active.
 *
 * @since 1.29.0
 */
class Swatches_Manager {

	/**
	 * Shared provider resolver.
	 *
	 * @var Provider_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Provider_Resolver|null $resolver Resolver (default: Emran, CartFlows).
	 */
	public function __construct( ?Provider_Resolver $resolver = null ) {
		$this->resolver = $resolver ?? new Provider_Resolver();
	}

	/**
	 * Hook everything up.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// WooCommerce fires woocommerce_blocks_loaded during its own
		// plugins_loaded callback, usually before this plugin initializes.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}
	}

	/**
	 * REST field + routes.
	 */
	public function register_rest(): void {
		( new Swatch_Rest_Field( $this->resolver ) )->register();
		( new Swatches_Controller( $this->resolver ) )->register_routes();
	}

	/**
	 * Store API product extension.
	 */
	public function register_store_api(): void {
		( new Store_API_Extension( $this->resolver ) )->register();
	}
}
