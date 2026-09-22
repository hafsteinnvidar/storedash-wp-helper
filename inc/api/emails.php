<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storedash Helper API - Emails Controller
 *
 * Handles operations for managing WooCommerce emails
 */
class StoreDash_API_Emails {
	/**
	 * API namespace.
	 */
	public $namespace = 'storedash/v1';

	/**
	 * Route base.
	 */
	protected $rest_base = 'emails';

	/**
	 * Register the routes for emails API.
	 *
	 * @param array $namespaces Array of namespaces to register routes under (defaults to both legacy and modern).
	 */
	public function register_routes( $namespaces = null ) {
		// Default to both namespaces if not specified
		if ( $namespaces === null ) {
			$namespaces = array( 'storedash/v1' );
		}

		// Support single namespace for backward compatibility
		if ( ! is_array( $namespaces ) ) {
			$namespaces = array( $namespaces );
		}

		// Register routes under each namespace
		foreach ( $namespaces as $namespace ) {
			$this->register_routes_for_namespace( $namespace );
		}
	}

	/**
	 * Register routes for a specific namespace.
	 *
	 * @param string $namespace The namespace to register routes under.
	 */
	protected function register_routes_for_namespace( $namespace ) {
		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_emails' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_emails' ),
					'permission_callback' => array( $this, 'update_items_permissions_check' ),
					'args'                => array(
						'emails' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'id'      => array(
										'type'     => 'string',
										'required' => true,
									),
									'enabled' => array(
										'type'     => 'boolean',
										'required' => true,
									),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Check if a given request has access to get emails.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Check if a given request has access to update emails.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function update_items_permissions_check( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get all available WooCommerce emails with their status.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_emails( $request ) {
		// Get WooCommerce mailer
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		$response = array();

		foreach ( $emails as $email ) {
			$response[] = array(
				'id'          => $email->id,
				'name'        => $email->get_title(),
				'description' => $email->get_description(),
				'enabled'     => $email->is_enabled(),
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Update email settings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_emails( $request ) {
		$params           = $request->get_params();
		$emails_to_update = $params['emails'];

		// Get WooCommerce mailer
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		$updated = array();

		foreach ( $emails_to_update as $email_data ) {
			$email_id   = $email_data['id'];
			$is_enabled = $email_data['enabled'];

			// Find the email in the mailer
			foreach ( $emails as $email ) {
				if ( $email->id === $email_id ) {
					// Get the option name that controls whether the email is enabled
					$option_name = 'woocommerce_' . $email->id . '_settings';
					$options     = get_option( $option_name, array() );

					// Update the 'enabled' setting
					$options['enabled'] = $is_enabled ? 'yes' : 'no';
					update_option( $option_name, $options );

					$updated[] = array(
						'id'      => $email_id,
						'enabled' => $is_enabled,
					);
					break;
				}
			}
		}

		if ( empty( $updated ) ) {
			return new WP_Error(
				'woodash_no_emails_updated',
				__( 'No emails were updated.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'updated' => $updated,
				'message' => __( 'Email settings updated successfully.', 'storedash' ),
			)
		);
	}
}
