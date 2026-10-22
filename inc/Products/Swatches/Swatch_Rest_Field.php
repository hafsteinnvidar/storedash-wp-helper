<?php
declare(strict_types=1);

/**
 * `storedash_swatch` REST field on WooCommerce attribute terms.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `storedash_swatch` to `wc/v3/products/attributes/{id}/terms` (GET, PUT,
 * and `terms/batch` updates). Permission is WooCommerce's own for those
 * routes (`manage_product_terms`).
 *
 * GET  → null when no provider is active, else
 *        { provider, color, color2, image_id, image_url, tooltip } (nulls when unset).
 * PUT  → { color?, color2?, image_id?, image_src? }. A present key writes;
 *        null or "" deletes. `image_src` is a URL: an existing attachment with
 *        that URL (or that `_source_url`) is reused, otherwise it is sideloaded.
 *        `image_id` wins when both are sent. 409 when no provider is active.
 *
 * WooCommerce's terms controller ignores the return value of additional-field
 * update callbacks (single and batch), so an error from update_value() would
 * be swallowed with a 200. Validation therefore runs earlier, in
 * `rest_request_before_callbacks` (guard_request): errors come back as real
 * 409/400 responses, `image_src` is resolved to `image_id` there, and the
 * request carries the sanitized value on to WooCommerce. A batch is validated
 * as a whole: one bad swatch rejects the batch before anything is written.
 *
 * @since 1.29.0
 */
class Swatch_Rest_Field {

	/** Field name on the term response. */
	public const FIELD = 'storedash_swatch';

	/** WooCommerce REST object type for attribute terms. */
	public const OBJECT_TYPE = 'product_attribute_term';

	/** WooCommerce attribute-term write routes (single, collection, batch; v1–v3). */
	public const ROUTE_PATTERN = '#^/wc/v[1-3]/products/attributes/\d+/terms(?:/\d+|/batch)?/?$#';

	/**
	 * Provider resolver.
	 *
	 * @var Provider_Resolver
	 */
	private $resolver;

	/**
	 * `function( string $url ): int|\WP_Error` — image_src → attachment id.
	 *
	 * @var callable
	 */
	private $image_resolver;

	/**
	 * `function( int $id ): bool` — attachment exists and is an image.
	 *
	 * @var callable
	 */
	private $attachment_check;

	/**
	 * Constructor.
	 *
	 * @param Provider_Resolver $resolver         Provider resolver.
	 * @param callable|null     $image_resolver   Override for image_src resolution (tests).
	 * @param callable|null     $attachment_check Override for the attachment check (tests).
	 */
	public function __construct( Provider_Resolver $resolver, ?callable $image_resolver = null, ?callable $attachment_check = null ) {
		$this->resolver         = $resolver;
		$this->image_resolver   = $image_resolver ?? array( $this, 'resolve_image_src' );
		$this->attachment_check = $attachment_check ?? array( $this, 'is_image_attachment' );
	}

	/**
	 * Register the field (call on rest_api_init).
	 */
	public function register(): void {
		register_rest_field(
			self::OBJECT_TYPE,
			self::FIELD,
			array(
				'get_callback'    => array( $this, 'get_value' ),
				'update_callback' => array( $this, 'update_value' ),
				'schema'          => self::schema(),
			)
		);

		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_request' ), 10, 3 );
	}

