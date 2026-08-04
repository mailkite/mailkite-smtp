<?php
/**
 * At-rest encryption for stored secrets (API key, SMTP password).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-GCM, keyed off MAILKITE_SMTP_ENCRYPTION_KEY (wp-config) when defined,
 * falling back to LOGGED_IN_KEY + LOGGED_IN_SALT. The key never lives in the
 * database. Values are prefixed so plaintext written before encryption existed
 * (or on hosts without OpenSSL) still reads back correctly.
 */
final class Crypto {

	private const PREFIX = '$mk1$';

	/**
	 * Encrypt a secret for storage. Returns plaintext unchanged when OpenSSL
	 * is unavailable (never fails closed on the user's mail).
	 *
	 * @param string $value Plaintext secret.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return $value;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage encoding.
	}

	/**
	 * Decrypt a stored secret; passes through legacy plaintext values.
	 *
	 * @param string $value Stored value.
	 */
	public static function decrypt( string $value ): string {
		if ( ! str_starts_with( $value, self::PREFIX ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt(
			substr( $raw, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ),
			substr( $raw, 12, 16 )
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive the 32-byte key from wp-config constants.
	 */
	private static function key(): string {
		if ( defined( 'MAILKITE_SMTP_ENCRYPTION_KEY' ) && MAILKITE_SMTP_ENCRYPTION_KEY ) {
			return hash( 'sha256', MAILKITE_SMTP_ENCRYPTION_KEY, true );
		}
		$logged_in_key  = defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : 'mailkite-smtp';
		$logged_in_salt = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : wp_salt( 'logged_in' );

		return hash( 'sha256', $logged_in_key . $logged_in_salt, true );
	}
}
