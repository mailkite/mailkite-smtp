<?php
/**
 * Instant email-failure alerts.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the site admin the moment a send fails — instant by default, which is
 * the thing FluentSMTP users ask for and WP Mail SMTP charges for. Guards:
 * a recursion flag (the alert itself uses wp_mail) and a 15-minute rate limit
 * per error message so a burst of failures sends one alert, not hundreds.
 */
final class Alerts {

	private const RATE_LIMIT_SECONDS = 15 * MINUTE_IN_SECONDS;

	private bool $sending_alert = false;

	/**
	 * wp_mail_failed listener.
	 *
	 * @param WP_Error $error Failure raised by the mail path.
	 */
	public function on_failed( WP_Error $error ): void {
		if ( $this->sending_alert || ! Options::get( 'alerts_enabled' ) ) {
			return;
		}

		$message       = $error->get_error_message();
		$transient_key = 'mailkite_smtp_alert_' . md5( $message );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, self::RATE_LIMIT_SECONDS );

		$to = (string) Options::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$data    = $error->get_error_data();
		$subject = is_array( $data ) && ! empty( $data['subject'] ) ? (string) $data['subject'] : __( '(unknown)', 'mailkite-smtp' );

		$webhook = (string) Options::get( 'alert_webhook' );
		if ( $webhook ) {
			$host = (string) wp_parse_url( $webhook, PHP_URL_HOST );
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$text = sprintf( '✉️❌ %s: email "%s" failed — %s', $site, $subject, $message );
			if ( str_contains( $host, 'slack.com' ) ) {
				$json = [ 'text' => $text ];
			} elseif ( str_contains( $host, 'discord.com' ) ) {
				$json = [ 'content' => $text ];
			} else {
				$json = [
					'site'    => $site,
					'subject' => $subject,
					'error'   => $message,
				];
			}
			wp_remote_post(
				$webhook,
				[
					'timeout' => 5,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( $json ),
				]
			);
		}

		$this->sending_alert = true;
		wp_mail(
			$to,
			/* translators: %s: site host name. */
			sprintf( __( '[%s] Email delivery failure', 'mailkite-smtp' ), wp_parse_url( home_url(), PHP_URL_HOST ) ),
			sprintf(
				/* translators: 1: failed email subject, 2: error message, 3: admin URL of the email log. */
				__( "An outgoing email just failed on your site.\n\nSubject: %1\$s\nError: %2\$s\n\nReview the log: %3\$s\n\n— MailKite SMTP (you can disable or rate-limit these alerts in Settings)", 'mailkite-smtp' ),
				$subject,
				$message,
				admin_url( 'admin.php?page=mailkite-smtp&tab=log' )
			)
		);
		$this->sending_alert = false;
	}
}
