<?php
declare(strict_types=1);

/**
 * Store API product extension for variation swatches.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `extensions.storedash_swatches` to every Store API product:
 *
 *     { "pa_color": { "type": "color", "terms": { "<term slug>": { "color": "#hex", "color2"?: "#hex", "image_url"?: "…" } } } }
 *
 * Only taxonomy attributes used on the product whose type is not `select`,
 * only when a swatch provider is active; `{}` otherwise. Terms with no swatch
 * data are omitted (an empty map is `{}` too); keyed by slug because Store API
 * variation attributes use slugs.
 *
 * WooCommerce's ExtendSchema throws unless data_callback returns a PHP array,
 * and an empty PHP array encodes as `[]`. So data_callback returns `array()`
 * when empty and objectify_empty() swaps it for `{}` on the finished Store API
 * response (`rest_request_after_callbacks`, `/wc/store/` routes only).
 *
 * The callback runs once per product in listings, so attribute types and
 * term swatches are cached for the request.
 *
 * @since 1.29.0
 */
class Store_API_Extension {

	/** Extension namespace. */
	public const NAMESPACE = 'storedash_swatches';

	/**
	 * Provider resolver.
	 *
	 * @var Provider_Resolver
	 */
	private $resolver;

	/**
	 * `function(): array` — taxonomy (pa_*) => stored attribute_type.
	 *
	 * @var callable
	 */
	private $types_loader;

	/**
	 * `function( string $taxonomy, int[] $ids ): array` — objects with term_id + slug.
	 *
	 * @var callable
	 */
	private $terms_loader;

	/**
	 * Provider slug the caches below were built for.
	 *
	 * @var string|null
	 */
	private $cache_provider = null;

	/**
	 * Taxonomy => normalized type.
	 *
	 * @var array<string,string>|null
	 */
	private $type_cache = null;

	/**
	 * Taxonomy => term id => array{slug: string, swatch: array}|null.
	 *
	 * @var array<string,array<int,?array>>
	 */
	private $term_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Provider_Resolver $resolver     Provider resolver.
	 * @param callable|null     $types_loader Override for attribute types (tests).
	 * @param callable|null     $terms_loader Override for term lookup (tests).
	 */
	public function __construct( Provider_Resolver $resolver, ?callable $types_loader = null, ?callable $terms_loader = null ) {
		$this->resolver     = $resolver;
		$this->types_loader = $types_loader ?? array( $this, 'load_attribute_types' );
		$this->terms_loader = $terms_loader ?? array( $this, 'load_terms' );
	}

