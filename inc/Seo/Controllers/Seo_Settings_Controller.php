<?php
/**
 * `GET/PUT /storedash/v1/seo/settings`, `GET /storedash/v1/seo/status`.
 *
 * Storedash pushes `seo_store_settings` here. The `hash` is opaque: woo-dash
 * computes it (`push_hash`) and compares it with GET to decide whether a push
 * is needed. This plugin only stores and echoes it.
 *
 * @package StoreDash\Seo\Controllers
 * @since   1.26.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Plugin_Conflict_Guard;
use StoreDash\Seo\Settings;

/**
 * Settings push + status.
 *
 * @since 1.26.0
 */
class Seo_Settings_Controller extends Abstract_Seo_Controller {

	/**
	 * Option holding the opaque hash of the last push.
	 */
	const HASH_OPTION = 'storedash_seo_settings_hash';

	/**
	 * Max length of a string setting / the hash.
	 */
	const MAX_STRING = 500;

	/**
	 * Max JSON size of an array setting (schema_org, ai_bots).
	 */
	const MAX_ARRAY_BYTES = 65536;

	/**
	 * Conflict guard.
	 *
	 * @var Plugin_Conflict_Guard
	 */
	protected $guard;

	/**
	 * Constructor.
	 *
	 * @param Plugin_Conflict_Guard|null $guard Guard (shared with Seo_Manager).
	 */
	public function __construct( $guard = null ) {
		$this->guard = $guard instanceof Plugin_Conflict_Guard ? $guard : new Plugin_Conflict_Guard();
	}

	/**
	 * GET /seo/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_settings( $request ) {
		return $this->success( $this->settings_payload() );
	}

	/**
	 * PUT /seo/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function put_settings( $request ) {
		$valid = self::validate_payload( $this->body( $request ) );
		if ( isset( $valid['error'] ) ) {
			return $this->error( $valid['error']['code'], $valid['error']['message'], 400 );
		}

		update_option( Settings::OPTION, $valid['settings'], true );
		update_option( Settings::ENABLED_OPTION, $valid['enabled'] ? '1' : '0', true );
		update_option( self::HASH_OPTION, $valid['hash'], true );
		Settings::reset_cache();

		return $this->success( $this->settings_payload() );
	}

	/**
	 * GET /seo/status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_status( $request ) {
		$hash = get_option( self::HASH_OPTION, '' );

		return $this->success(
			array(
				'enabled'           => Settings::is_enabled(),
				'conflict'          => apply_filters( 'storedash_seo_conflict', null ), // Seo_Manager::CONFLICT_FILTER.
				'version'           => defined( 'STOREDASH_VERSION' ) ? STOREDASH_VERSION : '',
				'sitemap_url'       => null,
				'detected_plugins'  => $this->guard->detected(),
				'settings_hash'     => is_string( $hash ) && '' !== $hash ? $hash : null,
				'wc_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'wp_version'        => get_bloginfo( 'version' ),
				'permalinks_pretty' => '' !== (string) get_option( 'permalink_structure', '' ),
			)
		);
	}

	/**
	 * `{ enabled, settings, hash }` as stored.
	 *
	 * @return array
	 */
	protected function settings_payload(): array {
		$hash = get_option( self::HASH_OPTION, '' );
		return array(
			'enabled'  => Settings::is_enabled(),
			'settings' => Settings::get(),
			'hash'     => is_string( $hash ) && '' !== $hash ? $hash : null,
		);
	}

	/**
	 * Validate a PUT body.
	 *
	 * Pure. `enabled` (bool), `settings` (object) and `hash` (non-empty string)
	 * are required. Unknown setting keys are rejected; missing keys and `null`
	 * values take the code defaults.
	 *
	 * @param array $body Decoded body.
	 * @return array { enabled, settings, hash } or { error: { code, message } }
	 */
	public static function validate_payload( array $body ): array {
		if ( ! isset( $body['enabled'] ) || ! is_bool( $body['enabled'] ) ) {
			return self::invalid( 'storedash_seo_invalid_enabled', 'enabled must be a boolean' );
		}
		if ( ! isset( $body['hash'] ) || ! is_string( $body['hash'] ) || '' === trim( $body['hash'] ) || strlen( $body['hash'] ) > self::MAX_STRING ) {
			return self::invalid( 'storedash_seo_invalid_hash', 'hash must be a non-empty string of at most ' . self::MAX_STRING . ' characters' );
		}
		if ( ! isset( $body['settings'] ) || ! is_array( $body['settings'] ) ) {
			return self::invalid( 'storedash_seo_invalid_settings', 'settings must be an object' );
		}

		$defaults = Settings::defaults();
		$settings = $body['settings'];

		$unknown = array_diff( array_map( 'strval', array_keys( $settings ) ), array_keys( $defaults ) );
		if ( $unknown ) {
			return self::invalid( 'storedash_seo_unknown_settings', 'unknown settings: ' . implode( ', ', $unknown ) );
		}

		$errors = array();
		$clean  = array();
		foreach ( $settings as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$default = $defaults[ $key ];
			if ( is_bool( $default ) && ! is_bool( $value ) ) {
				$errors[] = $key . ' must be a boolean';
			} elseif ( is_string( $default ) && ! is_string( $value ) ) {
				$errors[] = $key . ' must be a string';
			} elseif ( is_string( $default ) && strlen( $value ) > self::MAX_STRING ) {
				$errors[] = $key . ' must be at most ' . self::MAX_STRING . ' characters';
			} elseif ( is_string( $default ) && 'default_og_image_url' !== $key && trim( $value ) !== trim( wp_strip_all_tags( $value ) ) ) {
				// Settings::normalize() would silently store the stripped value
				// (e.g. a lone `<` separator becomes ''); reject instead.
				return self::invalid( 'storedash_seo_invalid_setting', $key . ' contains markup that would be stripped (got ' . wp_json_encode( $value ) . ')' );
			} elseif ( is_array( $default ) && ( ! is_array( $value ) || strlen( (string) wp_json_encode( $value ) ) > self::MAX_ARRAY_BYTES ) ) {
				$errors[] = $key . ' must be an object of at most ' . self::MAX_ARRAY_BYTES . ' bytes';
			} elseif ( 'default_og_image_url' === $key && '' !== trim( $value ) && '' === Settings::http_url( $value ) ) {
				$errors[] = $key . ' must be an absolute http(s) URL or empty';
			} else {
				$clean[ $key ] = $value;
			}
		}
		if ( $errors ) {
			return self::invalid( 'storedash_seo_invalid_settings', implode( '; ', $errors ) );
		}

		return array(
			'enabled'  => $body['enabled'],
			'settings' => Settings::normalize( $clean ),
			'hash'     => trim( $body['hash'] ),
		);
	}

	/**
	 * Validation error shape.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function invalid( string $code, string $message ): array {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
