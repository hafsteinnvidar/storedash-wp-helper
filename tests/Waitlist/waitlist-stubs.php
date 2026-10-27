<?php
/**
 * Minimal WordPress stubs for the standalone waitlist tests.
 *
 * Options, WP_Error, wc_get_product and StoreDash_Helpers come from
 * tests/bootstrap.php; add_action / wp_remote_post (recorded, never sent)
 * come from the opt-in stubs. This file adds the sanitisers and a $wpdb
 * double the waitlist handler touches.
 *
 * @package StoreDash\Tests
 */

require_once __DIR__ . '/../Optin/optin-stubs.php';

foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'ARRAY_A'           => 'ARRAY_A',
) as $waitlist_const => $waitlist_value ) {
	if ( ! defined( $waitlist_const ) ) {
		define( $waitlist_const, $waitlist_value );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( wp_strip_all_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'is_IS';
	}
}
if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, 'test-salt' );
	}
}

if ( ! class_exists( 'Waitlist_Test_Product' ) ) {
	/**
	 * Out-of-stock simple product double.
	 */
	class Waitlist_Test_Product extends WC_Product {
		public function is_in_stock() {
			return false;
		}
		public function get_sku() {
			return 'SKU-' . $this->id;
		}
		public function get_name() {
			return 'Product ' . $this->id;
		}
	}
}

if ( ! class_exists( 'Waitlist_Test_WPDB' ) ) {
	/**
	 * $wpdb double: records writes, serves one optional pending row.
	 */
	class Waitlist_Test_WPDB {
		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $last_error = '';
		/** @var int */
		public $insert_id = 0;
		/** @var array|null Row returned by get_row() (the shopper's pending entry). */
		public $pending_row = null;
		/** @var array<int, array> */
		public $inserts = array();
		/** @var array<int, array> */
		public $updates = array();

		/** @var array Args of the last prepare() call. */
		public $last_args = array();
		/** @var string Last get_row() query. */
		public $last_query = '';

		public function prepare( $query, ...$args ) {
			$this->last_args = $args;
			return $query;
		}

		public function get_row( $query, $output = null ) {
			$this->last_query = $query;
			return $this->pending_row;
		}

		public function insert( $table, $data ) {
			$this->inserts[] = $data;
			$this->insert_id = 900 + count( $this->inserts );
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->updates[] = array(
				'data'  => $data,
				'where' => $where,
			);
			return 1;
		}
	}
}