	/**
	 * Register with the Store API (call once WooCommerce Blocks is loaded).
	 */
	public function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$endpoint = class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema' )
			? \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER
			: 'product';

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => $endpoint,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'data_callback' ),
				'schema_callback' => array( $this, 'schema_callback' ),
				'schema_type'     => ARRAY_A,
			)
		);

		add_filter( 'rest_request_after_callbacks', array( $this, 'objectify_empty' ), 10, 3 );
	}

	/**
	 * Make an empty `extensions.storedash_swatches` serialize as `{}`.
	 *
	 * Handles a single product response and a product collection. The
	 * extensions container is a stdClass (ExtendSchema casts it), so the swap
	 * happens in place.
	 *
	 * @param mixed $response Response.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public function objectify_empty( $response, $handler = null, $request = null ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
			return $response;
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || 0 !== strpos( (string) $request->get_route(), '/wc/store/' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$items = array_key_exists( 'extensions', $data ) ? array( $data ) : $data;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['extensions'] ) || ! is_object( $item['extensions'] ) ) {
				continue;
			}
			$extensions = $item['extensions'];
			if ( property_exists( $extensions, self::NAMESPACE ) && array() === $extensions->{self::NAMESPACE} ) {
				$extensions->{self::NAMESPACE} = new \stdClass();
			}
		}

		return $response;
	}

	/**
	 * Per-product data.
	 *
	 * @param mixed $product WC_Product.
	 * @return array
	 */
	public function data_callback( $product = null ): array {
		$provider = $this->resolver->active();
		if ( null === $provider || ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		if ( $this->cache_provider !== $provider->slug() ) {
			$this->cache_provider = $provider->slug();
			$this->type_cache     = null;
			$this->term_cache     = array();
		}

		$out = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			// Variations carry plain slug strings here; only parent-level taxonomy attributes qualify.
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'is_taxonomy' ) || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$taxonomy = (string) $attribute->get_name();
			$type     = $this->taxonomy_type( $taxonomy, $provider );
			if ( 'select' === $type ) {
				continue;
			}

			$out[ $taxonomy ] = array(
				'type'  => $type,
				'terms' => (object) $this->term_swatches( $taxonomy, (array) $attribute->get_options(), $provider ),
			);
		}

		return $out;
	}

	/**
	 * Schema: keys are dynamic (taxonomy names), so only the namespace is described.
	 *
	 * @return array
	 */
	public function schema_callback(): array {
		return array();
	}

	/**
	 * Normalized type for a taxonomy (`select` when unknown).
	 *
	 * @param string                    $taxonomy Taxonomy (pa_*).
	 * @param Swatch_Provider_Interface $provider Active provider.
	 * @return string
	 */
	private function taxonomy_type( string $taxonomy, Swatch_Provider_Interface $provider ): string {
		if ( null === $this->type_cache ) {
			$this->type_cache = array();
			foreach ( (array) call_user_func( $this->types_loader ) as $tax => $native ) {
				$this->type_cache[ (string) $tax ] = $provider->to_normalized_type( (string) $native );
			}
		}
		return $this->type_cache[ $taxonomy ] ?? 'select';
	}

	/**
	 * Slug => display swatch for the given term ids (non-empty swatches only).
	 *
	 * @param string                    $taxonomy Taxonomy.
	 * @param array                     $ids      Term ids from the product attribute.
	 * @param Swatch_Provider_Interface $provider Active provider.
	 * @return array<string,array>
	 */
	private function term_swatches( string $taxonomy, array $ids, Swatch_Provider_Interface $provider ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$cache   = $this->term_cache[ $taxonomy ] ?? array();
		$missing = array_values( array_diff( $ids, array_keys( $cache ) ) );

		if ( ! empty( $missing ) ) {
			foreach ( (array) call_user_func( $this->terms_loader, $taxonomy, $missing ) as $term ) {
				$term_id = absint( is_object( $term ) ? ( $term->term_id ?? 0 ) : 0 );
				if ( $term_id <= 0 ) {
					continue;
				}
				$swatch            = array_filter(
					$provider->read_display( $term_id ),
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				);
				$cache[ $term_id ] = array(
					'slug'   => (string) $term->slug,
					'swatch' => $swatch,
				);
			}
			foreach ( $missing as $term_id ) {
				if ( ! array_key_exists( $term_id, $cache ) ) {
					$cache[ $term_id ] = null;
				}
			}
			$this->term_cache[ $taxonomy ] = $cache;
		}

		$out = array();
		foreach ( $ids as $term_id ) {
			$entry = $cache[ $term_id ] ?? null;
			if ( null !== $entry && ! empty( $entry['swatch'] ) ) {
				$out[ $entry['slug'] ] = $entry['swatch'];
			}
		}
		return $out;
	}

	/**
	 * Default types loader: WooCommerce's cached attribute taxonomy list.
	 *
	 * @return array<string,string>
	 */
	public function load_attribute_types(): array {
		$map = array();
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $map;
		}
		foreach ( (array) wc_get_attribute_taxonomies() as $row ) {
			if ( is_object( $row ) && ! empty( $row->attribute_name ) ) {
				$map[ wc_attribute_taxonomy_name( $row->attribute_name ) ] = (string) ( $row->attribute_type ?? 'select' );
			}
		}
		return $map;
	}

	/**
	 * Default terms loader: one query for the missing ids, priming term meta.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @return array
	 */
	public function load_terms( string $taxonomy, array $ids ): array {
		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'include'                => $ids,
				'hide_empty'             => false,
				'update_term_meta_cache' => true,
			)
		);
		return is_array( $terms ) ? $terms : array();
	}
}
