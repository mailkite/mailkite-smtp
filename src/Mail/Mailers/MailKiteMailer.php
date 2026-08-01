<?php
/**
 * MailKite API mailer: POST /v1/send.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Mail\Email;
use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the MailKite API with the site's (domain-scoped) API key.
 */
final class MailKiteMailer implements MailerInterface {

	private const MAX_INLINE_ATTACHMENT_BYTES = 10_485_760; // 10 MB total, base64-inlined.

	/**
	 * Deliver via POST /v1/send.
	 *
	 * @param Email $email Normalized email.
	 * @return true|WP_Error
	 */
	public function send( Email $email ) {
		$api_key = (string) Options::get( 'api_key' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'mailkite_no_key',
				__( 'MailKite is selected as the mailer but no API key is configured.', 'mailkite-smtp' )
			);
		}

		$body = [
			'from'    => $email->from,
			'to'      => $email->to,
			'subject' => $email->subject,
		];

		if ( $email->is_html() ) {
			$body['html'] = $email->message;
		} else {
			$body['text'] = $email->message;
		}
		if ( $email->cc ) {
			$body['cc'] = $email->cc;
		}
		if ( $email->bcc ) {
			$body['bcc'] = $email->bcc;
		}
		if ( $email->reply_to ) {
			$body['replyTo'] = $email->reply_to;
		}
		if ( $email->headers ) {
			$body['headers'] = $email->headers;
		}

		$attachments = $this->build_attachments( $email->attachments );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$body['attachments'] = $attachments;
		}

		$response = wp_remote_post(
			Options::get( 'api_base' ) . '/v1/send',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = is_array( $payload ) && ! empty( $payload['error'] )
			? (string) $payload['error']
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'MailKite API returned HTTP %d.', 'mailkite-smtp' ), $code );

		return new WP_Error( 'mailkite_api_error', $message, [ 'status' => $code ] );
	}

	/**
	 * Inline attachments as base64 (small files); enforce a total-size cap.
	 *
	 * @param string[] $paths Absolute file paths from wp_mail.
	 * @return array<int, array{filename: string, content: string, contentType: string}>|WP_Error
	 */
	private function build_attachments( array $paths ) {
		$attachments = [];
		$total       = 0;

		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'mailkite_attachment', sprintf( __( 'Attachment not readable: %s', 'mailkite-smtp' ), $path ) );
			}
			$total += (int) filesize( $path );
			if ( $total > self::MAX_INLINE_ATTACHMENT_BYTES ) {
				return new WP_Error( 'mailkite_attachment', __( 'Attachments exceed the 10 MB limit for API sending.', 'mailkite-smtp' ) );
			}
			$type          = wp_check_filetype( $path )['type'] ?: 'application/octet-stream';
			$attachments[] = [
				'filename'    => wp_basename( $path ),
				'content'     => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API transport encoding, not obfuscation.
				'contentType' => $type,
			];
		}

		return $attachments;
	}
}
