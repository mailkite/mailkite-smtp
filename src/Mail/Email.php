<?php
/**
 * Email DTO parsed from wp_mail() arguments.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes the loose wp_mail( $to, $subject, $message, $headers, $attachments )
 * contract: string-or-array recipients, string-or-array headers, From/Cc/Bcc/
 * Reply-To/Content-Type header extraction.
 */
final class Email {

	/** @var string[] */
	public array $to = [];

	/** @var string[] */
	public array $cc = [];

	/** @var string[] */
	public array $bcc = [];

	public string $subject  = '';
	public string $message  = '';
	public string $reply_to = '';
	public string $from     = '';

	public string $content_type = 'text/plain';

	/** @var array<string, string> Remaining custom headers (lower-cased names). */
	public array $headers = [];

	/** @var string[] Absolute file paths. */
	public array $attachments = [];

	/**
	 * Build from the pre_wp_mail $atts array.
	 *
	 * @param array{to?: mixed, subject?: mixed, message?: mixed, headers?: mixed, attachments?: mixed} $atts wp_mail attributes.
	 */
	public static function from_wp_atts( array $atts ): self {
		$email          = new self();
		$email->to      = self::address_list( $atts['to'] ?? [] );
		$email->subject = (string) ( $atts['subject'] ?? '' );
		$email->message = (string) ( $atts['message'] ?? '' );

		$attachments = $atts['attachments'] ?? [];
		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}
		$email->attachments = array_values( array_filter( array_map( 'trim', (array) $attachments ) ) );

		$headers = $atts['headers'] ?? [];
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}
		foreach ( (array) $headers as $header ) {
			if ( ! is_string( $header ) || ! str_contains( $header, ':' ) ) {
				continue;
			}
			[ $name, $value ] = explode( ':', $header, 2 );
			$name             = strtolower( trim( $name ) );
			$value            = trim( $value );

			switch ( $name ) {
				case 'from':
					$email->from = $value;
					break;
				case 'cc':
					$email->cc = array_merge( $email->cc, self::address_list( $value ) );
					break;
				case 'bcc':
					$email->bcc = array_merge( $email->bcc, self::address_list( $value ) );
					break;
				case 'reply-to':
					$email->reply_to = $value;
					break;
				case 'content-type':
					$parts               = explode( ';', $value );
					$email->content_type = strtolower( trim( $parts[0] ) );
					break;
				default:
					$email->headers[ $name ] = $value;
			}
		}

		// Resolved sender, honoring the same filters core applies.
		$from_email  = apply_filters( 'wp_mail_from', self::default_from_email() );
		$from_name   = apply_filters( 'wp_mail_from_name', 'WordPress' );
		$email->from = $email->from ?: sprintf( '%s <%s>', $from_name, $from_email );

		return $email;
	}

	/**
	 * Whether the body is HTML.
	 */
	public function is_html(): bool {
		return 'text/html' === $this->content_type;
	}

	/**
	 * Core's default from address (mirror of wp_mail()'s fallback).
	 */
	private static function default_from_email(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST ) ?: 'localhost';
		if ( str_starts_with( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Normalize a comma-separated string or array of addresses.
	 *
	 * @param mixed $value Raw recipient value.
	 * @return string[]
	 */
	private static function address_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return array_values( array_filter( array_map( 'trim', (array) $value ) ) );
	}
}
