<?php
declare(strict_types=1);

/**
 * Swatch provider contract.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One third-party "Variation Swatches" plugin, seen through Storedash's
 * normalized shape.
 *
 * Normalized attribute types: select | color | image | button | radio.
 * Normalized term swatch: color (#hex), color2 (#hex), image_id (attachment
 * id), image_url (thumbnail URL, read-only), tooltip (read-only).
 *
 * @since 1.29.0
 */
interface Swatch_Provider_Interface {

	/**
	 * Provider slug used on the wire: `emran` or `cartflows`.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Whether the plugin is loaded on this request (works in REST context).
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Installed plugin version, or null when not active.
	 *
	 * @return string|null
	 */
	public function version(): ?string;

	/**
	 * Normalized attribute types this provider can render.
	 *
	 * @return string[]
	 */
	public function attribute_types(): array;

	/**
	 * Map a normalized type to the value stored in `attribute_type`.
	 *
	 * @param string $type Normalized type.
	 * @return string|null Native type, or null when the provider has no equivalent.
	 */
	public function to_native_type( string $type ): ?string;

	/**
	 * Map a stored `attribute_type` value to the normalized type.
	 *
	 * @param string $native Stored type.
	 * @return string Normalized type (`select` for anything unknown).
	 */
	public function to_normalized_type( string $native ): string;

	/**
	 * Native type slug => label, merged into `product_attributes_type_selector`
	 * so `wc_update_attribute()` accepts the provider's types in REST context.
	 *
	 * @return array<string,string>
	 */
	public function native_type_labels(): array;

	/**
	 * Read one term's swatch in the normalized shape.
	 *
	 * @param int $term_id Term id.
	 * @return array{color: ?string, color2: ?string, image_id: ?int, image_url: ?string, tooltip: ?string}
	 */
	public function read( int $term_id ): array;

	/**
	 * Cheap read for storefront display (no attachment-id lookups).
	 *
	 * @param int $term_id Term id.
	 * @return array{color: ?string, color2: ?string, image_url: ?string}
	 */
	public function read_display( int $term_id ): array;

	/**
	 * Write a validated, normalized swatch. Only keys present are touched;
	 * a null value deletes that piece.
	 *
	 * @param int   $term_id Term id.
	 * @param array $data    Subset of {color, color2, image_id} (already sanitized).
	 * @return true|\WP_Error
	 */
	public function write( int $term_id, array $data );
}