	/**
	 * Validate + sanitize `storedash_swatch` on attribute-term writes before
	 * WooCommerce runs (see class doc). Runs before the route's own permission
	 * check, so it only acts for users WooCommerce would let through
	 * (`manage_product_terms`); everyone else falls through to WC's 401/403.
	 *
	 * @param mixed $response Response so far (non-null = already decided).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public function guard_request( $response, $handler = null, $request = null ) {
		if ( null !== $response || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS', 'DELETE' ), true ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( ! preg_match( self::ROUTE_PATTERN, $route ) ) {
			return $response;
		}

		$is_batch = (bool) preg_match( '#/batch/?$#', $route );
		$targets  = array();
		if ( $is_batch ) {
			foreach ( array( 'create', 'update' ) as $action ) {
				$items = $request->get_param( $action );
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $index => $item ) {
					if ( is_array( $item ) && isset( $item[ self::FIELD ] ) ) {
						$targets[] = array( $action, $index );
					}
				}
			}
		} elseif ( null !== $request->get_param( self::FIELD ) ) {
			$targets[] = array( null, null );
		}

		if ( empty( $targets ) || ! $this->can_edit_terms() ) {
			return $response;
		}

		if ( null === $this->resolver->active() ) {
			return self::no_provider();
		}

		$batch = array();
		foreach ( $targets as $target ) {
			list( $action, $index ) = $target;

			if ( null === $action ) {
				$prepared = $this->normalize_payload( $request->get_param( self::FIELD ) );
				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}
				$request->set_param( self::FIELD, $prepared );
				continue;
			}

			if ( ! isset( $batch[ $action ] ) ) {
				$batch[ $action ] = $request->get_param( $action );
			}
			$item     = $batch[ $action ][ $index ];
			$prepared = $this->normalize_payload( $item[ self::FIELD ] );
			if ( is_wp_error( $prepared ) ) {
				$data         = (array) $prepared->get_error_data();
				$data['item'] = array(
					'action' => $action,
					'index'  => $index,
					'id'     => isset( $item['id'] ) ? absint( $item['id'] ) : null,
				);
				return new \WP_Error(
					$prepared->get_error_code(),
					/* translators: 1: batch action, 2: item index, 3: error message. */
					sprintf( __( '%1$s[%2$s]: %3$s', 'storedash' ), $action, $index, $prepared->get_error_message() ),
					$data
				);
			}
			$batch[ $action ][ $index ][ self::FIELD ] = $prepared;
		}

		foreach ( $batch as $action => $items ) {
			$request->set_param( $action, $items );
		}

		return $response;
	}

	/**
	 * Same capability WooCommerce's attribute-term routes require.
	 *
	 * @return bool
	 */
	protected function can_edit_terms(): bool {
		return current_user_can( 'manage_product_terms' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce product-term capability.
	}

	/**
	 * Field schema. Input types are deliberately loose (`""` means delete);
	 * the update callback does the real validation.
	 *
	 * @return array
	 */
	public static function schema(): array {
		$nullable = static function ( $types, $description ) {
			return array(
				'description' => $description,
				'type'        => array_merge( (array) $types, array( 'null' ) ),
				'context'     => array( 'view', 'edit' ),
			);
		};

		return array(
			'description' => __( 'Variation swatch (color / image) from the active swatch plugin. Null when none is active.', 'storedash' ),
			'type'        => array( 'object', 'null' ),
			'context'     => array( 'view', 'edit' ),
			'properties'  => array(
				'provider'  => array(
					'description' => __( 'Active swatch plugin.', 'storedash' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'color'     => $nullable( 'string', __( 'Hex color.', 'storedash' ) ),
				'color2'    => $nullable( 'string', __( 'Second hex color (dual color).', 'storedash' ) ),
				'image_id'  => $nullable( array( 'integer', 'string' ), __( 'Image attachment ID.', 'storedash' ) ),
				'image_url' => array(
					'description' => __( 'Image thumbnail URL.', 'storedash' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'image_src' => $nullable( 'string', __( 'Write-only: image URL to reuse or sideload.', 'storedash' ) ),
				'tooltip'   => array(
					'description' => __( 'Tooltip text.', 'storedash' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * GET callback.
	 *
	 * @param array|object $item       Prepared term response data (has `id`).
	 * @param string       $field_name Field name.
	 * @param mixed        $request    Request.
	 * @return array|null
	 */
	public function get_value( $item, $field_name = '', $request = null ): ?array {
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			return null;
		}

		$term_id = absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : ( $item->term_id ?? 0 ) );

		return array_merge( array( 'provider' => $provider->slug() ), $provider->read( $term_id ) );
	}

	/**
	 * PUT / batch update callback.
	 *
	 * @param mixed  $value      Field value from the request.
	 * @param mixed  $term       WP_Term being updated.
	 * @param string $field_name Field name.
	 * @return true|\WP_Error
	 */
	public function update_value( $value, $term, $field_name = '' ) {
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			return self::log_error( self::no_provider() );
		}

		$term_id = absint( is_object( $term ) ? ( $term->term_id ?? 0 ) : $term );
		if ( $term_id <= 0 ) {
			return self::log_error( self::invalid( 'storedash_invalid_swatch', __( 'Unknown attribute term.', 'storedash' ) ) );
		}

		// Normally already sanitized by guard_request(); re-checked for writes
		// that bypass the REST server (e.g. direct controller calls).
		$data = $this->normalize_payload( $value );
		if ( is_wp_error( $data ) ) {
			return self::log_error( $data );
		}

		$result = $provider->write( $term_id, $data );
		return is_wp_error( $result ) ? self::log_error( $result ) : $result;
	}

	/**
	 * 409 no-provider error.
	 *
	 * @return \WP_Error
	 */
	private static function no_provider(): \WP_Error {
		return new \WP_Error(
			'storedash_no_swatch_provider',
			__( 'No variation swatches plugin is active on this store.', 'storedash' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * WooCommerce drops update-callback errors, so log them before returning.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_Error
	 */
	private static function log_error( \WP_Error $error ): \WP_Error {
		if ( class_exists( 'StoreDash_Helpers' ) ) {
			\StoreDash_Helpers::log_message( '[Swatches] term swatch write failed: ' . $error->get_error_code() . ' ' . $error->get_error_message(), 'warning' );
		}
		return $error;
	}

	/**
	 * Validate + sanitize a PUT payload into the provider write shape.
	 *
	 * Image sources are resolved last, so an invalid color never triggers a
	 * sideload.
	 *
	 * @param mixed $value Raw field value.
	 * @return array|\WP_Error Subset of {color, color2, image_id}; null = delete.
	 */
	public function normalize_payload( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return self::invalid( 'storedash_invalid_swatch', __( 'The swatch must be an object.', 'storedash' ) );
		}

		$data = array();

		foreach ( array( 'color', 'color2' ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}
			$raw = $value[ $key ];
			if ( self::is_blank( $raw ) ) {
				$data[ $key ] = null;
				continue;
			}
			$hex = is_string( $raw ) ? sanitize_hex_color( trim( $raw ) ) : null;
			if ( ! is_string( $hex ) || '' === $hex ) {
				return self::invalid(
					'storedash_invalid_swatch_color',
					/* translators: %s: field name (color or color2). */
					sprintf( __( '%s must be a hex color like #1a2b3c.', 'storedash' ), $key )
				);
			}
			$data[ $key ] = $hex;
		}

		if ( array_key_exists( 'image_id', $value ) ) {
			$raw = $value['image_id'];
			if ( self::is_blank( $raw ) || 0 === $raw || '0' === $raw ) {
				$data['image_id'] = null;
			} elseif ( ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) && (int) $raw > 0 ) {
				$id = (int) $raw;
				if ( ! call_user_func( $this->attachment_check, $id ) ) {
					return self::invalid( 'storedash_invalid_swatch_image', __( 'image_id is not an image attachment.', 'storedash' ) );
				}
				$data['image_id'] = $id;
			} else {
				return self::invalid( 'storedash_invalid_swatch_image', __( 'image_id must be a positive integer.', 'storedash' ) );
			}
		} elseif ( array_key_exists( 'image_src', $value ) ) {
			$raw = $value['image_src'];
			if ( self::is_blank( $raw ) ) {
				$data['image_id'] = null;
			} else {
				$url = is_string( $raw ) ? esc_url_raw( trim( $raw ) ) : '';
				if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
					return self::invalid( 'storedash_invalid_swatch_image', __( 'image_src must be an http(s) URL.', 'storedash' ) );
				}
				$id = call_user_func( $this->image_resolver, $url );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$data['image_id'] = (int) $id;
			}
		}

		return $data;
	}

	/**
	 * Resolve an image URL to an attachment id: an attachment already at that
	 * URL (full size, or a resized `-WxH` variant), one previously sideloaded
	 * from it (`_source_url`), or a fresh sideload.
	 *
	 * @param string $url Image URL.
	 * @return int|\WP_Error
	 */
	public function resolve_image_src( string $url ) {
		$id = (int) attachment_url_to_postid( $url );
		if ( $id <= 0 ) {
			$full = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
			if ( is_string( $full ) && $full !== $url ) {
				$id = (int) attachment_url_to_postid( $full );
			}
		}
		if ( $id > 0 ) {
			return $id;
		}

		$previous = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! empty( $previous ) ) {
			return (int) $previous[0];
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$sideloaded = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $sideloaded ) ) {
			return self::invalid(
				'storedash_swatch_image_sideload_failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not download the swatch image: %s', 'storedash' ), $sideloaded->get_error_message() )
			);
		}

		return (int) $sideloaded;
	}

	/**
	 * Whether an id is an image attachment.
	 *
	 * @param int $id Attachment id.
	 * @return bool
	 */
	public function is_image_attachment( int $id ): bool {
		return 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id );
	}

	/**
	 * Null, empty string or whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function is_blank( $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/**
	 * 400 error helper.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function invalid( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
