<?php
/**
 * StoreDash customer auth tokens.
 *
 * Issues and resolves the opaque bearer tokens that let a headless storefront
 * act as a logged-in WooCommerce customer. Tokens are minted by the
 * customer-auth REST endpoints (server-to-server, consumer-key authenticated)
 * and presented back on Store API requests via the X-StoreDash-Customer header,
 * where StoreDash_Customer_Auth_Bridge resolves them.
 *
 * Security model:
 * - The token is random (not a JWT): nothing is encoded in it, so nothing can be
 *   forged or decoded. Authority comes solely from the row existing in our table.
 * - Only the SHA-256 hash is stored. A leaked database gives no usable tokens.
 * - Tokens are issued only to customer-role users, and the bridge re-checks the
 *   role on every request, so a token can never reach a privileged route.
 * - Every token for a user is destroyed when their password changes.
 *
 * @package StoreDash
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Customer_Tokens {

	/**
	 * Token lifetime in seconds (30 days), refreshed while in active use.
	 */
	const TTL = 2592000;

	/**
	 * Minimum seconds between sliding-expiry writes.
	 *
	 * Without this every authenticated request would issue an UPDATE. One write
	 * per day per token keeps "active users stay logged in" while leaving the
	 * hot read path write-free.
	 */
	const REFRESH_INTERVAL = 86400;

	/**
	 * Length of the generated token, in characters.
	 */
	const TOKEN_LENGTH = 64;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'storedash_customer_tokens';
	}

	/**
	 * Hash a token for storage/lookup.
	 *
	 * Plain SHA-256, deliberately: this is a 64-char random secret with no
	 * user-chosen entropy, so it needs no password-style work factor, and the
	 * lookup must stay a single indexed read. It is never a password hash.
	 *
	 * Pure function — unit tested.
	 *
	 * @param string $token Plaintext token.
	 * @return string 64-char hex digest.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Roles a StoreDash customer token may be issued to.
	 *
	 * Allowlist, not denylist: an unknown or custom role fails closed. Stores
	 * with a bespoke customer role (e.g. wholesale) can opt it in via the filter,
	 * accepting that the role must carry no administrative capabilities.
	 *
	 * @return string[]
	 */
	public static function allowed_roles(): array {
		/**
		 * Filters the roles eligible for headless customer authentication.
		 *
		 * @since 1.8.0
		 *
		 * @param string[] $roles Allowed role slugs.
		 */
		$roles = apply_filters( 'storedash_customer_auth_allowed_roles', array( 'customer', 'subscriber' ) );

		return is_array( $roles ) ? $roles : array( 'customer', 'subscriber' );
	}

	/**
	 * Whether a set of role slugs is eligible for customer authentication.
	 *
	 * Takes the raw role slugs rather than a WP_User precisely so callers can
	 * avoid user_can()/current_user_can(): those fire the map_meta_cap and
	 * user_has_cap filter chains, and the bridge that calls this runs inside
	 * 'determine_current_user', before the current user is resolved. A third-party
	 * capability filter that calls wp_get_current_user() there (Yoast SEO does)
	 * re-enters determine_current_user in unbounded recursion until PHP memory is
	 * exhausted — a failure this plugin has already hit in production (see
	 * class-storedash-auth-handler.php). Reading $user->roles touches only loaded
	 * user meta and fires nothing.
	 *
	 * Pure function — unit tested.
	 *
	 * @param string[] $user_roles    Role slugs held by the user.
	 * @param string[] $allowed_roles Role slugs eligible for customer auth.
	 * @return bool True only when the user holds at least one role and every role is allowed.
	 */
	public static function roles_are_eligible( array $user_roles, array $allowed_roles ): bool {
		if ( empty( $user_roles ) ) {
			return false;
		}

		// EVERY role must be allowed. A user who is both customer and administrator
		// is an administrator; holding an allowed role alongside a privileged one
		// must not grant a token.
		foreach ( $user_roles as $role ) {
			if ( ! in_array( $role, $allowed_roles, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Convenience wrapper around roles_are_eligible() for a WP_User.
	 *
	 * @param WP_User $user User to test.
	 * @return bool
	 */
	public static function user_is_eligible( $user ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return self::roles_are_eligible( (array) $user->roles, self::allowed_roles() );
	}

	/**
	 * Whether a token's expiry should be pushed forward on this request.
	 *
	 * True once the token has burned more than REFRESH_INTERVAL of its window,
	 * i.e. at most one write per interval per token.
	 *
	 * Pure function — unit tested.
	 *
	 * @param int $expires_at      Expiry as a unix timestamp.
	 * @param int $now             Current unix timestamp.
	 * @param int $ttl             Full token lifetime in seconds.
	 * @param int $refresh_interval Minimum seconds between refreshes.
	 * @return bool
	 */
	public static function should_refresh_expiry( int $expires_at, int $now, int $ttl, int $refresh_interval ): bool {
		$remaining = $expires_at - $now;

		if ( $remaining <= 0 ) {
			return false; // Already expired — resolve() rejects it; never resurrect.
		}

		return $remaining < ( $ttl - $refresh_interval );
	}

	/**
	 * Issue a new token for a user.
	 *
	 * @param int $user_id User to issue for. Must already be role-checked by the caller.
	 * @return array{token:string,expires_at:int}|WP_Error Plaintext token (returned once, never stored) and expiry.
	 */
	public static function mint( int $user_id ) {
		global $wpdb;

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'storedash_token_no_user', __( 'User not found.', 'storedash' ), array( 'status' => 404 ) );
		}

		// Belt and braces: the endpoints check this too, but minting is the one
		// place a privileged account could ever acquire a customer token, so the
		// check lives here as well.
		if ( ! self::user_is_eligible( $user ) ) {
			return new WP_Error(
				'storedash_token_role_not_eligible',
				__( 'This account cannot be used for storefront login.', 'storedash' ),
				array( 'status' => 403 )
			);
		}

		$token      = wp_generate_password( self::TOKEN_LENGTH, false );
		$now        = time();
		$expires_at = $now + self::TTL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned auth table; must not be cached.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'token_hash'   => self::hash_token( $token ),
				'user_id'      => $user_id,
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires_at ),
				'last_used_at' => null,
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'storedash_token_insert_failed', __( 'Could not create session.', 'storedash' ), array( 'status' => 500 ) );
		}

		return array(
			'token'      => $token,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Resolve a token to its user ID, applying expiry and sliding refresh.
	 *
	 * @param string $token Plaintext token from the request header.
	 * @return int|false User ID, or false when unknown/expired.
	 */
	public static function resolve( string $token ) {
		global $wpdb;

		if ( '' === $token ) {
			return false;
		}

		$hash  = self::hash_token( $token );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned auth table; caching an auth lookup would defeat revocation.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id, expires_at FROM {$table} WHERE token_hash = %s", $hash )
		);

		if ( ! $row ) {
			return false;
		}

		$now        = time();
		$expires_at = strtotime( $row->expires_at . ' UTC' );

		if ( ! $expires_at || $expires_at <= $now ) {
			// Expired: delete on sight so the table self-cleans even between cron runs.
			self::revoke( $token );
			return false;
		}

		if ( self::should_refresh_expiry( $expires_at, $now, self::TTL, self::REFRESH_INTERVAL ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned auth table.
			$wpdb->update(
				$table,
				array(
					'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
					'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ),
				),
				array( 'token_hash' => $hash ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}

		return (int) $row->user_id;
	}

	/**
	 * Revoke a single token (logout).
	 *
	 * @param string $token Plaintext token.
	 * @return void
	 */
	public static function revoke( string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned auth table.
		$wpdb->delete( self::table_name(), array( 'token_hash' => self::hash_token( $token ) ), array( '%s' ) );
	}

	/**
	 * Revoke every token belonging to a user.
	 *
	 * Called on password change/reset and account deletion: a password change must
	 * log out every device, which is the whole reason tokens are stored server-side
	 * rather than self-signed.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function revoke_all_for_user( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned auth table.
		$wpdb->delete( self::table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Delete expired rows. Bound to the daily purge cron.
	 *
	 * @return void
	 */
	public static function purge_expired(): void {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned auth table maintenance.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Register the lifecycle hooks that keep tokens honest.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Password reset via the lost-password flow.
		add_action(
			'after_password_reset',
			static function ( $user ) {
				if ( $user instanceof WP_User ) {
					self::revoke_all_for_user( (int) $user->ID );
				}
			},
			10,
			1
		);

		// Password changed any other way (profile edit, wc/v3 customer update, WP admin).
		add_action(
			'profile_update',
			static function ( $user_id, $old_user_data, $userdata = array() ) {
				$old_hash = isset( $old_user_data->user_pass ) ? $old_user_data->user_pass : '';
				$new_hash = is_array( $userdata ) && isset( $userdata['user_pass'] ) ? $userdata['user_pass'] : '';

				// $userdata is only passed on WP 6.3+. Without it, fall back to
				// re-reading the stored hash and comparing to the pre-update value.
				if ( '' === $new_hash ) {
					$fresh    = get_userdata( $user_id );
					$new_hash = $fresh ? $fresh->user_pass : '';
				}

				if ( '' !== $new_hash && '' !== $old_hash && ! hash_equals( $old_hash, $new_hash ) ) {
					self::revoke_all_for_user( (int) $user_id );
				}
			},
			10,
			3
		);

		// Account deleted.
		add_action(
			'delete_user',
			static function ( $user_id ) {
				self::revoke_all_for_user( (int) $user_id );
			},
			10,
			1
		);

		// Daily cleanup of expired rows.
		add_action( 'storedash_purge_customer_tokens', array( __CLASS__, 'purge_expired' ) );

		if ( ! wp_next_scheduled( 'storedash_purge_customer_tokens' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'storedash_purge_customer_tokens' );
		}
	}
}
