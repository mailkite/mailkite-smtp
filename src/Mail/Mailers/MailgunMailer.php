<?php
/**
 * Mailgun API mailer (BYO key + domain).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Mail\Email;
use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST https://api[.eu].mailgun.net/v3/{domain}/messages (multipart form).
 */
final class MailgunMailer implements MailerInterface {

	/**
	 * Deliver via the Mailgun v3 API.
	 *
	 * @param Email $email Normalized email.
	 * @return true|WP_Error
	 */
	public function send( Email $email ) {
		$key    = (string) Options::get( 'mailgun_key' );
		$domain = (string) Options::get( 'mailgun_domain' );
		if ( '' === $key || '' === $domain ) {
			return new WP_Error( 'mailgun_no_key', __( 'Mailgun is selected but the API key or domain is missing.', 'mailkite-smtp' ) );
		}

		$fields                                        = [
			'from'    => $email->from,
			'to'      => implode( ',', $email->to ),
			'subject' => $email->subject,
		];
		$fields[ $email->is_html() ? 'html' : 'text' ] = $email->message;
		if ( $email->cc ) {
			$fields['cc'] = implode( ',', $email->cc );
		}
		if ( $email->bcc ) {
			$fields['bcc'] = implode( ',', $email->bcc );
		}
		if ( $email->reply_to ) {
			$fields['h:Reply-To'] = $email->reply_to;
		}

		$boundary = 'mk' . wp_generate_password( 24, false );
		$payload  = '';
		foreach ( $fields as $name => $value ) {
			$payload .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$total = 0;
		foreach ( $email->attachments as $path ) {
			if ( ! is_readable( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'attachment_unreadable', sprintf( __( 'Attachment not readable: %s', 'mailkite-smtp' ), $path ) );
			}
			$total += (int) filesize( $path );
			if ( $total > ApiAttachments::MAX_INLINE_BYTES ) {
				return new WP_Error( 'attachment_too_large', __( 'Attachments exceed the 10 MB limit for API sending.', 'mailkite-smtp' ) );
			}
			$filename = wp_basename( $path );
			$type     = wp_check_filetype( $path )['type'] ? wp_check_filetype( $path )['type'] : 'application/octet-stream';
			$payload .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"{$filename}\"\r\n";
			$payload .= "Content-Type: {$type}\r\n\r\n" . (string) file_get_contents( $path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local attachment file.
		}
		$payload .= "--{$boundary}--\r\n";

		$host     = 'eu' === Options::get( 'mailgun_region' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';
		$response = wp_remote_post(
			"https://{$host}/v3/" . rawurlencode( $domain ) . '/messages',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth encoding.
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				],
				'body'    => $payload,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$detail = json_decode( wp_remote_retrieve_body( $response ), true )['message'] ?? '';

		return new WP_Error(
			'mailgun_api_error',
			/* translators: 1: HTTP status, 2: provider error detail. */
			sprintf( __( 'Mailgun API returned HTTP %1$d. %2$s', 'mailkite-smtp' ), $code, is_string( $detail ) ? $detail : '' ),
			[ 'status' => $code ]
		);
	}
}
