<?php
/**
 * Generic SMTP: configures core PHPMailer; core performs the send.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Options;

defined( 'ABSPATH' ) || exit;

/**
 * phpmailer_init handler. Active only when the 'smtp' mailer is selected —
 * we never short-circuit wp_mail for SMTP, so every other plugin's
 * expectations about PHPMailer stay intact.
 */
final class SmtpMailer {

	/**
	 * Configure PHPMailer for SMTP transport.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Core PHPMailer instance (by reference).
	 */
	public function configure( $phpmailer ): void {
		$settings = Options::all();

		$is_fallback = \MailKite\Smtp\Mail\Interceptor::$falling_back && '' !== (string) $settings['smtp_host'];
		if ( 'smtp' !== $settings['mailer'] && ! $is_fallback ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = (string) $settings['smtp_host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer API.
		$phpmailer->Port = (int) $settings['smtp_port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		if ( 'none' !== $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure = $settings['smtp_encryption']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		if ( $settings['smtp_auth'] ) {
			$phpmailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Username = (string) $settings['smtp_username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Password = (string) $settings['smtp_password']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	}
}
