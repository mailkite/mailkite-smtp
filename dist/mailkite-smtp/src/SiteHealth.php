<?php
/**
 * WordPress Site Health integration.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

use MailKite\Smtp\Log\LogTable;

defined( 'ABSPATH' ) || exit;

/**
 * Adds email-deliverability tests to Tools → Site Health.
 */
final class SiteHealth {

	/**
	 * site_status_tests filter.
	 *
	 * @param array<string, array<string, mixed>> $tests Registered tests.
	 * @return array<string, array<string, mixed>>
	 */
	public function register( array $tests ): array {
		$tests['direct']['mailkite_smtp_mailer']   = [
			'label' => __( 'Email is sent through a real mailer', 'mailkite-smtp' ),
			'test'  => [ $this, 'test_mailer' ],
		];
		$tests['direct']['mailkite_smtp_failures'] = [
			'label' => __( 'Recent email delivery failures', 'mailkite-smtp' ),
			'test'  => [ $this, 'test_failures' ],
		];

		return $tests;
	}

	/**
	 * Fails when the site still uses PHP mail().
	 *
	 * @return array<string, mixed>
	 */
	public function test_mailer(): array {
		$s      = Options::all();
		$mailer = (string) $s['mailer'];
		// "Configured" means a mailer that can actually deliver: MailKite with a key,
		// SMTP with a host, or a BYO provider with its credential present.
		$good = match ( $mailer ) {
			'mailkite' => '' !== (string) $s['api_key'],
			'smtp'     => '' !== (string) $s['smtp_host'],
			'sendgrid' => '' !== (string) $s['sendgrid_key'],
			'brevo'    => '' !== (string) $s['brevo_key'],
			'mailgun'  => '' !== (string) $s['mailgun_key'],
			default    => false,
		};

		return [
			'label'       => $good
				? __( 'Email is sent through a configured mailer', 'mailkite-smtp' )
				: __( 'Email still uses PHP mail() — deliverability is unreliable', 'mailkite-smtp' ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => [
				'label' => __( 'Email', 'mailkite-smtp' ),
				'color' => 'blue',
			],
			'description' => $good
				/* translators: %s: mailer name. */
				? '<p>' . sprintf( esc_html__( 'Outgoing email is delivered via %s.', 'mailkite-smtp' ), esc_html( $mailer ) ) . '</p>'
				: '<p>' . esc_html__( 'Most hosts block or rate-limit PHP mail(), and Gmail/Yahoo now require authenticated senders. Configure MailKite or an SMTP server.', 'mailkite-smtp' ) . '</p>',
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=mailkite-smtp' ) ), esc_html__( 'Open MailKite SMTP settings', 'mailkite-smtp' ) ),
			'test'        => 'mailkite_smtp_mailer',
		];
	}

	/**
	 * Flags failures in the last 7 days.
	 *
	 * @return array<string, mixed>
	 */
	public function test_failures(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, health check.
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed' AND created_at >= %s", LogTable::name(), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$good   = 0 === $failed;

		return [
			'label'       => $good
				? __( 'No email delivery failures in the last 7 days', 'mailkite-smtp' )
				/* translators: %d: failure count. */
				: sprintf( __( '%d emails failed to send in the last 7 days', 'mailkite-smtp' ), $failed ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => [
				'label' => __( 'Email', 'mailkite-smtp' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'The MailKite SMTP email log records every send and its outcome.', 'mailkite-smtp' ) . '</p>',
			'actions'     => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=mailkite-smtp&tab=log' ) ), esc_html__( 'Review the email log', 'mailkite-smtp' ) ),
			'test'        => 'mailkite_smtp_failures',
		];
	}
}
