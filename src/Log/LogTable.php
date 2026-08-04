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

	public const DB_VERSION        = '3';
	public const DB_VERSION_OPTION = 'mailkite_smtp_db_version';

	/**
	 * Fully-qualified table name.
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'mailkite_smtp_log';
	}

	/** Message bodies, split off so listing never loads them. */
	public static function body_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mailkite_smtp_body';
	}

	/** Attachment metadata (bytes stay upstream unless a site opts in to copies). */
	public static function attachment_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mailkite_smtp_attachment';
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
				direction VARCHAR(10) NOT NULL DEFAULT 'outbound',
				owner_user_id BIGINT UNSIGNED NULL,
				seen TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY status (status),
				KEY thread_id (thread_id),
				KEY message_id (message_id),
				KEY owner (owner_user_id, direction, id)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::body_table() . " (
				log_id BIGINT UNSIGNED NOT NULL,
				body_text LONGTEXT NULL,
				body_html LONGTEXT NULL,
				PRIMARY KEY  (log_id)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::attachment_table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				log_id BIGINT UNSIGNED NOT NULL,
				filename TEXT NULL,
				mime VARCHAR(190) NULL,
				size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				url TEXT NULL,
				PRIMARY KEY  (id),
				KEY log_id (log_id)
			) {$charset};"
		);

		self::migrate_bodies();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Move any body still living on the list table into the body table, and label rows
	 * that predate the direction column. One pass; the column is emptied as it goes, so
	 * re-running is harmless.
	 */
	private static function migrate_bodies(): void {
		global $wpdb;
		$log  = self::name();
		$body = self::body_table();

		// Table names go through %i (WP 6.2+) rather than string interpolation — same
		// reason every other query here does: an identifier built by hand is the shape a
		// SQL injection takes, even when today's input is a constant.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration on our own tables.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (log_id, body_text, body_html) SELECT id, body, NULL FROM %i WHERE body IS NOT NULL AND body <> %s',
				$body,
				$log,
				''
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration on our own tables.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET body = NULL WHERE body IS NOT NULL AND body <> %s', $log, '' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema migration on our own tables.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET direction = CASE WHEN mailer = %s THEN %s ELSE %s END', $log, 'inbound', 'inbound', 'outbound' ) );
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
		// Site mail only. A user's mailbox is their archive — clearing it on the log's
		// housekeeping schedule would be data loss, so owned rows are never purged here.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, scheduled purge.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE log_id IN (SELECT id FROM %i WHERE owner_user_id IS NULL AND created_at < %s)', self::body_table(), self::name(), $cutoff ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, scheduled purge.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE log_id IN (SELECT id FROM %i WHERE owner_user_id IS NULL AND created_at < %s)', self::attachment_table(), self::name(), $cutoff ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, scheduled purge.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE owner_user_id IS NULL AND created_at < %s',
				self::name(),
				$cutoff
			)
		);
	}
}
