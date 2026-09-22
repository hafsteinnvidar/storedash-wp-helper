<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Media Controller for Storedash
 */
class StoreDash_API_Media {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'storedash/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'media';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Routes are registered by the main API class
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Support both namespaces for backward compatibility
		$namespaces = array( 'storedash/v1' );

		foreach ( $namespaces as $namespace ) {
			$this->register_namespace_routes( $namespace );
		}
	}

	/**
	 * Register routes for a specific namespace.
	 *
	 * @param string $namespace The namespace to register routes for.
	 */
	protected function register_namespace_routes( $namespace ) {
		// Endpoint for uploading media from URL
		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/upload-from-url',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_media_from_url' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url'        => array(
							'required'          => true,
							'type'              => 'string',
							'format'            => 'uri',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => function ( $param, $request, $key ) {
								return filter_var( $param, FILTER_VALIDATE_URL ) !== false;
							},
						),
						'title'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'alt'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'product_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// New endpoint for fetching media library images
		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/gallery',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_media_gallery' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 20,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'default'           => '',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Endpoint for updating media metadata
		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_media_metadata' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'          => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
						),
						'title'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'alt'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'caption'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
						'description' => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_media' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for media endpoints
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get media gallery images
	 */
	public function get_media_gallery( $request ) {
		try {
			$page     = absint( $request->get_param( 'page' ) ) ?: 1;
			$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
			$search   = sanitize_text_field( $request->get_param( 'search' ) ) ?: '';

			// Ensure per_page is within limits
			$per_page = min( max( $per_page, 1 ), 100 );

			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			// Add search if provided
			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}

			// Get full post objects (not just IDs) to avoid N get_post() calls
			$query = new WP_Query( $args );

			// Prime the post meta cache for all attachments in a single query
			$attachment_ids = wp_list_pluck( $query->posts, 'ID' );
			if ( ! empty( $attachment_ids ) ) {
				update_meta_cache( 'post', $attachment_ids );
			}

			$images = array();

			// Build attachment data using already-fetched post objects and primed meta cache
			foreach ( $query->posts as $attachment ) {
				$attachment_id = $attachment->ID;
				$full_url      = wp_get_attachment_url( $attachment_id );
				if ( ! $full_url ) {
					continue;
				}

				$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
				$medium_url    = wp_get_attachment_image_url( $attachment_id, 'medium' );

				// Get image metadata (hits primed meta cache)
				$metadata  = wp_get_attachment_metadata( $attachment_id );
				$file_size = '';

				if ( isset( $metadata['filesize'] ) ) {
					$file_size = size_format( $metadata['filesize'] );
				} else {
					$file_path = get_attached_file( $attachment_id );
					if ( $file_path && file_exists( $file_path ) ) {
						$file_size = size_format( filesize( $file_path ) );
					}
				}

				$images[] = array(
					'id'            => $attachment_id,
					'title'         => $attachment->post_title ?: basename( get_attached_file( $attachment_id ) ),
					'filename'      => basename( get_attached_file( $attachment_id ) ) ?: '',
					'url'           => $full_url,
					'thumbnail_url' => $thumbnail_url ?: $full_url,
					'medium_url'    => $medium_url ?: $full_url,
					'alt'           => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '',
					'caption'       => $attachment->post_excerpt ?: '',
					'description'   => $attachment->post_content ?: '',
					'mime_type'     => $attachment->post_mime_type ?: '',
					'date_created'  => $attachment->post_date ?: '',
					'file_size'     => $file_size,
					'width'         => isset( $metadata['width'] ) ? intval( $metadata['width'] ) : null,
					'height'        => isset( $metadata['height'] ) ? intval( $metadata['height'] ) : null,
				);
			}

			return rest_ensure_response(
				array(
					'images'       => $images,
					'total'        => intval( $query->found_posts ),
					'pages'        => intval( $query->max_num_pages ),
					'current_page' => $page,
					'per_page'     => $per_page,
					'success'      => true,
				)
			);

		} catch ( Exception $e ) {
			\StoreDash_Helpers::debug_log( 'Media Gallery Error: ' . $e->getMessage() );
			return new WP_Error(
				'gallery_error',
				'Failed to load media gallery',
				array(
					'status'  => 500,
					'details' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Check if a URL points to an internal or private network address (SSRF protection).
	 *
	 * Rejects non-HTTP(S) schemes, resolves the hostname to every IPv4 record, and
	 * validates each against private, reserved, and link-local ranges. This is the
	 * first-hop guard; redirect hops are validated separately by forcing
	 * reject_unsafe_urls on the actual download request (see upload_media_from_url()).
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if the URL targets an internal address (or is otherwise unsafe).
	 */
	private function is_internal_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return true; // No host = reject.
		}

		// Only http/https may be fetched — block file://, gopher://, dict://, etc.
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}

		// Strip IPv6 brackets so a literal address validates.
		$host = strtolower( trim( $parts['host'], '[]' ) );

		// Explicit blocklist for common loopback/metadata names.
		$blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '169.254.169.254', 'metadata.google.internal' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return true;
		}

		// Build the set of IPs to validate: a literal IP host, or every resolved A
		// AND AAAA record (a dual-stack host with a public A but a private AAAA must
		// not slip through — the transport may connect over IPv6).
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = gethostbynamel( $host ); // All IPv4 records; false on failure.
			$ips = ( false === $ips ) ? array() : $ips;

			// Enumerate IPv6 (AAAA) records too, when the resolver is available.
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record emits warnings on lookup failure; a failed AAAA lookup is not fatal and IPv4 records are still validated.
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $record ) {
						if ( ! empty( $record['ipv6'] ) ) {
							$ips[] = $record['ipv6'];
						}
					}
				}
			}

			if ( empty( $ips ) ) {
				return true; // No resolvable address — block to be safe.
			}
		}

		// Reject private and reserved ranges (covers 10.x, 172.16-31.x, 192.168.x,
		// 169.254.x link-local, ::1, and other reserved ranges). Any single bad
		// record blocks the whole host.
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Upload media from URL
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media_from_url( $request ) {
		try {
			$url        = $request->get_param( 'url' );
			$title      = $request->get_param( 'title' );
			$alt        = $request->get_param( 'alt' );
			$product_id = $request->get_param( 'product_id' );

			// SSRF protection — block requests to internal/private addresses
			if ( $this->is_internal_url( $url ) ) {
				return new WP_Error(
					'ssrf_blocked',
					__( 'Requests to internal or private network addresses are not allowed.', 'storedash' ),
					array( 'status' => 403 )
				);
			}

			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Starting for ' . $url );
			if ( $product_id ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL: Will attach to product ID ' . $product_id );
			}

			// Include required WordPress files
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Using manual download method' );
			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Attempting to download from ' . $url );

			// Download file to temp location. Force reject_unsafe_urls so WP_Http
			// validates the URL (and redirect targets) against wp_http_validate_url(),
			// which blocks private/reserved IPv4 and non-standard ports — hardening the
			// redirect-to-metadata bypass that the first-hop is_internal_url() check
			// alone cannot cover. Note: neither layer pins the resolved IP, so a
			// determined DNS-rebinding attacker with a dual-stack/rotating record is
			// not fully defeated; is_internal_url() now also checks AAAA records to
			// narrow the IPv6 gap.
			$reject_unsafe_urls = static function ( $args ) {
				$args['reject_unsafe_urls'] = true;
				return $args;
			};
			add_filter( 'http_request_args', $reject_unsafe_urls, 100 );
			try {
				$tmp = download_url( $url, 60 ); // 60 second timeout for large images.
			} finally {
				remove_filter( 'http_request_args', $reject_unsafe_urls, 100 );
			}

			if ( is_wp_error( $tmp ) ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL Error: Failed to download - ' . $tmp->get_error_message() );
				\StoreDash_Helpers::debug_log( 'Media Upload from URL Error: URL was ' . $url );

				// Try to provide more detailed error information
				$error_details = array(
					'url'        => $url,
					'error'      => $tmp->get_error_message(),
					'error_code' => $tmp->get_error_code(),
				);

				return new WP_Error(
					'download_failed',
					__( 'Failed to download image from URL. The URL may not be accessible from this server.', 'storedash' ),
					array(
						'status'  => 400,
						'details' => $error_details,
					)
				);
			}

			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Successfully downloaded to temp file ' . $tmp );

			// Get file name from URL
			$file_array         = array();
			$file_array['name'] = basename( wp_parse_url( $url, PHP_URL_PATH ) );

			// If no filename in URL, generate one
			if ( empty( $file_array['name'] ) || $file_array['name'] === '/' ) {
				$file_array['name'] = 'image-' . time();

				// Try to get extension from mime type
				$file_info = wp_check_filetype( $tmp );
				if ( $file_info['ext'] ) {
					$file_array['name'] .= '.' . $file_info['ext'];
				}
			}

			$file_array['tmp_name'] = $tmp;

			// Use title if provided, otherwise use filename
			$post_title = $title ?: preg_replace( '/\.[^.]+$/', '', $file_array['name'] );

			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Processing file ' . $file_array['name'] . ' with title ' . $post_title );

			// Do the validation and storage stuff
			// The 0 parameter means no parent post (orphaned attachment)
			$attach_id = media_handle_sideload( $file_array, 0, $post_title );

			// Clean up temp file
			if ( file_exists( $tmp ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort temp cleanup, failure is acceptable
				@unlink( $tmp );
			}

			if ( is_wp_error( $attach_id ) ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL Error: media_handle_sideload failed - ' . $attach_id->get_error_message() );
				return new WP_Error(
					'sideload_failed',
					__( 'Failed to process uploaded image.', 'storedash' ),
					array(
						'status'  => 500,
						'details' => $attach_id->get_error_message(),
					)
				);
			}

			\StoreDash_Helpers::debug_log( 'Media Upload from URL: Created attachment ID ' . $attach_id );

			// Update alt text if provided
			if ( $alt ) {
				update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
			}

			// Verify the attachment was created properly
			$attachment = get_post( $attach_id );
			if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL Error: Attachment not found after creation' );
				return new WP_Error(
					'attachment_not_found',
					__( 'Attachment was created but cannot be found.', 'storedash' ),
					array( 'status' => 500 )
				);
			}

			// Get URLs for response
			$attachment_url = wp_get_attachment_url( $attach_id );
			$thumbnail_url  = wp_get_attachment_image_url( $attach_id, 'thumbnail' );
			$medium_url     = wp_get_attachment_image_url( $attach_id, 'medium' );

			// Verify we have a valid URL
			if ( ! $attachment_url ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL Error: Could not get attachment URL for ID ' . $attach_id );
				// Try to get the URL from the attachment guid
				$attachment_url = $attachment->guid;
			}

			\StoreDash_Helpers::debug_log( 'Media Upload from URL Success: Attachment ID ' . $attach_id . ' created with URL ' . $attachment_url );

			// DO NOT automatically attach to product - just log the product context
			// The image is now in the media library but not attached to any product
			// User must explicitly select it and save the product
			if ( $product_id ) {
				\StoreDash_Helpers::debug_log( 'Media Upload from URL: Image uploaded for product context ' . $product_id . ' but not attached (user must select and save)' );
			}

			return rest_ensure_response(
				array(
					'success'             => true,
					'id'                  => $attach_id,
					'url'                 => $attachment_url,
					'thumbnail_url'       => $thumbnail_url ?: $attachment_url,
					'medium_url'          => $medium_url ?: $attachment_url,
					'filename'            => basename( get_attached_file( $attach_id ) ),
					'attached_to_product' => false, // Never auto-attach - user must select and save
				)
			);

		} catch ( Exception $e ) {
			\StoreDash_Helpers::debug_log( 'Media Upload from URL Exception: ' . $e->getMessage() );
			return new WP_Error(
				'upload_exception',
				__( 'An unexpected error occurred during upload.', 'storedash' ),
				array(
					'status'  => 500,
					'details' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Update media metadata
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Permanently delete a media attachment.
	 *
	 * Attachments are never trashed: WordPress core also forces `force=true` on
	 * `DELETE /wp/v2/media/<id>`, because a trashed attachment leaves the file on
	 * disk while disappearing from the library. `wp_delete_attachment( $id, true )`
	 * is the same call core makes, so files, sizes, and meta all go with it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public function delete_media( $request ) {
		try {
			$attachment_id = absint( $request->get_param( 'id' ) );

			$attachment = get_post( $attachment_id );
			if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Invalid attachment ID.', 'storedash' ),
					array( 'status' => 404 )
				);
			}

			$deleted = wp_delete_attachment( $attachment_id, true );

			if ( ! $deleted ) {
				return new WP_Error(
					'delete_failed',
					__( 'Failed to delete attachment.', 'storedash' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'success' => true,
				'data'    => array(
					'id'      => $attachment_id,
					'deleted' => true,
				),
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'delete_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function update_media_metadata( $request ) {
		try {
			$attachment_id = absint( $request->get_param( 'id' ) );

			// Check if attachment exists
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Invalid attachment ID.', 'storedash' ),
					array( 'status' => 404 )
				);
			}

			// Prepare update data
			$update_data = array();
			$update_post = false;

			// Handle title update
			if ( $request->has_param( 'title' ) ) {
				$update_data['post_title'] = sanitize_text_field( $request->get_param( 'title' ) );
				$update_post               = true;
			}

			// Handle caption update
			if ( $request->has_param( 'caption' ) ) {
				$update_data['post_excerpt'] = wp_kses_post( $request->get_param( 'caption' ) );
				$update_post                 = true;
			}

			// Handle description update
			if ( $request->has_param( 'description' ) ) {
				$update_data['post_content'] = wp_kses_post( $request->get_param( 'description' ) );
				$update_post                 = true;
			}

			// Update post data if needed
			if ( $update_post ) {
				$update_data['ID'] = $attachment_id;
				$result            = wp_update_post( $update_data, true );

				if ( is_wp_error( $result ) ) {
					return new WP_Error(
						'update_failed',
						__( 'Failed to update attachment.', 'storedash' ),
						array(
							'status'  => 500,
							'details' => $result->get_error_message(),
						)
					);
				}
			}

			// Handle alt text update separately (it's stored as post meta)
			if ( $request->has_param( 'alt' ) ) {
				$alt_text = sanitize_text_field( $request->get_param( 'alt' ) );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
			}

			// Get updated attachment data
			$updated_attachment = get_post( $attachment_id );
			$full_url           = wp_get_attachment_url( $attachment_id );
			$thumbnail_url      = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			$medium_url         = wp_get_attachment_image_url( $attachment_id, 'medium' );
			$metadata           = wp_get_attachment_metadata( $attachment_id );

			// Get file size
			$file_size = '';
			if ( isset( $metadata['filesize'] ) ) {
				$file_size = size_format( $metadata['filesize'] );
			} else {
				$file_path = get_attached_file( $attachment_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$file_size = size_format( filesize( $file_path ) );
				}
			}

			// Return updated media data
			$response = array(
				'success' => true,
				'data'    => array(
					'id'            => $attachment_id,
					'title'         => $updated_attachment->post_title ?: basename( get_attached_file( $attachment_id ) ),
					'filename'      => basename( get_attached_file( $attachment_id ) ) ?: '',
					'url'           => $full_url,
					'thumbnail_url' => $thumbnail_url ?: $full_url,
					'medium_url'    => $medium_url ?: $full_url,
					'alt'           => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '',
					'caption'       => $updated_attachment->post_excerpt ?: '',
					'description'   => $updated_attachment->post_content ?: '',
					'mime_type'     => $updated_attachment->post_mime_type ?: '',
					'date_created'  => $updated_attachment->post_date ?: '',
					'file_size'     => $file_size,
					'width'         => isset( $metadata['width'] ) ? intval( $metadata['width'] ) : null,
					'height'        => isset( $metadata['height'] ) ? intval( $metadata['height'] ) : null,
				),
			);

			return rest_ensure_response( $response );

		} catch ( Exception $e ) {
			\StoreDash_Helpers::debug_log( 'Media Update Error: ' . $e->getMessage() );
			return new WP_Error(
				'update_error',
				__( 'Failed to update media metadata.', 'storedash' ),
				array(
					'status'  => 500,
					'details' => $e->getMessage(),
				)
			);
		}
	}
}
