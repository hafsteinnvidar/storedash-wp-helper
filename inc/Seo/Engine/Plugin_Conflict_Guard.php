<?php
/**
 * Detects other SEO plugins. When one is active the native SEO module stays
 * completely invisible (plan §6.4).
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO plugin conflict guard.
 *
 * @since 1.26.0
 */
class Plugin_Conflict_Guard {

	/**
	 * Known SEO plugins: slug => version constants + main plugin files.
	 * Order is the precedence when more than one is active.
	 */
	const PLUGINS = array(
		'yoast'        => array(
			'constants' => array( 'WPSEO_VERSION' ),
			'files'     => array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ),
		),
		'rankmath'     => array(
			'constants' => array( 'RANK_MATH_VERSION' ),
			'files'     => array( 'seo-by-rank-math/rank-math.php' ),
		),
		'seopress'     => array(
			'constants' => array( 'SEOPRESS_VERSION' ),
			'files'     => array( 'wp-seopress/seopress.php' ),
		),
		'aioseo'       => array(
			'constants' => array( 'AIOSEO_VERSION' ),
			'files'     => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ),
		),
		'seoframework' => array(
			'constants' => array( 'THE_SEO_FRAMEWORK_VERSION' ),
			'files'     => array( 'autodescription/autodescription.php' ),
		),
	);

	/**
	 * Constant check (injectable for tests).
	 *
	 * @var callable
	 */
	private $is_defined;

	/**
	 * Active plugin files provider (injectable for tests).
	 *
	 * @var callable
	 */
	private $active_plugins;

	/**
	 * Memoized detection result.
	 *
	 * @var array|null
	 */
	private $detected = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $is_defined     fn( string $constant ): bool. Default `defined`.
	 * @param callable|null $active_plugins fn(): string[] of active plugin files.
	 */
	public function __construct( $is_defined = null, $active_plugins = null ) {
		$this->is_defined     = $is_defined ? $is_defined : 'defined';
		$this->active_plugins = $active_plugins ? $active_plugins : array( __CLASS__, 'wp_active_plugins' );
	}

	/**
	 * Map of slug => active.
	 *
	 * Constants are checked first (free); the active-plugins list (an autoloaded
	 * option, same data `is_plugin_active()` reads) is only consulted when no
	 * constant matched.
	 *
	 * @return array<string,bool>
	 */
	public function detected(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}

		$out = array_fill_keys( array_keys( self::PLUGINS ), false );
		$any = false;
		foreach ( self::PLUGINS as $slug => $plugin ) {
			foreach ( $plugin['constants'] as $constant ) {
				if ( call_user_func( $this->is_defined, $constant ) ) {
					$out[ $slug ] = true;
					$any          = true;
				}
			}
		}

		if ( ! $any ) {
			$active = call_user_func( $this->active_plugins );
			$active = is_array( $active ) ? $active : array();
			foreach ( self::PLUGINS as $slug => $plugin ) {
				if ( array_intersect( $plugin['files'], $active ) ) {
					$out[ $slug ] = true;
				}
			}
		}

		$this->detected = $out;
		return $out;
	}

	/**
	 * Slug of the first active conflicting plugin, or null.
	 *
	 * @return string|null
	 */
	public function conflict(): ?string {
		foreach ( $this->detected() as $slug => $active ) {
			if ( $active ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Active plugin files on this site (site + network-activated).
	 *
	 * @return string[]
	 */
	public static function wp_active_plugins(): array {
		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? $active : array();
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}
		return $active;
	}
}
