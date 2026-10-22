<?php
declare(strict_types=1);

/**
 * Picks the active swatch provider.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolution order: the provider saved in `storedash_swatch_provider` (when
 * that plugin is active), else Emran, else CartFlows, else none.
 *
 * @since 1.29.0
 */
class Provider_Resolver {

	/** Option holding the merchant's preferred provider slug. */
	public const OPTION = 'storedash_swatch_provider';

	/**
	 * Providers in fallback order, keyed by slug.
	 *
	 * @var array<string,Swatch_Provider_Interface>
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @param Swatch_Provider_Interface[]|null $providers Providers in fallback order (default Emran, CartFlows).
	 */
	public function __construct( ?array $providers = null ) {
		$providers = $providers ?? array( new Emran_Provider(), new Cartflows_Provider() );
		foreach ( $providers as $provider ) {
			$this->providers[ $provider->slug() ] = $provider;
		}
	}

	/**
	 * Known provider slugs.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_keys( $this->providers );
	}

	/**
	 * Provider by slug (active or not).
	 *
	 * @param string $slug Provider slug.
	 * @return Swatch_Provider_Interface|null
	 */
	public function get( string $slug ): ?Swatch_Provider_Interface {
		return $this->providers[ $slug ] ?? null;
	}

	/**
	 * The saved preference, or null.
	 *
	 * @return string|null
	 */
	public function preferred(): ?string {
		$saved = get_option( self::OPTION, '' );
		return ( is_string( $saved ) && isset( $this->providers[ $saved ] ) ) ? $saved : null;
	}

	/**
	 * The provider in charge on this request, or null when no swatch plugin is active.
	 *
	 * @return Swatch_Provider_Interface|null
	 */
	public function active(): ?Swatch_Provider_Interface {
		$preferred = $this->preferred();
		if ( null !== $preferred && $this->providers[ $preferred ]->is_active() ) {
			return $this->providers[ $preferred ];
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->is_active() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * `GET storedash/v1/swatches/status` body.
	 *
	 * @return array{provider: ?string, active: object, types: string[]} `active` is an object so it encodes as `{}` even if empty.
	 */
	public function status(): array {
		$active = array();
		foreach ( $this->providers as $slug => $provider ) {
			$version         = $provider->version();
			$active[ $slug ] = null === $version ? null : array( 'version' => $version );
		}

		$current = $this->active();

		return array(
			'provider' => null === $current ? null : $current->slug(),
			'active'   => (object) $active,
			'types'    => null === $current ? array( 'select' ) : $current->attribute_types(),
		);
	}
}
