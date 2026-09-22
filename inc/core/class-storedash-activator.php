<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Activator {

	/**
	 * Latest discount table schema version. Must match the highest version step
	 * in maybe_upgrade_discount_tables(), and the CREATE TABLE in
	 * create_discount_tables() must include every column up to this version.
	 */
	private const LATEST_DISCOUNT_DB_VERSION = '1.5.0';

	/**
	 * Safe logging helper — StoreDash_Helpers may not be loaded during
	 * the activation hook (register_activation_hook fires before plugins_loaded).
	 */
	private static function log( string $message, string $level = 'debug', array $context = array() ): void {
		if ( ! class_exists( 'StoreDash_Helpers' ) ) {
			return;
		}
		if ( $level === 'error' ) {
			StoreDash_Helpers::log_message( $message, 'error', $context );
		} else {
			StoreDash_Helpers::debug_log( $message, $context );
		}
	}

	/**
	 * Create required database tables on plugin activation.
	 */
	public static function activate(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Clear OPcache to ensure new code is loaded
		self::clear_opcache();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Get the appropriate table name (supports both legacy woodash and new storedash)
		$carts_table = storedash_get_cart_table_name();

		// dbDelta() requires a plain "CREATE TABLE" (no IF NOT EXISTS) — the
		// IF NOT EXISTS clause breaks its table-name parser so it can neither create
		// nor diff/ALTER the table, meaning schema upgrades silently never apply.
		$sql_carts = "CREATE TABLE $carts_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cart_token varchar(255) NOT NULL,
            customer_id bigint(20) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            phone varchar(100) DEFAULT NULL,
            cart_contents longtext,
            cart_hash varchar(32) DEFAULT NULL,
            total decimal(10,2) DEFAULT 0,
            subtotal decimal(10,2) DEFAULT 0,
            total_tax decimal(10,2) DEFAULT 0,
            total_discount decimal(10,2) DEFAULT 0,
            total_shipping decimal(10,2) DEFAULT 0,
            total_fee decimal(10,2) DEFAULT 0,
            currency varchar(10) DEFAULT NULL,
            locale varchar(10) DEFAULT NULL,
            email_opt_out tinyint(1) DEFAULT 0,
            marketing_optin tinyint(1) DEFAULT 0,
            client_session text,
            recovery_status varchar(50) DEFAULT 'none',
            recovery_sent_at datetime DEFAULT NULL,
            recovered_at datetime DEFAULT NULL,
            converted_order_id bigint(20) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            abandoned_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_cart_token (cart_token),
            KEY idx_customer_id (customer_id),
            KEY idx_email (email),
            KEY idx_recovery_status (recovery_status),
            KEY idx_abandoned_at (abandoned_at),
            KEY idx_created_at (created_at),
            KEY idx_updated_at (updated_at)
        ) $charset_collate;";

		dbDelta( $sql_carts );

		// Create discount tables
		self::create_discount_tables();

		// Create waitlist table
		self::create_waitlist_table();

		// Create enquiry table
		self::create_enquiry_table();

		// Create headless customer auth token table
		self::create_customer_tokens_table();

		// Rewards credit ledger + rules mirror
		self::create_credit_tables();

		self::maybe_upgrade_tables();

		// Ensure custom interval exists during activation before scheduling.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				if ( ! isset( $schedules['woodash_ten_minutes'] ) ) {
					$schedules['woodash_ten_minutes'] = array(
						'interval' => 600,
						'display'  => __( 'Every 10 minutes', 'storedash' ),
					);
				}
				return $schedules;
			}
		);

		// Schedule abandoned cart cron if not already scheduled.
		if ( ! wp_next_scheduled( 'woodash_check_abandoned_carts' ) ) {
			$result = wp_schedule_event( time(), 'woodash_ten_minutes', 'woodash_check_abandoned_carts' );
			if ( is_wp_error( $result ) || false === $result ) {
				self::log(
					'Failed to schedule abandoned cart cron on activation',
					'error',
					array(
						'error' => is_wp_error( $result ) ? $result->get_error_message() : 'Unknown scheduling error',
					)
				);
			}
		}
	}

	/**
	 * Create discount tables
	 */
	private static function create_discount_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_prefix    = $wpdb->prefix . 'storedash_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Detect whether the table already existed BEFORE dbDelta runs, so we can
		// tell a fresh create apart from a re-activation on an existing install.
		$table_existed = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table_prefix . 'discounts'
			)
		);

		// Main discounts table
		$sql_discounts = "CREATE TABLE {$table_prefix}discounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supabase_id VARCHAR(36) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            rule_type VARCHAR(50) NOT NULL,
            discount_type VARCHAR(50),
            amount DECIMAL(10,2),
            priority INT DEFAULT 10,
            enabled TINYINT(1) DEFAULT 0,
            rule_config LONGTEXT,
            target_ids TEXT,
            exclude_ids TEXT,
            exclude_category_ids TEXT,
            exclude_tag_ids TEXT,
            exclude_brand_ids TEXT,
            conditions LONGTEXT,
            start_date DATETIME,
            end_date DATETIME,
            disable_on_sale TINYINT(1) DEFAULT 0,
            disable_lower_priority TINYINT(1) DEFAULT 0,
            disable_with_coupons TINYINT(1) DEFAULT 0,
            apply_to_sale_price TINYINT(1) DEFAULT 0,
            last_synced_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY supabase_id (supabase_id),
            KEY store_id (store_id),
            KEY enabled (enabled),
            KEY rule_type (rule_type),
            KEY priority (priority),
            KEY dates (start_date, end_date)
        ) $charset_collate;";

		// Execute each SQL statement separately with error checking
		self::log( 'Creating discount tables...' );

		$result1 = dbDelta( $sql_discounts );
		if ( ! empty( $result1 ) ) {
			self::log( 'Discounts table result', 'debug', array( 'result' => $result1 ) );
		}

		// Check for database errors
		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during table creation: ' . $wpdb->last_error, 'error' );
		}

		// Version-option rules (no autoload - only needed during upgrades):
		// - Fresh create: the CREATE TABLE above already contains every column up to
		//   the latest discount schema, so record the LATEST version directly and
		//   maybe_upgrade_discount_tables() will skip all upgrades.
		// - Existing table: leave the option untouched. Previously this reset it to
		//   '1.0.0' on every activation, which would re-run upgrades 1.1-1.5 each
		//   time and made any bump replay the whole ladder.
		if ( ! $table_existed ) {
			update_option( 'storedash_discount_db_version', self::LATEST_DISCOUNT_DB_VERSION, false );
		}
	}

	/**
	 * Create waitlist table for back-in-stock notifications
	 */
	private static function create_waitlist_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'storedash_product_waitlist';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_waitlist = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_email VARCHAR(100) NOT NULL,
            customer_name VARCHAR(100) NOT NULL,
            customer_phone VARCHAR(20) DEFAULT NULL,
            language VARCHAR(10) DEFAULT 'en',
            product_sku VARCHAR(100) DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            variation_attributes TEXT DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending' NOT NULL,
            source VARCHAR(50) DEFAULT 'elementor_widget',
            notified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_product_variation (product_id, variation_id, status),
            KEY idx_store_status (store_id, status),
            KEY idx_email (customer_email),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) $charset_collate;";

		self::log( 'Creating waitlist table...' );

		$result = dbDelta( $sql_waitlist );
		if ( ! empty( $result ) ) {
			self::log( 'Waitlist table result', 'debug', array( 'result' => $result ) );
		}

		// Check for database errors
		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during waitlist table creation: ' . $wpdb->last_error, 'error' );
		}

		// Migrate existing NULL variation_id values to 0
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DML operation, table name from $wpdb->prefix
		$wpdb->query( "UPDATE {$table_name} SET variation_id = 0 WHERE variation_id IS NULL" );

		// Add unique index separately (dbDelta doesn't support composite UNIQUE KEY well)
		// This prevents duplicate signups for the same product by the same customer
		$index_exists = $wpdb->get_var(
			"
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$table_name}'
            AND INDEX_NAME = 'unique_email_product_status'
        "
		);

		if ( ! $index_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"
                CREATE UNIQUE INDEX unique_email_product_status
                ON {$table_name} (store_id, product_id, variation_id, customer_email, status)
            "
			);
			self::log( 'Created unique index for waitlist table' );
		}

		// Store DB version (no autoload - only needed during upgrades)
		update_option( 'storedash_waitlist_db_version', '1.1.0', false );
	}

	/**
	 * Create enquiry table for product enquiries
	 */
	private static function create_enquiry_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'storedash_product_enquiries';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_enquiry = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            customer_email VARCHAR(100) NOT NULL,
            customer_name VARCHAR(100) DEFAULT '',
            message TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'open',
            sync_status VARCHAR(20) DEFAULT 'pending',
            supabase_enquiry_id VARCHAR(36) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_store_product (store_id, product_id),
            KEY idx_status (status),
            KEY idx_sync_status (sync_status)
        ) $charset_collate;";

		self::log( 'Creating enquiry table...' );

		$result = dbDelta( $sql_enquiry );
		if ( ! empty( $result ) ) {
			self::log( 'Enquiry table result', 'debug', array( 'result' => $result ) );
		}

		// Check for database errors
		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during enquiry table creation: ' . $wpdb->last_error, 'error' );
		}

		// Store DB version (no autoload - only needed during upgrades)
		update_option( 'storedash_enquiry_db_version', '1.0.0', false );
	}

	/**
	 * Create the headless customer auth token table.
	 *
	 * Holds SHA-256 hashes of the bearer tokens issued by the customer-auth REST
	 * routes; never the tokens themselves. See StoreDash_Customer_Tokens.
	 */
	private static function create_customer_tokens_table(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'storedash_customer_tokens';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// token_hash is the primary key: every read is an exact-match lookup on it,
		// and uniqueness is guaranteed by the hash itself.
		$sql_tokens = "CREATE TABLE {$table_name} (
            token_hash CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (token_hash),
            KEY idx_user_id (user_id),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";

		self::log( 'Creating customer tokens table...' );

		$result = dbDelta( $sql_tokens );
		if ( ! empty( $result ) ) {
			self::log( 'Customer tokens table result', 'debug', array( 'result' => $result ) );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during customer tokens table creation: ' . $wpdb->last_error, 'error' );
		}
	}

	/**
	 * Create the rewards credit tables.
	 *
	 * Ledger rows are append-only money movements keyed by lower-cased billing
	 * email; `idem_key` is UNIQUE so every write is idempotent. Rules are a
	 * full-replace mirror of the Storedash rules keyed by `supabase_id`.
	 */
	private static function create_credit_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$ledger_table    = $wpdb->prefix . 'storedash_credit_ledger';
		$rules_table     = $wpdb->prefix . 'storedash_credit_rules';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_ledger = "CREATE TABLE {$ledger_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_key varchar(191) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            type varchar(16) NOT NULL,
            amount decimal(19,4) NOT NULL DEFAULT 0,
            remaining decimal(19,4) DEFAULT NULL,
            currency varchar(3) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned DEFAULT NULL,
            refund_id bigint(20) unsigned DEFAULT NULL,
            rule_id varchar(36) DEFAULT NULL,
            note text DEFAULT NULL,
            meta longtext DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            idem_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_idem_key (idem_key),
            KEY idx_customer_key (customer_key),
            KEY idx_order_id (order_id),
            KEY idx_refund_id (refund_id),
            KEY idx_expires_at (expires_at),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

		$sql_rules = "CREATE TABLE {$rules_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supabase_id varchar(36) NOT NULL,
            name varchar(191) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            priority int(11) NOT NULL DEFAULT 0,
            kind varchar(16) NOT NULL DEFAULT 'percent',
            value decimal(19,4) NOT NULL DEFAULT 0,
            conditions longtext DEFAULT NULL,
            expiry_days int(11) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_supabase_id (supabase_id),
            KEY idx_enabled_priority (enabled, priority)
        ) $charset_collate;";

		self::log( 'Creating rewards credit tables...' );

		dbDelta( $sql_ledger );
		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during credit ledger table creation: ' . $wpdb->last_error, 'error' );
		}

		dbDelta( $sql_rules );
		if ( ! empty( $wpdb->last_error ) ) {
			self::log( 'Database error during credit rules table creation: ' . $wpdb->last_error, 'error' );
		}
	}

	/**
	 * Check and upgrade tables if needed
	 */
	private static function maybe_upgrade_tables(): void {
		$current_version = get_option( 'woodash_db_version', '1.0' );

		if ( version_compare( $current_version, '1.2', '<' ) ) {
			self::upgrade_to_1_2();
			update_option( 'woodash_db_version', '1.2', false );
		}

		if ( version_compare( $current_version, '1.3', '<' ) ) {
			self::upgrade_to_1_3();
			update_option( 'woodash_db_version', '1.3', false );
		}

		// Run discount table migrations
		self::maybe_upgrade_discount_tables();
	}

	/**
	 * Check and upgrade discount tables if needed
	 */
	private static function maybe_upgrade_discount_tables(): void {
		$current_version = get_option( 'storedash_discount_db_version', '1.0.0' );

		if ( version_compare( $current_version, '1.1.0', '<' ) ) {
			self::upgrade_discount_tables_to_1_1();
			update_option( 'storedash_discount_db_version', '1.1.0', false );
		}

		if ( version_compare( $current_version, '1.2.0', '<' ) ) {
			self::upgrade_discount_tables_to_1_2();
			update_option( 'storedash_discount_db_version', '1.2.0', false );
		}

		if ( version_compare( $current_version, '1.3.0', '<' ) ) {
			self::upgrade_discount_tables_to_1_3();
			update_option( 'storedash_discount_db_version', '1.3.0', false );
		}

		if ( version_compare( $current_version, '1.4.0', '<' ) ) {
			self::upgrade_discount_tables_to_1_4();
			update_option( 'storedash_discount_db_version', '1.4.0', false );
		}

		if ( version_compare( $current_version, '1.5.0', '<' ) ) {
			self::upgrade_discount_tables_to_1_5();
			update_option( 'storedash_discount_db_version', self::LATEST_DISCOUNT_DB_VERSION, false );
		}
	}

	/**
	 * Upgrade discount tables to version 1.1.0
	 * Add original_sale_price column for proper price restoration
	 */
	private static function upgrade_discount_tables_to_1_1(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_discount_products';

		// Fresh installs (and post-1.4.0 upgrades) no longer create this tracking
		// table, so its ALTER would log MySQL error 1146. Bail if it is absent.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table_name
			)
		);

		if ( ! $table_exists ) {
			return;
		}

		// Check if column exists
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'original_sale_price'",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $column_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN original_sale_price DECIMAL(10,2) DEFAULT NULL AFTER original_price"
			);
			self::log( 'Added original_sale_price column to discount_products table' );
		}
	}

	/**
	 * Upgrade discount tables to version 1.2.0
	 * Add processing_status and processing_progress columns for batch processing
	 */
	private static function upgrade_discount_tables_to_1_2(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_discounts';

		// Check if processing_status column exists
		$status_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'processing_status'",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $status_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN processing_status VARCHAR(20) DEFAULT 'idle' AFTER disable_with_coupons,
                ADD COLUMN processing_progress INT DEFAULT 0 AFTER processing_status"
			);
			self::log( 'Added processing_status and processing_progress columns to discounts table' );
		}
	}

	/**
	 * Upgrade discount tables to version 1.3.0
	 * Add processing_error column for error tracking in batch processing
	 */
	private static function upgrade_discount_tables_to_1_3(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_discounts';

		// Check if processing_error column exists
		$error_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'processing_error'",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $error_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN processing_error TEXT DEFAULT NULL AFTER processing_progress"
			);
			self::log( 'Added processing_error column to discounts table' );
		}
	}

	/**
	 * Upgrade discount tables to version 1.4.0
	 * Add apply_to_sale_price column (per-rule sale-price basis) and drop the
	 * never-written storedash_discount_products tracking table. Its reader
	 * (Trait_Sale_Check) was removed in the engine-rewrite wave, so the whole
	 * tracking layer is torn down here.
	 */
	private static function upgrade_discount_tables_to_1_4(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_discounts';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'apply_to_sale_price'",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $column_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN apply_to_sale_price TINYINT(1) DEFAULT 0 AFTER disable_with_coupons"
			);
			self::log( 'Added apply_to_sale_price column to discounts table' );
		}

		// The tracking table never had writers (audit 2026-07-03); drop it.
		$tracking_table = $wpdb->prefix . 'storedash_discount_products';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe DDL operation, table name from $wpdb->prefix
		$wpdb->query( "DROP TABLE IF EXISTS {$tracking_table}" );
		self::log( 'Dropped unused storedash_discount_products tracking table' );
	}

	/**
	 * Upgrade discount tables to version 1.5.0
	 * Add typed taxonomy exclusion columns (categories, tags, brands). A bare
	 * term ID is ambiguous across taxonomies, so each gets its own column
	 * alongside the product-only exclude_ids.
	 */
	private static function upgrade_discount_tables_to_1_5(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_discounts';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'exclude_category_ids'",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $column_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe DDL operation, table name from $wpdb->prefix
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN exclude_category_ids TEXT AFTER exclude_ids,
                ADD COLUMN exclude_tag_ids TEXT AFTER exclude_category_ids,
                ADD COLUMN exclude_brand_ids TEXT AFTER exclude_tag_ids"
			);
			self::log( 'Added taxonomy exclusion columns to discounts table' );
		}
	}

	/**
	 * Upgrade tables to version 1.2
	 * Add marketing_optin column to carts table
	 */
	private static function upgrade_to_1_2(): void {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// Check if marketing_optin column exists
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe DDL, table name from storedash_get_cart_table_name()
		$columns = $wpdb->get_col( "DESC `{$table_name}`" );

		if ( ! in_array( 'marketing_optin', $columns ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from storedash_get_cart_table_name()
			$wpdb->query(
				"ALTER TABLE {$table_name}
                ADD COLUMN marketing_optin tinyint(1) DEFAULT 0 AFTER email_opt_out"
			);
		}

		// Add performance indexes
		self::add_performance_indexes();
	}

	/**
	 * Upgrade tables to version 1.3
	 * Add converted_webhook_sent_at column for idempotent webhook delivery (W3 fix)
	 */
	private static function upgrade_to_1_3(): void {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe DDL, table name from storedash_get_cart_table_name()
		$columns = $wpdb->get_col( "DESC `{$table_name}`" );

		if ( ! in_array( 'converted_webhook_sent_at', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL operation, table name from storedash_get_cart_table_name()
			$wpdb->query(
				"ALTER TABLE {$table_name}
				ADD COLUMN converted_webhook_sent_at datetime DEFAULT NULL AFTER recovered_at"
			);
			self::log( 'Added converted_webhook_sent_at column to carts table' );
		}
	}

	/**
	 * Add database indexes for better performance
	 */
	private static function add_performance_indexes(): void {
		global $wpdb;

		$cart_table = storedash_get_cart_table_name();

		// Check if indexes exist and add them if they don't
		$indexes = array(
			'idx_cart_token'      => "CREATE INDEX idx_cart_token ON {$cart_table} (cart_token)",
			'idx_recovery_status' => "CREATE INDEX idx_recovery_status ON {$cart_table} (recovery_status)",
			'idx_updated_at'      => "CREATE INDEX idx_updated_at ON {$cart_table} (updated_at)",
			'idx_abandoned_at'    => "CREATE INDEX idx_abandoned_at ON {$cart_table} (abandoned_at)",
			'idx_email'           => "CREATE INDEX idx_email ON {$cart_table} (email)",
			'idx_status_updated'  => "CREATE INDEX idx_status_updated ON {$cart_table} (recovery_status, updated_at)",
		);

		foreach ( $indexes as $index_name => $sql ) {
			// Check if index exists
			$index_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND INDEX_NAME = %s',
					DB_NAME,
					$cart_table,
					$index_name
				)
			);

			if ( ! $index_exists ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe DDL index creation, table name from storedash_get_cart_table_name()
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Clear OPcache to ensure new code is loaded.
	 *
	 * This is critical when updating the plugin to ensure the server
	 * doesn't serve stale cached bytecode from before the update.
	 *
	 * @since 1.0.0
	 */
	private static function clear_opcache(): void {
		// Invalidate ONLY this plugin's PHP files. The previous code also called
		// opcache_reset(), which flushes the ENTIRE server bytecode cache for every
		// site and plugin on the host — a heavy, disruptive action (and a no-op or
		// disallowed on many managed hosts). Per-file invalidation below is enough to
		// pick up updated plugin code.
		if ( function_exists( 'opcache_invalidate' ) && defined( 'STOREDASH_PATH' ) ) {
			$files_cleared = 0;
			$plugin_dir    = STOREDASH_PATH;

			// Recursively invalidate all PHP files in the plugin directory
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getExtension() === 'php' ) {
					if ( opcache_invalidate( $file->getPathname(), true ) ) {
						++$files_cleared;
					}
				}
			}

			if ( class_exists( 'StoreDash_Helpers' ) ) {
				StoreDash_Helpers::debug_log(
					'OPcache invalidated for plugin files',
					array( 'files_cleared' => $files_cleared )
				);
			}
		}
	}
}
