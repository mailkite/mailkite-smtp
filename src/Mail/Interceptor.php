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
	 * True while a failed API send is being retried through PHPMailer,
	 * so SmtpMailer knows to configure SMTP transport for this request.
	 */
	public static bool $falling_back = false;

	/**
	 * Transport a routing rule forced for the current email ('smtp'|'php'), or null.
	 */
	public static ?string $forced_transport = null;

	private const API_MAILERS = [
		'mailkite' => Mailers\MailKiteMailer::class,
		'sendgrid' => Mailers\SendGridMailer::class,
		'brevo'    => Mailers\BrevoMailer::class,
		'mailgun'  => Mailers\MailgunMailer::class,
	];

	/**
	 * pre_wp_mail filter callback.
	 *
	 * @param bool|null                                                                            $short_circuit Existing short-circuit value.
	 * @param array{to: mixed, subject: mixed, message: mixed, headers: mixed, attachments: mixed} $atts     wp_mail attributes.
	 * @return bool|null Null to let core send; bool result when handled here.
	 */
	public function maybe_send( $short_circuit, array $atts ) {
		self::$falling_back     = false; // Each email decides fresh.
		self::$forced_transport = null;

		if ( null !== $short_circuit ) {
			return $short_circuit; // Another plugin got here first; respect it.
		}

		$mailer_id = $this->resolve_mailer( $atts );

		if ( 'php' === $mailer_id || 'smtp' === $mailer_id ) {
			if ( Options::get( 'mailer' ) !== $mailer_id ) {
				self::$forced_transport = $mailer_id; // A rule diverged from the default.
			}

			return null; // Core sends; SmtpMailer configures transport as needed.
		}

		$email  = Email::from_wp_atts( $atts );
		$class  = self::API_MAILERS[ $mailer_id ];
		$result = ( new $class() )->send( $email );

		$mail_data = [
			'to'          => $email->to,
			'subject'     => $email->subject,
			'message'     => $email->message,
			'headers'     => $atts['headers'] ?? [],
			'attachments' => $email->attachments,
		];

		if ( is_wp_error( $result ) ) {
			if ( Options::get( 'fallback_enabled' ) ) {
				// Let core deliver via PHPMailer instead: SMTP when configured, PHP
				// mail() otherwise. The pending log row keeps its outcome from the
				// wp_mail_succeeded / wp_mail_failed the core path fires.
				\MailKite\Smtp\Log\Logger::instance()->note_fallback( ucfirst( $mailer_id ) . ': ' . $result->get_error_message() );
				self::$falling_back = true;

				return null;
			}

			$error = new WP_Error( 'wp_mail_failed', $result->get_error_message(), $mail_data );
			do_action( 'wp_mail_failed', $error );

			return false;
		}

		do_action( 'wp_mail_succeeded', $mail_data );

		return true;
	}

	/**
	 * Resolve the mailer for this email: routing rules first, then the default.
	 *
	 * @param array<string, mixed> $atts wp_mail attributes.
	 */
	private function resolve_mailer( array $atts ): string {
		$mailer_id = (string) Options::get( 'mailer' );

		$rules = (array) Options::get( 'routing_rules' );
		if ( $rules ) {
			$subject = (string) ( $atts['subject'] ?? '' );
			$to      = $atts['to'] ?? '';
			$to      = is_array( $to ) ? implode( ',', $to ) : (string) $to;

			foreach ( $rules as $rule ) {
				$haystack = 'subject' === $rule['field'] ? $subject : $to;
				if ( false !== stripos( $haystack, (string) $rule['match'] ) ) {
					$mailer_id = (string) $rule['mailer'];
					break;
				}
			}
		}

		return isset( self::API_MAILERS[ $mailer_id ] ) || in_array( $mailer_id, [ 'php', 'smtp' ], true ) ? $mailer_id : 'php';
	}
}
