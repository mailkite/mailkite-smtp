<?php
/**
 * Email log table schema.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the {$wpdb->prefix}mailkite_smtp_log table.
 */
final class LogTable {

	public const DB_VERSION        = '2';
	public const DB_VERSION_OPTION = 'mailkite_smtp_db_version';

	/**
	 * Fully-qualified table name.
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'mailkite_smtp_log';
	}

	/**
	 * Create/upgrade the table (activation hook; safe to re-run).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				mail_to TEXT NOT NULL,
				subject TEXT NOT NULL,
				body LONGTEXT NULL,
				headers TEXT NULL,
				attachments TEXT NULL,
				mailer VARCHAR(20) NOT NULL DEFAULT '',
				status VARCHAR(10) NOT NULL DEFAULT 'pending',
				error TEXT NULL,
				redacted TINYINT(1) NOT NULL DEFAULT 0,
				from_addr TEXT NULL,
				thread_id VARCHAR(255) NULL,
				message_id VARCHAR(255) NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY status (status),
				KEY thread_id (thread_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version is behind (plugin updated in place,
	 * where the activation hook never fires). dbDelta adds the missing columns.
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Delete log rows older than the retention window (daily cron).
	 *
	 * @param int $days Retention in days.
	 */
	public static function purge( int $days ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, scheduled purge.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				self::name(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}
}
