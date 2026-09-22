<?php
/**
 * Tests for Discount_DB_Handler active-discount reads.
 *
 * Focuses on the DB-error path: a failed get_results() must NOT be cached and
 * must return an array, so downstream consumers (usort/count inside a price
 * display filter) never receive null and fatal on every catalog page.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Sync\Discount_DB_Handler;

/**
 * Minimal wpdb double exposing only what the reads touch.
 */
if ( ! class_exists( 'Fake_WPDB_For_Discounts' ) ) {
	class Fake_WPDB_For_Discounts {

		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $last_error = '';
		/** @var string */
		public $last_query = '';
		/** @var mixed Value returned by get_results(). */
		public $results_to_return = null;
		/** @var int Number of get_results() invocations. */
		public $get_results_calls = 0;

		public function get_results( $query ) {
			++$this->get_results_calls;
			$this->last_query = $query;
			return $this->results_to_return;
		}

		public function prepare( $query, ...$args ) {
			return $query;
		}
	}
}

// StoreDash_Helpers (capturing logger stub) is provided by tests/bootstrap.php.
require_once __DIR__ . '/../../inc/Discounts/Sync/Discount_DB_Handler.php';

/**
 * @covers \StoreDash\Discounts\Sync\Discount_DB_Handler
 */
final class Discount_DB_HandlerTest extends TestCase {

	/** @var Fake_WPDB_For_Discounts */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Discount_DB_Handler::clear_active_discounts_cache();
		StoreDash_Helpers::$logs = array();
		$this->wpdb              = new Fake_WPDB_For_Discounts();
		$GLOBALS['wpdb']         = $this->wpdb;
	}

	public function test_db_error_returns_array_not_null_and_is_not_cached(): void {
		$this->wpdb->last_error        = 'Deadlock found when trying to get lock';
		$this->wpdb->results_to_return = null; // wpdb returns null on error.

		$handler = new Discount_DB_Handler();

		$first = $handler->get_active_discounts();
		$this->assertIsArray( $first );
		$this->assertSame( array(), $first );

		// Failure must be logged as an error.
		$this->assertNotEmpty( StoreDash_Helpers::$logs );
		$this->assertSame( 'error', StoreDash_Helpers::$logs[0]['level'] );

		// Failure must NOT be cached: a second call re-queries the DB.
		$handler->get_active_discounts();
		$this->assertSame( 2, $this->wpdb->get_results_calls );
	}

	public function test_display_db_error_returns_array_not_null_and_is_not_cached(): void {
		$this->wpdb->last_error        = 'MySQL server has gone away';
		$this->wpdb->results_to_return = null;

		$handler = new Discount_DB_Handler();

		$first = $handler->get_active_display_discounts();
		$this->assertSame( array(), $first );

		$handler->get_active_display_discounts();
		$this->assertSame( 2, $this->wpdb->get_results_calls );
	}

	public function test_successful_empty_result_is_cached(): void {
		$this->wpdb->last_error        = '';
		$this->wpdb->results_to_return = array();

		$handler = new Discount_DB_Handler();

		$this->assertSame( array(), $handler->get_active_discounts() );

		// Successful (even empty) result is cached: no second query.
		$handler->get_active_discounts();
		$this->assertSame( 1, $this->wpdb->get_results_calls );
	}

	public function test_successful_result_returns_rows(): void {
		$this->wpdb->last_error        = '';
		$row                           = (object) array( 'id' => 1 );
		$this->wpdb->results_to_return = array( $row );

		$handler = new Discount_DB_Handler();

		$this->assertSame( array( $row ), $handler->get_active_discounts() );
	}
}
