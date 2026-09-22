<?php
/**
 * Self-hosted plugin updates.
 *
 * The plugin is not distributed through wordpress.org, so WordPress would never
 * learn about new versions on its own. This class plugs into the core update
 * machinery (WordPress 5.8+ `Update URI` header) so that a new release shows
 * up as "Update available" on the Plugins screen, installs with the standard
 * one-click "Update now" link, and can be auto-updated with the core toggle.
 *
 * How it works:
 *  1. `storedash.php` declares `Update URI: https://storedash.app/wp-helper`.
 *     That tells core to skip wordpress.org for this plugin and instead run
 *     the `update_plugins_storedash.app` filter during its update check.
 *  2. The filter fetches a small JSON manifest (`storedash.json`, written by
 *     `scripts/build-manifest.php` and attached to every GitHub Release) that
 *     names the latest version, the zip URL, and the zip's SHA-256.
 *  3. Core compares the manifest version to the installed one and shows the
 *     update. The `plugins_api` filter fills the "View details" modal, and
 *     `upgrader_pre_download` verifies the downloaded zip against the SHA-256
 *     before core installs it.
 *
 * The manifest is cached in a transient so the update check does not hit the
 * network on every admin request (core already throttles, but belt and braces).
 * "Check again" on Dashboard → Updates bypasses the cache.
 *
 * @package Storedash
 * @since 1.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Updater {

	/**
	 * Hostname from the `Update URI` header. Core derives the filter name from it.
	 */
	const UPDATE_HOST = 'storedash.app';

	/**
	 * Where the manifest for the newest release lives. GitHub always redirects
	 * `releases/latest/download/<asset>` to the asset on the latest non-prerelease,
	 * non-draft release, so this URL never changes between versions.
	 *
	 * Overridable with the `storedash_update_manifest_url` filter.
	 */
	const MANIFEST_URL = 'https://github.com/hafsteinnvidar/storedash-wp-helper/releases/latest/download/storedash.json';

	const TRANSIENT       = 'storedash_update_manifest';
	const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
	const ERROR_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Plugin basename, e.g. `storedash/storedash.php`.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin slug (directory name), e.g. `storedash`.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Installed version, from the plugin header.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * @param string $main_file Absolute path to the main plugin file.
	 * @param string $version   Installed plugin version.
	 */
	public function __construct( string $main_file, string $version ) {
		$this->plugin_file = plugin_basename( $main_file );
		$this->slug        = dirname( $this->plugin_file );
		$this->version     = $version;
	}

	/**
	 * Attach to the WordPress update hooks.
	 */
	public function register(): void {
		add_filter( 'update_plugins_' . self::UPDATE_HOST, array( $this, 'filter_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_checksum' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache_after_update' ), 10, 2 );
	}

	/**
	 * `update_plugins_{$hostname}` callback: tell core about the latest release.
	 *
	 * Returns the update array on every call, not only when newer — core sorts
	 * it into `response` (update available) or `no_update` (current) itself, and
	 * a populated `no_update` entry is what enables the auto-update toggle.
	 *
	 * @param array|false $update      Existing update data (false).
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename core is asking about.
	 * @return array|false
	 */
	public function filter_update( $update, array $plugin_data, string $plugin_file ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		$manifest = $this->get_manifest();
		if ( ! $manifest ) {
			return $update;
		}

		return array(
			'slug'         => $this->slug,
			'version'      => $manifest['version'],
			'url'          => $manifest['homepage'],
			'package'      => $manifest['package'],
			'tested'       => $manifest['tested'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'icons'        => $manifest['icons'],
			'banners'      => $manifest['banners'],
		);
	}

	/**
	 * `plugins_api` callback: content for the "View details" / "View version x
	 * details" modal, which core would otherwise ask wordpress.org for.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action API action.
	 * @param object             $args   Request args; `$args->slug` is the plugin asked about.
	 * @return false|object|array
	 */
	public function filter_plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$manifest = $this->get_manifest();
		if ( ! $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => $manifest['name'],
			'slug'          => $this->slug,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'],
			'homepage'      => $manifest['homepage'],
			'requires'      => $manifest['requires'],
			'tested'        => $manifest['tested'],
			'requires_php'  => $manifest['requires_php'],
			'last_updated'  => $manifest['last_updated'],
			'download_link' => $manifest['package'],
			'sections'      => $manifest['sections'],
			'banners'       => $manifest['banners'],
			'icons'         => $manifest['icons'],
		);
	}

	/**
	 * `upgrader_pre_download` callback: download the zip ourselves and refuse to
	 * install it unless its SHA-256 matches the manifest.
	 *
	 * Core verifies signatures for wordpress.org packages only; third-party
	 * packages are installed as downloaded. Pinning the hash in the manifest
	 * (served from a different URL than the zip) means a swapped zip is rejected.
	 *
	 * @param bool|string|WP_Error $reply      false to let core download; a path or error to short-circuit.
	 * @param string               $package    Package URL.
	 * @param WP_Upgrader          $upgrader   Upgrader instance.
	 * @param array                $hook_extra Extra args; `plugin` is the basename being updated.
	 * @return bool|string|WP_Error
	 */
	public function verify_package_checksum( $reply, $package, $upgrader, $hook_extra ) {
		if ( false !== $reply || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $reply;
		}

		$manifest = $this->get_manifest();
		if ( ! $manifest || empty( $manifest['sha256'] ) || $manifest['package'] !== $package ) {
			// Nothing to verify against (or a different package than we announced): leave it to core.
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_file = download_url( $package, 300 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$actual = hash_file( 'sha256', $tmp_file );
		if ( ! $actual || ! hash_equals( $manifest['sha256'], $actual ) ) {
			wp_delete_file( $tmp_file );
			StoreDash_Helpers::log_message( 'Update package checksum mismatch; refusing to install ' . $package, 'error' );
			return new WP_Error(
				'storedash_checksum_mismatch',
				__( 'The downloaded Storedash Helper update did not match its published checksum and was not installed. Please try again later.', 'storedash' )
			);
		}

		// Core deletes the temp file after install because it differs from $package.
		return $tmp_file;
	}

	/**
	 * `upgrader_process_complete` callback: forget the cached manifest once this
	 * plugin has been updated so the new version's "no update" state is fresh.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Action/type info; `plugins` lists updated basenames.
	 */
	public function flush_cache_after_update( $upgrader, array $options ): void {
		if ( 'plugin' !== ( $options['type'] ?? '' ) ) {
			return;
		}
		$plugins = (array) ( $options['plugins'] ?? array() );
		if ( in_array( $this->plugin_file, $plugins, true ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Fetch (or read from cache) the normalized release manifest.
	 *
	 * @return array|null Normalized manifest, or null when unavailable/invalid.
	 */
	private function get_manifest(): ?array {
		// "Check again" on Dashboard → Updates, and `wp plugin update --force`-style flows.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag on a core admin screen, only bypasses our cache.
		$force = isset( $_GET['force-check'] );

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached['manifest'] ?? null; // 'manifest' => null is a cached failure.
			}
		}

		$manifest = $this->fetch_manifest();

		set_transient(
			self::TRANSIENT,
			array( 'manifest' => $manifest ),
			$manifest ? self::CACHE_TTL : self::ERROR_CACHE_TTL
		);

		return $manifest;
	}

	/**
	 * Download and validate the manifest.
	 *
	 * @return array|null
	 */
	private function fetch_manifest(): ?array {
		/**
		 * Filters the URL of the release manifest.
		 *
		 * @param string $url Manifest URL.
		 */
		$url = apply_filters( 'storedash_update_manifest_url', self::MANIFEST_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'sslverify'  => true,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'StoredashHelper/' . $this->version . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			StoreDash_Helpers::debug_log( 'Update check failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			StoreDash_Helpers::debug_log( 'Update check returned HTTP ' . $code );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			StoreDash_Helpers::debug_log( 'Update manifest is not valid JSON' );
			return null;
		}

		return self::normalize_manifest( $data );
	}

	/**
	 * Validate the raw manifest and fill in defaults.
	 *
	 * Pure function (no WordPress calls) so it can be unit tested. Rejects a
	 * manifest that lacks a version or an https package URL; everything else is
	 * optional.
	 *
	 * @param array $data Decoded manifest JSON.
	 * @return array|null Normalized manifest or null if unusable.
	 */
	public static function normalize_manifest( array $data ): ?array {
		$version = isset( $data['version'] ) ? trim( (string) $data['version'] ) : '';
		$package = isset( $data['package'] ) ? trim( (string) $data['package'] ) : '';

		if ( '' === $version || ! preg_match( '/^\d+(\.\d+){1,3}([.-][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}
		if ( 0 !== strpos( $package, 'https://' ) ) {
			return null;
		}

		$sha256 = isset( $data['sha256'] ) ? strtolower( trim( (string) $data['sha256'] ) ) : '';
		if ( '' !== $sha256 && ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
			$sha256 = '';
		}

		$sections = array();
		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( $data['sections'] as $key => $html ) {
				if ( is_string( $key ) && is_string( $html ) && '' !== $html ) {
					$sections[ $key ] = $html;
				}
			}
		}

		$string = static function ( $value, string $default = '' ): string {
			return is_scalar( $value ) ? (string) $value : $default;
		};
		$urls   = static function ( $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}
			$out = array();
			foreach ( $value as $key => $url ) {
				if ( is_string( $key ) && is_string( $url ) && 0 === strpos( $url, 'https://' ) ) {
					$out[ $key ] = $url;
				}
			}
			return $out;
		};

		return array(
			'name'         => $string( $data['name'] ?? null, 'Storedash Helper' ),
			'version'      => $version,
			'package'      => $package,
			'sha256'       => $sha256,
			'homepage'     => $string( $data['homepage'] ?? null, 'https://storedash.app' ),
			'author'       => $string( $data['author'] ?? null, 'Storedash' ),
			'requires'     => $string( $data['requires'] ?? null, '5.8' ),
			'tested'       => $string( $data['tested'] ?? null ),
			'requires_php' => $string( $data['requires_php'] ?? null, '7.4' ),
			'last_updated' => $string( $data['last_updated'] ?? null ),
			'sections'     => $sections,
			'icons'        => $urls( $data['icons'] ?? null ),
			'banners'      => $urls( $data['banners'] ?? null ),
		);
	}
}
