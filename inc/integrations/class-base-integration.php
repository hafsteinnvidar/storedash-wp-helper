<?php
/**
 * Base Integration Class
 *
 * Abstract base class that all Storedash integrations must extend.
 * Provides common functionality and enforces a consistent structure
 * for all plugin integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class StoreDash_Base_Integration {
	/**
	 * Integration ID (must be unique)
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Integration display name
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Integration description
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Minimum required version of the integrated plugin
	 *
	 * @var string
	 */
	protected $min_version;

	/**
	 * Main plugin class or function to check for availability
	 *
	 * @var string
	 */
	protected $plugin_class;

	/**
	 * Integration version
	 *
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * Check if the integration is available (plugin is active and meets requirements)
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * Get the data schema for this integration
	 *
	 * @return array Schema definition
	 */
	abstract public function get_data_schema(): array;

	/**
	 * Transform data from WordPress format to Storedash format
	 *
	 * @param mixed  $data Raw data from WordPress
	 * @param string $entity_type Type of entity (product, order, etc.)
	 * @return array Transformed data
	 */
	abstract public function transform_data( $data, string $entity_type = 'product' ): array;

	/**
	 * Get integration ID
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get integration name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get integration description
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Get integration version
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Log debug messages
	 *
	 * @param string $message
	 * @param mixed  $data
	 */
	protected function debug_log( $message, $data = null ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$log_message = '[Storedash ' . $this->name . '] ' . $message;
			if ( $data !== null ) {
				$log_message .= ': ' . print_r( $data, true );
			}
			error_log( $log_message );
		}
	}

	/**
	 * Sanitize and validate data based on schema
	 *
	 * @param array $data
	 * @param array $schema
	 * @return array Sanitized data
	 */
	protected function sanitize_data( array $data, array $schema ): array {
		$sanitized = array();

		foreach ( $schema as $key => $rules ) {
			if ( ! isset( $data[ $key ] ) ) {
				if ( isset( $rules['default'] ) ) {
					$sanitized[ $key ] = $rules['default'];
				}
				continue;
			}

			$value = $data[ $key ];

			switch ( $rules['type'] ) {
				case 'string':
					$sanitized[ $key ] = sanitize_text_field( $value );
					break;
				case 'textarea':
					$sanitized[ $key ] = sanitize_textarea_field( $value );
					break;
				case 'url':
					$sanitized[ $key ] = esc_url_raw( $value );
					break;
				case 'boolean':
					$sanitized[ $key ] = (bool) $value;
					break;
				case 'integer':
					$sanitized[ $key ] = intval( $value );
					break;
				case 'number':
					$sanitized[ $key ] = floatval( $value );
					break;
				case 'array':
					$sanitized[ $key ] = is_array( $value ) ? $value : array();
					break;
				default:
					$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Get product by ID
	 *
	 * @param int $product_id
	 * @return WC_Product|null
	 */
	protected function get_product( int $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->debug_log( 'Product not found', $product_id );
			return null;
		}
		return $product;
	}

	/**
	 * Handle API errors consistently
	 *
	 * @param string $message
	 * @param int    $code
	 * @param mixed  $data
	 * @return WP_Error
	 */
	protected function api_error( $message, $code = 500, $data = null ) {
		return new WP_Error(
			$this->id . '_error',
			$message,
			array(
				'status' => $code,
				'data'   => $data,
			)
		);
	}
}
