<?php
/**
 * One-click settings migration from other SMTP plugins.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Migrate;

use MailKite\Smtp\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads competitor plugins' stored options and maps their generic-SMTP
 * configuration onto ours. Provider-API configs (SendGrid keys etc.) are not
 * importable until we ship those mailers, and are reported as skipped.
 *
 * Option formats verified against each plugin's source (2026-08-01):
 * - WP Mail SMTP   `wp_mail_smtp`        ['mail' => [...], 'smtp' => [host, port, encryption, auth, user, pass]]
 * - Easy WP SMTP   `easy_wp_smtp`        same shape (same vendor); legacy `swpsmtp_options` with base64 password
 * - FluentSMTP     `fluentmail-settings` ['connections' => [hash => ['provider_settings' => [...]]], 'misc' => [...]]
 * - Post SMTP      `postman_options`     flat: hostname, port, enc_type, basic_auth_username, basic_auth_password (base64)
 */
final class Importer {

	/**
	 * Sources we can detect, in display order.
	 *
	 * @return array<string, string> slug => label.
	 */
	public static function sources(): array {
		return [
			'wp_mail_smtp' => 'WP Mail SMTP',
			'easy_wp_smtp' => 'Easy WP SMTP',
			'fluent_smtp'  => 'FluentSMTP',
			'post_smtp'    => 'Post SMTP',
		];
	}

	/**
	 * Detect sources with an importable (SMTP-type) configuration.
	 *
	 * @return array<string, string> slug => label of detected sources.
	 */
	public static function detect(): array {
		$found = [];
		foreach ( self::sources() as $slug => $label ) {
			if ( null !== self::extract( $slug ) ) {
				$found[ $slug ] = $label;
			}
		}

		return $found;
	}

	/**
	 * Import a source's settings into ours.
	 *
	 * @param string $slug Source slug from sources().
	 * @return bool Whether anything was imported.
	 */
	public static function import( string $slug ): bool {
		$mapped = self::extract( $slug );
		if ( null === $mapped ) {
			return false;
		}

		$mapped['mailer'] = 'smtp';
		Options::update( $mapped );

		return true;
	}

	/**
	 * Extract and map a source's SMTP settings, or null when absent/not SMTP.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed>|null Fields for Options::update().
	 */
	private static function extract( string $slug ): ?array {
		switch ( $slug ) {
			case 'wp_mail_smtp':
				return self::from_awesome_motive( get_option( 'wp_mail_smtp' ) );
			case 'easy_wp_smtp':
				return self::from_awesome_motive( get_option( 'easy_wp_smtp' ) )
					?? self::from_legacy_easy_wp_smtp( get_option( 'swpsmtp_options' ) );
			case 'fluent_smtp':
				return self::from_fluent( get_option( 'fluentmail-settings' ) );
			case 'post_smtp':
				return self::from_post_smtp( get_option( 'postman_options' ) );
		}

		return null;
	}

	/**
	 * WP Mail SMTP / modern Easy WP SMTP shape.
	 *
	 * @param mixed $opt Stored option.
	 * @return array<string, mixed>|null
	 */
	private static function from_awesome_motive( $opt ): ?array {
		if ( ! is_array( $opt ) || empty( $opt['smtp']['host'] ) ) {
			return null;
		}
		$smtp = $opt['smtp'];
		$mail = is_array( $opt['mail'] ?? null ) ? $opt['mail'] : [];

		$mapped = [
			'smtp_host'       => (string) $smtp['host'],
			'smtp_port'       => (int) ( $smtp['port'] ?? 587 ),
			'smtp_encryption' => self::norm_encryption( (string) ( $smtp['encryption'] ?? 'tls' ) ),
			'smtp_auth'       => ! empty( $smtp['auth'] ),
			'smtp_username'   => (string) ( $smtp['user'] ?? '' ),
			'smtp_password'   => (string) ( $smtp['pass'] ?? '' ),
		];

		if ( ! empty( $mail['from_email_force'] ) && ! empty( $mail['from_email'] ) ) {
			$mapped['force_from_email'] = (string) $mail['from_email'];
		}
		if ( ! empty( $mail['from_name_force'] ) && ! empty( $mail['from_name'] ) ) {
			$mapped['force_from_name'] = (string) $mail['from_name'];
		}

		return $mapped;
	}

