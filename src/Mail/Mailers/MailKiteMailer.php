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

	private const INLINE_CUTOVER_BYTES = 2_097_152; // >2 MB total → upload once, attach by URL.

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
		$headers = $email->headers;
		if ( isset( $headers['in-reply-to'] ) ) {
			// The API threads on inReplyTo; passing it only as a raw header would reach the
			// recipient's client but leave the conversation unlinked on MailKite's side.
			$body['inReplyTo'] = $headers['in-reply-to'];
			unset( $headers['in-reply-to'] );
		}
		if ( $headers ) {
			$body['headers'] = $headers;
		}

		foreach ( [
			'track_opens'  => 'trackOpens',
			'track_clicks' => 'trackClicks',
		] as $setting => $field ) {
			$mode = (string) Options::get( $setting );
			if ( 'default' !== $mode ) {
				$body[ $field ] = 'on' === $mode;
			}
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
	 * Build attachments: small sets inline as base64; large sets upload to
	 * MailKite storage first and attach by URL (POST /v1/attachments raw-body).
	 *
	 * @param string[] $paths Absolute file paths from wp_mail.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private function build_attachments( array $paths ) {
		if ( ! $paths ) {
			return [];
		}

		$total = 0;
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'mailkite_attachment', sprintf( __( 'Attachment not readable: %s', 'mailkite-smtp' ), $path ) );
			}
			$total += (int) filesize( $path );
		}

		if ( $total <= self::INLINE_CUTOVER_BYTES ) {
			return ApiAttachments::inline( $paths );
		}

		$attachments = [];
		foreach ( $paths as $path ) {
			$filename = wp_basename( $path );
			$response = wp_remote_post(
				Options::get( 'api_base' ) . '/v1/attachments?filename=' . rawurlencode( $filename ),
				[
					'timeout' => 60,
					'headers' => [
						'Authorization' => 'Bearer ' . (string) Options::get( 'api_key' ),
						'Content-Type'  => wp_check_filetype( $path )['type'] ? wp_check_filetype( $path )['type'] : 'application/octet-stream',
					],
					'body'    => (string) file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment file.
				]
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$payload = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 2 !== intdiv( (int) wp_remote_retrieve_response_code( $response ), 100 ) || empty( $payload['url'] ) ) {
				/* translators: %s: file name. */
				return new WP_Error( 'mailkite_attachment', sprintf( __( 'Uploading attachment %s to MailKite failed.', 'mailkite-smtp' ), $filename ) );
			}
			$attachments[] = [
				'filename' => $filename,
				'url'      => (string) $payload['url'],
			];
		}

		return $attachments;
	}
}
