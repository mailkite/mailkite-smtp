<?php
/**
 * Brevo (ex-Sendinblue) API mailer (BYO key).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Mail\Email;
use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST https://api.brevo.com/v3/smtp/email — 201 on acceptance.
 */
final class BrevoMailer implements MailerInterface {

	/**
	 * Deliver via the Brevo v3 API.
	 *
	 * @param Email $email Normalized email.
	 * @return true|WP_Error
	 */
	public function send( Email $email ) {
		$key = (string) Options::get( 'brevo_key' );
		if ( '' === $key ) {
			return new WP_Error( 'brevo_no_key', __( 'Brevo is selected but no API key is configured.', 'mailkite-smtp' ) );
		}

		$sender = [ 'email' => $email->from_email() ];
		if ( '' !== $email->from_name() ) {
			$sender['name'] = $email->from_name();
		}

		$body = [
			'sender'  => $sender,
			'to'      => array_map( static fn( string $a ): array => [ 'email' => $a ], $email->to ),
			'subject' => $email->subject,
		];
		$body[ $email->is_html() ? 'htmlContent' : 'textContent' ] = $email->message;

		if ( $email->cc ) {
			$body['cc'] = array_map( static fn( string $a ): array => [ 'email' => $a ], $email->cc );
		}
		if ( $email->bcc ) {
			$body['bcc'] = array_map( static fn( string $a ): array => [ 'email' => $a ], $email->bcc );
		}
		if ( $email->reply_to ) {
			$body['replyTo'] = [ 'email' => $email->reply_to ];
		}

		$attachments = ApiAttachments::inline( $email->attachments );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$body['attachment'] = array_map(
				static fn( array $a ): array => [
					'name'    => $a['filename'],
					'content' => $a['content'],
				],
				$attachments
			);
		}

		return ApiAttachments::json_post(
			'https://api.brevo.com/v3/smtp/email',
			[ 'api-key' => $key ],
			$body,
			'brevo'
		);
	}
}
