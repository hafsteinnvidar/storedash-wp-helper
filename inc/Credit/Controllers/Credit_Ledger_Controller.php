<?php
/**
 * `GET /credit/ledger?since=&page=&per_page=` — reconciliation feed (contract B).
 *
 * @package StoreDash\Credit\Controllers
 * @since   1.17.0
 */

namespace StoreDash\Credit\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;

/**
 * Ledger feed controller.
 *
 * @since 1.17.0
 */
class Credit_Ledger_Controller extends Abstract_Credit_Controller {

	/**
	 * Paged rows created since a moment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function feed( WP_REST_Request $request ) {
		$since = (string) $request->get_param( 'since' );
		if ( '' !== $since ) {
			$ts = strtotime( $since );
			if ( false === $ts ) {
				return $this->error( 'storedash_credit_invalid_since', 'since must be an ISO-8601 datetime' );
			}
			$since = gmdate( 'Y-m-d H:i:s', $ts );
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 500, $per_page ) : 200;

		$feed = $this->ledger->feed( $since, $page, $per_page );

		return rest_ensure_response(
			array(
				'rows'     => array_map( array( $this, 'format_row' ), $feed['rows'] ),
				'total'    => (int) $feed['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}
}
