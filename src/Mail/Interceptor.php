<?php
/**
 * pre_wp_mail interception and mailer routing.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail;

use MailKite\Smtp\Mail\Mailers\MailKiteMailer;
use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Routes wp_mail() based on the selected mailer.
 *
 * - 'php'      → return null (core default behavior).
 * - 'smtp'     → return null (SmtpMailer configures PHPMailer via phpmailer_init).
 * - 'mailkite' → short-circuit and deliver via the MailKite API, firing
 *                wp_mail_succeeded / wp_mail_failed exactly like core does.
 */
final class Interceptor {

	/**
	 * pre_wp_mail filter callback.
	 *
	 * @param bool|null                                                                       $short_circuit Existing short-circuit value.
	 * @param array{to: mixed, subject: mixed, message: mixed, headers: mixed, attachments: mixed} $atts     wp_mail attributes.
	 * @return bool|null Null to let core send; bool result when handled here.
	 */
	public function maybe_send( $short_circuit, array $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit; // Another plugin got here first; respect it.
		}
		if ( 'mailkite' !== Options::get( 'mailer' ) ) {
			return null;
		}

		$email  = Email::from_wp_atts( $atts );
		$result = ( new MailKiteMailer() )->send( $email );

		$mail_data = [
			'to'          => $email->to,
			'subject'     => $email->subject,
			'message'     => $email->message,
			'headers'     => $atts['headers'] ?? [],
			'attachments' => $email->attachments,
		];

		if ( is_wp_error( $result ) ) {
			$error = new WP_Error( 'wp_mail_failed', $result->get_error_message(), $mail_data );
			do_action( 'wp_mail_failed', $error );

			return false;
		}

		do_action( 'wp_mail_succeeded', $mail_data );

		return true;
	}
}
