<?php
/**
 * WP-CLI commands: wp mailkite <status|test|log|purge>.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Cli;

use MailKite\Smtp\Log\LogTable;
use MailKite\Smtp\Options;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage MailKite SMTP from the command line. No other SMTP plugin ships
 * WP-CLI support — this exists for agencies and host automation.
 */
final class Commands {

	/**
	 * Show the active mailer configuration (secrets masked).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailkite status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$s = Options::all();

		$rows = [
			[
				'setting' => 'mailer',
				'value'   => $s['mailer'],
			],
			[
				'setting' => 'api_key',
				'value'   => '' !== (string) $s['api_key'] ? '(set)' : '(none)',
			],
			[
				'setting' => 'smtp_host',
				'value'   => '' !== (string) $s['smtp_host'] ? $s['smtp_host'] : '(none)',
			],
			[
				'setting' => 'fallback_enabled',
				'value'   => $s['fallback_enabled'] ? 'yes' : 'no',
			],
			[
				'setting' => 'log_enabled',
				'value'   => $s['log_enabled'] ? 'yes' : 'no',
			],
			[
				'setting' => 'log_retention_days',
				'value'   => (string) $s['log_retention'],
			],
			[
				'setting' => 'alerts_enabled',
				'value'   => $s['alerts_enabled'] ? 'yes' : 'no',
			],
		];
		WP_CLI\Utils\format_items( 'table', $rows, [ 'setting', 'value' ] );
	}

	/**
	 * Send a test email.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Recipient address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailkite test admin@example.com
	 *
	 * @when after_wp_load
	 *
	 * @param array{0: string} $args Positional args.
	 */
	public function test( array $args ): void {
		[ $to ] = $args;
		if ( ! is_email( $to ) ) {
			WP_CLI::error( 'Invalid email address.' );
		}

		$sent = wp_mail(
			$to,
			'MailKite SMTP test email',
			sprintf( 'CLI test from %s via the "%s" mailer.', home_url(), (string) Options::get( 'mailer' ) )
		);

		$sent ? WP_CLI::success( 'Test email accepted for delivery.' ) : WP_CLI::error( 'Send failed — run `wp mailkite log` for the error.' );
	}

	/**
	 * List recent email log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of entries. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailkite log --limit=50
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function log( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit = max( 1, (int) ( $assoc_args['limit'] ?? 20 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, CLI read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, mail_to, subject, mailer, status, error FROM %i ORDER BY id DESC LIMIT %d', LogTable::name(), $limit ), ARRAY_A );

		WP_CLI\Utils\format_items( 'table', (array) $rows, [ 'id', 'created_at', 'mail_to', 'subject', 'mailer', 'status', 'error' ] );
	}

	/**
	 * Purge log entries older than the retention window (or all of them).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete every entry, not just expired ones.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mailkite purge
	 *     wp mailkite purge --all
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function purge( array $args, array $assoc_args ): void {
		LogTable::purge( isset( $assoc_args['all'] ) ? 0 : (int) Options::get( 'log_retention' ) );
		WP_CLI::success( 'Log purged.' );
	}
}