	/**
	 * Legacy Easy WP SMTP (pre-Awesome Motive) shape; password base64-encoded.
	 *
	 * @param mixed $opt Stored option.
	 * @return array<string, mixed>|null
	 */
	private static function from_legacy_easy_wp_smtp( $opt ): ?array {
		if ( ! is_array( $opt ) || empty( $opt['smtp_settings']['host'] ) ) {
			return null;
		}
		$smtp = $opt['smtp_settings'];

		return [
			'smtp_host'       => (string) $smtp['host'],
			'smtp_port'       => (int) ( $smtp['port'] ?? 587 ),
			'smtp_encryption' => self::norm_encryption( (string) ( $smtp['type_encryption'] ?? 'tls' ) ),
			'smtp_auth'       => 'yes' === ( $smtp['autentication'] ?? 'yes' ), // sic: their key is misspelled.
			'smtp_username'   => (string) ( $smtp['username'] ?? '' ),
			'smtp_password'   => self::maybe_base64( (string) ( $smtp['password'] ?? '' ) ),
		];
	}

	/**
	 * FluentSMTP: default connection when it is a generic-SMTP provider.
	 *
	 * @param mixed $opt Stored option.
	 * @return array<string, mixed>|null
	 */
	private static function from_fluent( $opt ): ?array {
		if ( ! is_array( $opt ) || empty( $opt['connections'] ) || ! is_array( $opt['connections'] ) ) {
			return null;
		}

		$default_key = $opt['misc']['default_connection'] ?? '';
		$connection  = $opt['connections'][ $default_key ] ?? reset( $opt['connections'] );
		$settings    = $connection['provider_settings'] ?? null;

		if ( ! is_array( $settings ) || 'smtp' !== ( $settings['provider'] ?? '' ) || empty( $settings['host'] ) ) {
			return null;
		}

		$mapped = [
			'smtp_host'       => (string) $settings['host'],
			'smtp_port'       => (int) ( $settings['port'] ?? 587 ),
			'smtp_encryption' => self::norm_encryption( (string) ( $settings['secure'] ?? 'tls' ) ),
			'smtp_auth'       => 'yes' === ( $settings['auth'] ?? 'no' ),
			'smtp_username'   => (string) ( $settings['username'] ?? '' ),
			'smtp_password'   => (string) ( $settings['password'] ?? '' ),
		];

		if ( ! empty( $settings['force_from_email'] ) && ! empty( $settings['sender_email'] ) ) {
			$mapped['force_from_email'] = (string) $settings['sender_email'];
		}

		return $mapped;
	}

	/**
	 * Post SMTP flat shape; password base64-encoded, SMTP transport only.
	 *
	 * @param mixed $opt Stored option.
	 * @return array<string, mixed>|null
	 */
	private static function from_post_smtp( $opt ): ?array {
		if ( ! is_array( $opt ) || empty( $opt['hostname'] ) ) {
			return null;
		}
		if ( ! in_array( $opt['transport_type'] ?? 'smtp', [ 'smtp', '' ], true ) ) {
			return null; // API transports (sendgrid_api etc.) are not importable yet.
		}

		$mapped = [
			'smtp_host'       => (string) $opt['hostname'],
			'smtp_port'       => (int) ( $opt['port'] ?? 587 ),
			'smtp_encryption' => self::norm_encryption( (string) ( $opt['enc_type'] ?? 'tls' ) ),
			'smtp_auth'       => 'none' !== ( $opt['auth_type'] ?? 'plain' ),
			'smtp_username'   => (string) ( $opt['basic_auth_username'] ?? '' ),
			'smtp_password'   => self::maybe_base64( (string) ( $opt['basic_auth_password'] ?? '' ) ),
		];

		if ( ! empty( $opt['sender_email'] ) ) {
			$mapped['force_from_email'] = (string) $opt['sender_email'];
		}
		if ( ! empty( $opt['sender_name'] ) ) {
			$mapped['force_from_name'] = (string) $opt['sender_name'];
		}

		return $mapped;
	}

	/**
	 * Normalize the many encryption spellings to none|ssl|tls.
	 *
	 * @param string $value Source value.
	 */
	private static function norm_encryption( string $value ): string {
		$value = strtolower( $value );

		return in_array( $value, [ 'ssl', 'tls' ], true ) ? $value : 'none';
	}

	/**
	 * Decode base64-stored passwords; pass through anything that round-trip fails.
	 *
	 * @param string $value Possibly-encoded password.
	 */
	private static function maybe_base64( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- source plugin stores base64.

		return ( false !== $decoded && base64_encode( $decoded ) === $value ) ? $decoded : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
