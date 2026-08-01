<?php
/**
 * Shared helpers for HTTP API mailers.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment encoding + JSON POST plumbing shared by the API mailers.
 */
final class ApiAttachments {

	public const MAX_INLINE_BYTES = 10_485_760; // 10 MB total.

	/**
	 * Read file paths into base64 attachment structs with a total-size cap.
	 *
	 * @param string[] $paths Absolute file paths.
	 * @return array<int, array{filename: string, content: string, contentType: string}>|WP_Error
	 */
	public static function inline( array $paths ) {
		$attachments = [];
		$total       = 0;

		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'attachment_unreadable', sprintf( __( 'Attachment not readable: %s', 'mailkite-smtp' ), $path ) );
			}
			$total += (int) filesize( $path );
			if ( $total > self::MAX_INLINE_BYTES ) {
				return new WP_Error( 'attachment_too_large', __( 'Attachments exceed the 10 MB limit for API sending.', 'mailkite-smtp' ) );
			}
			$attachments[] = [
				'filename'    => wp_basename( $path ),
				'content'     => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- API transport encoding.
				'contentType' => wp_check_filetype( $path )['type'] ? wp_check_filetype( $path )['type'] : 'application/octet-stream',
			];
		}

		return $attachments;
	}

	/**
	 * JSON POST with uniform success/error mapping.
	 *
	 * @param string                $url     Endpoint.
	 * @param array<string, string> $headers Auth headers.
	 * @param array<string, mixed>  $body    JSON payload.
	 * @param string                $mailer  Mailer id for error codes.
	 * @return true|WP_Error
	 */
	public static function json_post( string $url, array $headers, array $body, string $mailer ) {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => $headers + [ 'Content-Type' => 'application/json' ],
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

		$raw     = wp_remote_retrieve_body( $response );
		$payload = json_decode( $raw, true );
		$detail  = '';
		if ( is_array( $payload ) ) {
			// Each provider nests its message differently; take the first string we find.
			foreach ( [ 'message', 'error', 'errors' ] as $field ) {
				if ( ! empty( $payload[ $field ] ) ) {
					$detail = is_string( $payload[ $field ] ) ? $payload[ $field ] : wp_json_encode( $payload[ $field ] );
					break;
				}
			}
		}

		return new WP_Error(
			$mailer . '_api_error',
			/* translators: 1: mailer name, 2: HTTP status, 3: provider error detail. */
			sprintf( __( '%1$s API returned HTTP %2$d. %3$s', 'mailkite-smtp' ), ucfirst( $mailer ), $code, $detail ),
			[ 'status' => $code ]
		);
	}
}
