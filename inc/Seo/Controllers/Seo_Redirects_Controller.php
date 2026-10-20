<?php
/**
 * `GET/PUT /storedash/v1/seo/redirects`, `GET /storedash/v1/seo/redirects/hits`.
 *
 * Storedash pushes its `seo_redirects` rows here (B4b). PUT replaces the
 * merchant rows; auto rows (slug change / trash, see Redirect_Manager)
 * survive unless the body carries the same `source_path` or lists it in the
 * optional `remove: [source_path]` key. The `hash` is opaque — same contract
 * as `/seo/settings`.
 *
 * @package StoreDash\Seo\Controllers
 * @since   1.27.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Redirect_Manager;
use WP_Error;

/**
 * Redirects push + hits.
 *
 * @since 1.27.0
 */
class Seo_Redirects_Controller extends Abstract_Seo_Controller {

	/**
	 * Max hash length.
	 */
	const MAX_HASH = 500;

	/**
	 * Manager.
	 *
	 * @var Redirect_Manager
	 */
	protected $manager;

	/**
	 * Constructor.
	 *
	 * @param Redirect_Manager|null $manager Manager (injectable for tests).
	 */
	public function __construct( $manager = null ) {
		$this->manager = $manager instanceof Redirect_Manager ? $manager : new Redirect_Manager();
	}

	/**
	 * GET /seo/redirects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_redirects( $request ) {
		return $this->success( $this->payload() );
	}

	/**
	 * PUT /seo/redirects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function put_redirects( $request ) {
		$body = $this->body( $request );

		if ( ! isset( $body['hash'] ) || ! is_string( $body['hash'] ) || '' === trim( $body['hash'] ) || strlen( $body['hash'] ) > self::MAX_HASH ) {
			return $this->error( 'storedash_seo_invalid_hash', 'hash must be a non-empty string of at most ' . self::MAX_HASH . ' characters' );
		}
		if ( ! isset( $body['redirects'] ) || ! is_array( $body['redirects'] ) ) {
			return $this->error( 'storedash_seo_invalid_redirects', 'redirects must be an array' );
		}
		if ( count( $body['redirects'] ) > Redirect_Manager::MAX_ROWS ) {
			return $this->error( 'storedash_seo_redirects_cap', 'at most ' . Redirect_Manager::MAX_ROWS . ' redirects are supported (got ' . count( $body['redirects'] ) . ')' );
		}

		$valid  = Redirect_Manager::validate_rows( array_values( $body['redirects'] ), $this->manager->home() );
		$remove = Redirect_Manager::validate_remove( array_key_exists( 'remove', $body ) ? $body['remove'] : null );
		if ( $remove['errors'] ) {
			return new WP_Error(
				'storedash_seo_invalid_remove',
				'remove must list source paths; nothing stored',
				array(
					'status' => 400,
					'errors' => $remove['errors'],
				)
			);
		}
		foreach ( $remove['paths'] as $path ) {
			if ( isset( $valid['rows'][ $path ] ) ) {
				$valid['errors'][] = array(
					'index'  => -1,
					'reason' => 'source_path ' . $path . ' is both in redirects and in remove',
				);
			}
		}
		if ( $valid['errors'] ) {
			return new WP_Error(
				'storedash_seo_invalid_redirects',
				count( $valid['errors'] ) . ' invalid redirect row(s); nothing stored',
				array(
					'status' => 400,
					'errors' => $valid['errors'],
				)
			);
		}

		if ( ! $this->manager->replace_merchant_rows( $valid['rows'], trim( $body['hash'] ), $remove['paths'] ) ) {
			return $this->error(
				'storedash_seo_redirects_cap',
				'merchant rows plus automatic rows would exceed ' . Redirect_Manager::MAX_ROWS . ' redirects; nothing stored'
			);
		}

		return $this->success( $this->payload() );
	}

	/**
	 * GET /seo/redirects/hits — returns and clears the counter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function get_hits( $request ) {
		$drained = Redirect_Manager::drain_hits();
		return $this->success(
			array(
				// Empty PHP arrays encode as `[]`; the contract is an object.
				'hits'  => $drained['hits'] ? $drained['hits'] : new \stdClass(),
				'since' => $drained['since'],
			)
		);
	}

	/**
	 * `{ redirects, count, hash }`.
	 *
	 * @return array
	 */
	protected function payload(): array {
		$rows = $this->manager->export();
		return array(
			'redirects' => $rows,
			'count'     => count( $rows ),
			'hash'      => $this->manager->hash(),
		);
	}
}
