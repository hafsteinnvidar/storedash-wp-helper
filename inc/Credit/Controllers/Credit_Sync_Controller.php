<?php
/**
 * `POST /credit/sync` — settings + full rule replace (contract A).
 *
 * @package StoreDash\Credit\Controllers
 * @since   1.17.0
 */

namespace StoreDash\Credit\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Rules_Repository;
use StoreDash\Credit\Settings;
use WP_REST_Request;

/**
 * Sync controller.
 *
 * @since 1.17.0
 */
class Credit_Sync_Controller extends Abstract_Credit_Controller {

	/**
	 * Rules.
	 *
	 * @var Rules_Repository
	 */
	protected $rules;

	/**
	 * Constructor.
	 *
	 * @param Rules_Repository|null $rules  Rules.
	 * @param \StoreDash\Credit\Ledger|null $ledger Ledger.
	 */
	public function __construct( $rules = null, $ledger = null ) {
		parent::__construct( $ledger );
		$this->rules = $rules ? $rules : new Rules_Repository();
	}

	/**
	 * Handle the sync.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync( WP_REST_Request $request ) {
		$data = $this->body( $request );

		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $this->error( 'storedash_credit_missing_settings', 'settings object is required' );
		}
		$rules = isset( $data['rules'] ) && is_array( $data['rules'] ) ? $data['rules'] : array();

		Settings::save( $data['settings'] );
		$count = $this->rules->replace_all( $rules );

		\StoreDash_Helpers::debug_log( 'Rewards credit synced', array( 'rules' => $count ) );

		return rest_ensure_response(
			array(
				'success'      => true,
				'rules_synced' => $count,
				'version'      => STOREDASH_VERSION,
			)
		);
	}
}
