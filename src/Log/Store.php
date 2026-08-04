<?php
/**
 * The one read/write API for stored mail. Both plugins go through here.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Log;

defined( 'ABSPATH' ) || exit;

/**
 * MailKite's retention is finite, so WordPress is the archive of record: every message
 * the webhook delivers is written here once and read from here by everything — the admin
 * Email Log and the per-user inbox alike.
 *
 * Two rules make that safe to share:
 *  1. Nothing outside this class writes SQL against these tables. The Mailboxes add-on
 *     calls these methods; it never touches another plugin's schema.
 *  2. A row carries `owner_user_id` when it belongs to someone's personal mailbox.
 *     Ownership is enforced HERE, in the query — never left to a template.
 */
final class Store {

	/**
	 * Record a message and its parts.
	 *
	 * @param array<string, mixed> $row  Envelope columns for the log table.
	 * @param string               $text Plain-text body ('' when none).
	 * @param string               $html HTML body ('' when none).
	 * @param array<int, array{filename: string, mime: string, size: int, url: string}> $attachments Attachment metadata.
	 * @return int The new row id (0 on failure).
	 */
	public static function insert( array $row, string $text = '', string $html = '', array $attachments = [] ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		$wpdb->insert( LogTable::name(), $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return 0;
		}

		if ( '' !== $text || '' !== $html ) {
			// Bodies live in their own table so listing never drags message text through
			// memory — the reason to split them, not their size.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom body table.
			$wpdb->insert(
				LogTable::body_table(),
				[
					'log_id'    => $id,
					'body_text' => $text,
					'body_html' => $html,
				]
			);
		}
		foreach ( $attachments as $file ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom attachment table.
			$wpdb->insert(
				LogTable::attachment_table(),
				[
					'log_id'   => $id,
					'filename' => (string) ( $file['filename'] ?? '' ),
					'mime'     => (string) ( $file['mime'] ?? '' ),
					'size'     => (int) ( $file['size'] ?? 0 ),
					'url'      => (string) ( $file['url'] ?? '' ),
				]
			);
		}

		return $id;
	}

	/**
	 * Which WordPress user owns mail addressed here, if anyone.
	 *
	 * The Mailboxes add-on answers this; with only the SMTP plugin installed every
	 * message is site mail and the answer is always null.
	 *
	 * @param string $recipient A recipient address (may be "Name <addr>").
	 */
	public static function owner_for( string $recipient ): ?int {
		$address = preg_match( '/<([^>]+)>/', $recipient, $m ) ? trim( $m[1] ) : trim( $recipient );

		/**
		 * Filters the WordPress user who owns a mailbox address.
		 *
		 * @param int|null $user_id Owning user, or null for site mail.
		 * @param string   $address The bare address.
		 */
		$owner = apply_filters( 'mailkite_smtp_mailbox_owner', null, strtolower( $address ) );

		return $owner ? (int) $owner : null;
	}

	/**
	 * Site mail for the admin log — personal mailbox rows are excluded by construction.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function site_messages( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, mail_to, from_addr, subject, mailer, direction, status, error, redacted, thread_id
				   FROM %i WHERE owner_user_id IS NULL ORDER BY id DESC LIMIT %d',
				LogTable::name(),
				$limit
			)
		);
	}

	/**
	 * One user's mail, newest first.
	 *
	 * @param int    $user_id   Owner.
	 * @param string $direction inbound|outbound.
	 * @param int    $limit     Max rows.
	 * @return array<int, object>
	 */
	public static function mailbox_messages( int $user_id, string $direction = 'inbound', int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, mail_to, from_addr, subject, direction, status, thread_id, seen
				   FROM %i WHERE owner_user_id = %d AND direction = %s ORDER BY id DESC LIMIT %d',
				LogTable::name(),
				$user_id,
				'outbound' === $direction ? 'outbound' : 'inbound',
				$limit
			)
		);
	}

	/**
	 * One message with its body, enforcing ownership.
	 *
	 * @param int      $id      Row id.
	 * @param int|null $user_id Pass a user id to read a personal message; null for site mail.
	 * @return object|null Null when it does not exist or is not theirs to read.
	 */
	public static function get( int $id, ?int $user_id = null ): ?object {
		global $wpdb;

		// Ownership is part of the lookup, not a check after it — a wrong id simply
		// finds nothing rather than leaking a row.
		$sql = null === $user_id
			? $wpdb->prepare(
				'SELECT l.*, b.body_text, b.body_html FROM %i l LEFT JOIN %i b ON b.log_id = l.id
				  WHERE l.id = %d AND l.owner_user_id IS NULL',
				LogTable::name(),
				LogTable::body_table(),
				$id
			)
			: $wpdb->prepare(
				'SELECT l.*, b.body_text, b.body_html FROM %i l LEFT JOIN %i b ON b.log_id = l.id
				  WHERE l.id = %d AND l.owner_user_id = %d',
				LogTable::name(),
				LogTable::body_table(),
				$id,
				$user_id
			);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$row = $wpdb->get_row( $sql );

		return $row ?: null;
	}

	/**
	 * Attachment metadata for a message.
	 *
	 * @param int $id Row id.
	 * @return array<int, object>
	 */
	public static function attachments( int $id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom attachment table.
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT filename, mime, size, url FROM %i WHERE log_id = %d', LogTable::attachment_table(), $id )
		);
	}

	/**
	 * Mark a personal message read (ownership enforced).
	 *
	 * @param int $id      Row id.
	 * @param int $user_id Owner.
	 */
	public static function mark_seen( int $id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		$wpdb->update( LogTable::name(), [ 'seen' => 1 ], [ 'id' => $id, 'owner_user_id' => $user_id ] );
	}

	/**
	 * Has this message already been stored? Keeps webhook retries and API backfill from
	 * writing the same mail twice.
	 *
	 * @param string $message_id Upstream message id.
	 */
	public static function exists( string $message_id ): bool {
		if ( '' === $message_id ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE message_id = %s LIMIT 1', LogTable::name(), $message_id ) );
	}
}
