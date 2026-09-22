<?php
/**
 * Daily expiry sweep.
 *
 * Bookkeeping only: the balance query already ignores expired rows, so a
 * missed run never lets expired credit be spent. The sweep writes `expire`
 * rows (and fires `credit.expired`) so Storedash reports see the movement.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Credit_Webhook;
use StoreDash\Credit\Ledger;

/**
 * WP-Cron expiry job.
 *
 * @since 1.17.0
 */
class Expiry_Cron {

	/**
	 * Cron hook.
	 */
	const HOOK = 'storedash_credit_expire';

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger|null $ledger Ledger.
	 */
	public function __construct( $ledger = null ) {
		$this->ledger = $ledger ? $ledger : new Ledger();
	}

	/**
	 * Register the handler and self-heal the daily schedule.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Expire due rows.
	 */
	public function run(): void {
		foreach ( $this->ledger->expire_due() as $result ) {
			Credit_Webhook::send( 'expired', $result['row'], $result['affected'] );
		}
	}
}
