<?php
/**
 * Read routes (contract C): card feed, ledger feed, single card.
 *
 * @package StoreDash\GiftCard\Controllers
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;

/**
 * Feed controller.
 *
 * @since 1.24.0
 */
class Gift_Card_Feed_Controller extends Abstract_Gift_Card_Controller {

	/**
	 * `GET /gift-cards?since=&page=&per_page=` (by updated_at).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cards( WP_REST_Request $request ) {
		$since = $this->parse_since( $request->get_param( 'since' ) );
		if ( is_wp_error( $since ) ) {
			return $since;
		}
		list( $page, $per_page ) = $this->paging( $request );
		$feed                    = $this->ledger->cards_feed( $since, $page, $per_page );

		return rest_ensure_response(
			array(
				'rows'     => array_map( array( $this, 'format_card' ), $feed['rows'] ),
				'total'    => (int) $feed['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * `GET /gift-cards/ledger?since=&page=&per_page=` (by created_at).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ledger( WP_REST_Request $request ) {
		$since = $this->parse_since( $request->get_param( 'since' ) );
		if ( is_wp_error( $since ) ) {
			return $since;
		}
		list( $page, $per_page ) = $this->paging( $request );
		$feed                    = $this->ledger->ledger_feed( $since, $page, $per_page );

		return rest_ensure_response(
			array(
				'rows'     => array_map( array( $this, 'format_row' ), $feed['rows'] ),
				'total'    => (int) $feed['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * `GET /gift-cards/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get( WP_REST_Request $request ) {
		$card = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		return rest_ensure_response(
			array(
				'card'   => $this->format_card( $card ),
				'ledger' => array_map( array( $this, 'format_row' ), $this->ledger->rows_for_card( (int) $card->id ) ),
			)
		);
	}

	/**
	 * Page + page size.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int[]
	 */
	protected function paging( WP_REST_Request $request ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 500, $per_page ) : 200;
		return array( $page, $per_page );
	}
}
