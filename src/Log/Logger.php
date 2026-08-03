<?php
/**
 * Email logging with auth-email redaction.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Log;

use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Captures wp_mail() calls via the 'wp_mail' filter and records the outcome
 * from wp_mail_succeeded / wp_mail_failed. Bodies of authentication emails
 * (password resets, login links, verification codes) are redacted by default —
 * a stored-plaintext log of reset emails is an account-takeover vector
 * (see Post SMTP CVE-2025-11833).
 */
final class Logger {

	private static ?self $instance = null;

	/** @var int|null Row id of the in-flight email. */
	private ?int $current_id = null;

	/** @var string Fallback note for the in-flight email, prepended to a final error. */
	private string $fallback_note = '';

	/**
	 * Singleton accessor (hook callbacks need one shared in-flight row id).
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * 'wp_mail' filter: record the outgoing email as pending, return args unchanged.
	 *
	 * @param array<string, mixed> $args wp_mail args.
	 * @return array<string, mixed>
	 */
	public function capture( array $args ): array {
		$this->current_id    = null;
		$this->fallback_note = '';

		if ( ! Options::get( 'log_enabled' ) ) {
			return $args;
		}

		global $wpdb;

		$subject = (string) ( $args['subject'] ?? '' );
		$redact  = $this->should_redact( $subject );

		$to = $args['to'] ?? [];
		if ( is_string( $to ) ) {
			$to = explode( ',', $to );
		}

		// A reply carries In-Reply-To; that value is the thread root, so an outgoing
		// answer lands in the same conversation as the message it answers.
		$raw_headers = $args['headers'] ?? [];
		if ( is_string( $raw_headers ) ) {
			$raw_headers = explode( "\n", str_replace( "\r\n", "\n", $raw_headers ) );
		}
		$thread_id = '';
		foreach ( (array) $raw_headers as $header ) {
			if ( is_string( $header ) && preg_match( '/^\s*in-reply-to\s*:\s*(.+)$/i', $header, $m ) ) {
				$thread_id = trim( $m[1] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table.
		$wpdb->insert(
			LogTable::name(),
			[
				'created_at'  => current_time( 'mysql', true ),
				'mail_to'     => implode( ', ', array_map( 'trim', (array) $to ) ),
				'subject'     => $subject,
				'body'        => $redact ? null : (string) ( $args['message'] ?? '' ),
				'headers'     => wp_json_encode( $args['headers'] ?? [] ),
				'attachments' => wp_json_encode( array_map( 'wp_basename', (array) ( $args['attachments'] ?? [] ) ) ),
				'mailer'      => (string) Options::get( 'mailer' ),
				'status'      => 'pending',
				'redacted'    => $redact ? 1 : 0,
				'thread_id'   => $thread_id,
			]
		);
		$this->current_id = (int) $wpdb->insert_id;

		return $args;
	}

	/**
	 * wp_mail_succeeded action.
	 */
	public function on_succeeded(): void {
		$this->finish( 'sent', null );
	}

	/**
	 * wp_mail_failed action.
	 *
	 * @param WP_Error $error Failure details.
	 */
	public function on_failed( WP_Error $error ): void {
		$this->finish( 'failed', $error->get_error_message() );
	}

	/**
	 * Record that the primary mailer failed and delivery is falling back,
	 * without closing the in-flight row (the fallback path will).
	 *
	 * @param string $reason Primary-mailer error message.
	 */
	public function note_fallback( string $reason ): void {
		if ( null === $this->current_id ) {
			return;
		}

		$this->fallback_note = sprintf( '%s — fell back to PHPMailer.', $reason );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		$wpdb->update(
			LogTable::name(),
			[ 'error' => $this->fallback_note ],
			[ 'id' => $this->current_id ]
		);
	}

	/**
	 * Mark the in-flight row with its outcome.
	 *
	 * @param string      $status sent|failed.
	 * @param string|null $error  Error message when failed.
	 */
	private function finish( string $status, ?string $error ): void {
		if ( null === $this->current_id ) {
			return;
		}

		global $wpdb;
		$fields = [ 'status' => $status ];
		if ( null !== $error ) {
			$fields['error'] = '' !== $this->fallback_note ? $this->fallback_note . ' Then: ' . $error : $error;
		}
		$this->fallback_note = '';
		// A null $error deliberately leaves any existing text (e.g. a fallback
		// note) in place instead of wiping it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom log table.
		$wpdb->update( LogTable::name(), $fields, [ 'id' => $this->current_id ] );
		$this->current_id = null;
	}

	/**
	 * Whether this subject matches the auth-email redaction patterns.
	 *
	 * @param string $subject Email subject.
	 */
	private function should_redact( string $subject ): bool {
		if ( ! Options::get( 'log_redact_auth' ) ) {
			return false;
		}

		/**
		 * Filters the list of regex fragments identifying authentication emails
		 * whose bodies must not be stored.
		 *
		 * @param string[] $patterns Case-insensitive regex fragments.
		 */
		$patterns = apply_filters(
			'mailkite_smtp_redact_patterns',
			[ 'password', 'passwort', 'reset', 'verif', 'confirm', 'log.?in', 'magic link', 'one.?time', 'otp', '2fa', 'two.?factor', 'security code', 'activat' ]
		);

		return (bool) preg_match( '/' . implode( '|', $patterns ) . '/i', $subject );
	}
}
