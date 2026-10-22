<?php
declare(strict_types=1);

/**
 * Shared provider plumbing.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detection probe + small sanitizers shared by both providers.
 *
 * @since 1.29.0
 */
abstract class Abstract_Provider implements Swatch_Provider_Interface {

	/**
	 * Version probe: `function(): ?string`. Null = use detect_version().
	 * Injected by unit tests, where plugin constants cannot be undefined.
	 *
	 * @var callable|null
	 */
	private $probe;

	/**
	 * Constructor.
	 *
	 * @param callable|null $probe Optional version probe override.
	 */
	public function __construct( ?callable $probe = null ) {
		$this->probe = $probe;
	}

	/**
	 * Read the installed version from the plugin's own constant.
	 *
	 * @return string|null
	 */
	abstract protected function detect_version(): ?string;

	/**
	 * {@inheritDoc}
	 */
	public function version(): ?string {
		$version = null !== $this->probe ? call_user_func( $this->probe ) : $this->detect_version();
		return ( is_string( $version ) && '' !== $version ) ? $version : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return null !== $this->version();
	}

	/**
	 * {@inheritDoc}
	 */
	public function to_native_type( string $type ): ?string {
		$map = $this->type_map();
		return $map[ $type ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function to_normalized_type( string $native ): string {
		$normalized = array_search( $native, $this->type_map(), true );
		return false === $normalized ? 'select' : (string) $normalized;
	}

	/**
	 * {@inheritDoc}
	 */
	public function attribute_types(): array {
		return array_keys( $this->type_map() );
	}

	/**
	 * Normalized type => native type.
	 *
	 * @return array<string,string>
	 */
	abstract protected function type_map(): array;

	/**
	 * Stored hex color or null.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string|null
	 */
	protected static function hex_or_null( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$hex = sanitize_hex_color( $value );
		return ( is_string( $hex ) && '' !== $hex ) ? $hex : null;
	}

	/**
	 * Positive int or null.
	 *
	 * @param mixed $value Raw meta value.
	 * @return int|null
	 */
	protected static function id_or_null( $value ): ?int {
		$id = absint( $value );
		return $id > 0 ? $id : null;
	}

	/**
	 * Thumbnail URL for an attachment, or null.
	 *
	 * @param int|null $attachment_id Attachment id.
	 * @return string|null
	 */
	protected static function thumbnail_url( ?int $attachment_id ): ?string {
		if ( null === $attachment_id ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		return ( is_string( $url ) && '' !== $url ) ? $url : null;
	}
}
