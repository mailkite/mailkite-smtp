<?php
/**
 * Minimal RFC 822 / MIME reader for displaying a fetched message.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the raw bytes from GET /api/mailbox/messages/{uid}/raw into something
 * renderable: decoded headers, a best-effort body (text preferred, HTML as
 * fallback), and the attachment list. Deliberately small — display only, never
 * used to make security decisions.
 */
final class Mime {

	/**
	 * Parse a raw message.
	 *
	 * @param string $raw RFC822 bytes.
	 * @return array{headers: array<string, string>, text: string, html: string, attachments: string[]}
	 */
	public static function parse( string $raw ): array {
		$raw               = str_replace( "\r\n", "\n", $raw );
		[ $head, $body ]   = array_pad( explode( "\n\n", $raw, 2 ), 2, '' );
		$headers           = self::headers( $head );
		$result            = [
			'headers'     => $headers,
			'text'        => '',
			'html'        => '',
			'attachments' => [],
		];
		$content_type      = strtolower( $headers['content-type'] ?? 'text/plain' );
		$transfer_encoding = strtolower( trim( $headers['content-transfer-encoding'] ?? '' ) );

		if ( str_starts_with( $content_type, 'multipart/' ) && preg_match( '/boundary="?([^";\s]+)"?/i', $content_type, $m ) ) {
			self::walk_parts( $body, $m[1], $result );
		} else {
			$decoded = self::decode_body( $body, $transfer_encoding, $content_type );
			if ( str_contains( $content_type, 'text/html' ) ) {
				$result['html'] = $decoded;
			} else {
				$result['text'] = $decoded;
			}
		}

		return $result;
	}

	/**
	 * Header name => decoded value (last wins; folded lines joined).
	 *
	 * @param string $head The header block.
	 * @return array<string, string>
	 */
	private static function headers( string $head ): array {
		$headers = [];
		$lines   = preg_split( '/\n(?![ \t])/', $head ) ?: [];
		foreach ( $lines as $line ) {
			$line = preg_replace( '/\n[ \t]+/', ' ', $line ) ?? '';
			$pos  = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$headers[ strtolower( trim( substr( $line, 0, $pos ) ) ) ] = self::decode_words( trim( substr( $line, $pos + 1 ) ) );
		}

		return $headers;
	}

	/**
	 * Recursively collect text/html/attachments from a multipart body.
	 *
	 * @param string                                                                             $body     Multipart body.
	 * @param string                                                                             $boundary Boundary token.
	 * @param array{headers: array<string, string>, text: string, html: string, attachments: string[]} $result   Accumulator (by reference).
	 * @param int                                                                                $depth    Recursion guard.
	 */
	private static function walk_parts( string $body, string $boundary, array &$result, int $depth = 0 ): void {
		if ( $depth > 5 ) {
			return;
		}
		$chunks = explode( '--' . $boundary, $body );
		foreach ( $chunks as $chunk ) {
			$chunk = ltrim( $chunk, "\n" );
			if ( '' === trim( $chunk ) || str_starts_with( $chunk, '--' ) ) {
				continue;
			}
			[ $head, $part ] = array_pad( explode( "\n\n", $chunk, 2 ), 2, '' );
			$headers         = self::headers( $head );
			$type            = strtolower( $headers['content-type'] ?? 'text/plain' );
			$encoding        = strtolower( trim( $headers['content-transfer-encoding'] ?? '' ) );
			$disposition     = strtolower( $headers['content-disposition'] ?? '' );

			if ( str_starts_with( $type, 'multipart/' ) && preg_match( '/boundary="?([^";\s]+)"?/i', $type, $m ) ) {
				self::walk_parts( $part, $m[1], $result, $depth + 1 );
				continue;
			}
			if ( str_contains( $disposition, 'attachment' ) || preg_match( '/name="?([^";]+)"?/i', $type . ' ' . $disposition, $n ) ) {
				$name = isset( $n[1] ) ? self::decode_words( trim( $n[1] ) ) : __( '(unnamed file)', 'mailkite-smtp' );
				if ( str_contains( $disposition, 'attachment' ) ) {
					$result['attachments'][] = $name;
					continue;
				}
			}
			if ( str_contains( $type, 'text/plain' ) && '' === $result['text'] ) {
				$result['text'] = self::decode_body( $part, $encoding, $type );
			} elseif ( str_contains( $type, 'text/html' ) && '' === $result['html'] ) {
				$result['html'] = self::decode_body( $part, $encoding, $type );
			}
		}
	}

	/**
	 * Decode transfer encoding and convert the charset to UTF-8.
	 *
	 * @param string $body     Encoded body.
	 * @param string $encoding Content-Transfer-Encoding.
	 * @param string $type     Content-Type (for charset).
	 */
	private static function decode_body( string $body, string $encoding, string $type ): string {
		if ( 'base64' === $encoding ) {
			$body = (string) base64_decode( preg_replace( '/\s+/', '', $body ) ?? '', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME transfer decoding.
		} elseif ( 'quoted-printable' === $encoding ) {
			$body = quoted_printable_decode( $body );
		}
		$charset = preg_match( '/charset="?([^";\s]+)"?/i', $type, $m ) ? strtoupper( $m[1] ) : 'UTF-8';
		if ( 'UTF-8' !== $charset && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $body, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown charsets are expected; fall back to the raw bytes.
			$body      = false === $converted ? $body : $converted;
		}

		return trim( $body );
	}

	/**
	 * Decode RFC 2047 encoded-words in a header value.
	 *
	 * @param string $value Header value.
	 */
	private static function decode_words( string $value ): string {
		if ( ! str_contains( $value, '=?' ) ) {
			return $value;
		}
		$decoded = function_exists( 'iconv_mime_decode' )
			? @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed headers must not fatal.
			: false;

		return false === $decoded ? $value : $decoded;
	}
}
