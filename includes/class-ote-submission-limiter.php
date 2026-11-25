<?php
/**
 * Database-backed public form submission limiter.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_Submission_Limiter {
	private static $table;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ote_submission_limits';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			email_hash char(64) NOT NULL,
			form_type varchar(16) NOT NULL,
			window_started datetime NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (email_hash,form_type),
			KEY window_started (window_started)
		) {$charset} ENGINE=InnoDB;";
		dbDelta( $sql );
		update_option( 'ote_submission_limits_db_version', '1' );
	}

	public static function maybe_install() {
		if ( '1' !== get_option( 'ote_submission_limits_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Atomically count submissions by a salted email hash and form type.
	 *
	 * @param string $type  Form type.
	 * @param string $email Validated email address.
	 * @return bool True when the request should be blocked.
	 */
	public static function is_limited( $type, $email ) {
		global $wpdb;

		$type = sanitize_key( $type );
		$email = strtolower( sanitize_email( $email ) );
		if ( ! in_array( $type, array( 'booking', 'review' ), true ) || ! is_email( $email ) ) {
			return true;
		}

		$limit = (int) apply_filters( 'ote_submission_rate_limit', 5, $type, $email );
		$limit = max( 1, min( 50, $limit ) );
		$hash = hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );
		$now = current_time( 'mysql', true );
		$table = self::table_name();

		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (email_hash, form_type, window_started, attempts)
			VALUES (%s, %s, %s, 1)
			ON DUPLICATE KEY UPDATE
				attempts = IF(window_started < DATE_SUB(%s, INTERVAL 15 MINUTE), 1, attempts + 1),
				window_started = IF(window_started < DATE_SUB(%s, INTERVAL 15 MINUTE), %s, window_started)",
			$hash,
			$type,
			$now,
			$now,
			$now,
			$now
		) );

		if ( false === $result ) {
			return true;
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT attempts FROM {$table} WHERE email_hash = %s AND form_type = %s",
			$hash,
			$type
		) );

		// Opportunistic cleanup: hashes are retained for no longer than one day.
		if ( false === get_transient( 'ote_submission_limits_cleanup' ) ) {
			$wpdb->query( "DELETE FROM {$table} WHERE window_started < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)" );
			set_transient( 'ote_submission_limits_cleanup', 1, DAY_IN_SECONDS );
		}

		return $count > $limit;
	/**
	 * Remove short-lived limiter records for a privacy erasure request.
	 *
	 * @param string $email Email address supplied to the privacy tool.
	 */
	public static function erase_email( $email ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$wpdb->delete(
			self::table_name(),
			array( 'email_hash' => hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ) ),
			array( '%s' )
		);
	}

	}
}
