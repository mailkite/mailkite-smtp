<?php
/**
 * SendGrid API mailer (BYO key).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Mail\Email;
use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST https://api.sendgrid.com/v3/mail/send — 202 on acceptance.
 */
final class SendGridMailer implements MailerInterface {

	/**
	 * Deliver via the SendGrid v3 API.
	 *
	 * @param Email $email Normalized email.
	 * @return true|WP_Error
	 */
	public function send( Email $email ) {
		$key = (string) Options::get( 'sendgrid_key' );
		if ( '' === $key ) {
			return new WP_Error( 'sendgrid_no_key', __( 'SendGrid is selected but no API key is configured.', 'mailkite-smtp' ) );
		}

		$personalization = [ 'to' => array_map( static fn( string $a ): array => [ 'email' => $a ], $email->to ) ];
		if ( $email->cc ) {
			$personalization['cc'] = array_map( static fn( string $a ): array => [ 'email' => $a ], $email->cc );
		}
		if ( $email->bcc ) {
			$personalization['bcc'] = array_map( static fn( string $a ): array => [ 'email' => $a ], $email->bcc );
		}

		$from = [ 'email' => $email->from_email() ];
		if ( '' !== $email->from_name() ) {
			$from['name'] = $email->from_name();
		}

		$body = [
			'personalizations' => [ $personalization ],
			'from'             => $from,
			'subject'          => $email->subject,
			'content'          => [
				[
					'type'  => $email->is_html() ? 'text/html' : 'text/plain',
					'value' => $email->message,
				],
			],
		];
		if ( $email->reply_to ) {
			$body['reply_to'] = [ 'email' => $email->reply_to ];
		}

		$attachments = ApiAttachments::inline( $email->attachments );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$body['attachments'] = array_map(
				static fn( array $a ): array => [
					'content'  => $a['content'],
					'filename' => $a['filename'],
					'type'     => $a['contentType'],
				],
				$attachments
			);
		}

		return ApiAttachments::json_post(
			'https://api.sendgrid.com/v3/mail/send',
			[ 'Authorization' => 'Bearer ' . $key ],
			$body,
			'sendgrid'
		);
	}
}
