<?php
/**
 * Weekly deliverability summary email.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

use MailKite\Smtp\Log\LogTable;

defined( 'ABSPATH' ) || exit;

/**
 * Weekly digest: sent/failed counts and the most frequent failure reasons
 * from the last 7 days of the email log.
 */
final class Summary {

	/**
	 * Weekly cron callback.
	 */
	public static function cron_send(): void {
		if ( ! Options::get( 'summary_enabled' ) || ! Options::get( 'log_enabled' ) ) {
			return;
		}

		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, weekly aggregate.
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(mailer = 'inbound') AS received FROM %i WHERE created_at >= %s", LogTable::name(), $since ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, weekly aggregate.
		$errors = $wpdb->get_results( $wpdb->prepare( "SELECT error, COUNT(*) AS n FROM %i WHERE created_at >= %s AND status = 'failed' AND error IS NOT NULL GROUP BY error ORDER BY n DESC LIMIT 3", LogTable::name(), $since ) );

		$lines   = [];
		$lines[] = sprintf(
			/* translators: 1: sent count, 2: failed count, 3: inbound count. */
			__( 'Last 7 days: %1$d sent, %2$d failed, %3$d received (inbound).', 'mailkite-smtp' ),
			(int) ( $counts->sent ?? 0 ),
			(int) ( $counts->failed ?? 0 ),
			(int) ( $counts->received ?? 0 )
		);
		if ( $errors ) {
			$lines[] = '';
			$lines[] = __( 'Top failure reasons:', 'mailkite-smtp' );
			foreach ( $errors as $row ) {
				$lines[] = sprintf( '  %d× %s', (int) $row->n, (string) $row->error );
			}
		}
		$health  = Health::latest();
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: domain, 2: SPF status, 3: DMARC status. */
			__( 'DNS for %1$s — SPF: %2$s, DMARC: %3$s.', 'mailkite-smtp' ),
			$health['domain'],
			$health['spf'] ? __( 'ok', 'mailkite-smtp' ) : __( 'MISSING', 'mailkite-smtp' ),
			$health['dmarc'] ? __( 'ok', 'mailkite-smtp' ) : __( 'MISSING', 'mailkite-smtp' )
		);
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=mailkite-smtp&tab=log' );

		$to = (string) Options::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		wp_mail(
			$to,
			/* translators: %s: site host. */
			sprintf( __( '[%s] Weekly email delivery summary', 'mailkite-smtp' ), wp_parse_url( home_url(), PHP_URL_HOST ) ),
			implode( "\n", $lines )
		);
	}
}
