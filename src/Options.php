<?php
/**
 * Settings storage with sanitization and wp-config constant overrides.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Single options-array wrapper. Constants MAILKITE_API_KEY and
 * MAILKITE_DEFAULT_MAILER override stored values (zero-UI host provisioning).
 */
final class Options {

	public const OPTION = 'mailkite_smtp_settings';

	private const DEFAULTS = [
		'mailer'           => 'php', // php | smtp | mailkite.
		'api_key'          => '',
		'api_base'         => 'https://api.mailkite.dev',
		'force_from_email' => '',
		'force_from_name'  => '',
		'smtp_host'        => '',
		'smtp_port'        => 587,
		'smtp_encryption'  => 'tls', // none | ssl | tls.
		'smtp_auth'        => true,
		'smtp_username'    => '',
		'smtp_password'    => '',
		'log_enabled'      => true,
		'log_redact_auth'  => true,
		'log_retention'    => 30,
		'fallback_enabled' => true,
		'alerts_enabled'   => false,
		'alert_email'      => '',
		'alert_webhook'    => '',
		'sendgrid_key'     => '',
		'brevo_key'        => '',
		'mailgun_key'      => '',
		'mailgun_domain'   => '',
		'mailgun_region'   => 'us',
		'track_opens'      => 'default', // default | on | off (MailKite sends only).
		'track_clicks'     => 'default',
		'routing_rules'    => [], // [ [ 'field' => subject|to, 'match' => str, 'mailer' => id ], ... ].
		'inbound_enabled'  => false,
		'inbound_secret'   => '',
		'inbound_forward'  => '',
		'summary_enabled'  => false,
	];

	public const MAILERS = [ 'php', 'smtp', 'mailkite', 'sendgrid', 'brevo', 'mailgun' ];

	/**
	 * All settings merged over defaults, with constant overrides applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored   = get_option( self::OPTION, [] );
		$settings = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );

		foreach ( [ 'api_key', 'smtp_password', 'sendgrid_key', 'brevo_key', 'mailgun_key', 'inbound_secret' ] as $secret ) {
			$settings[ $secret ] = Crypto::decrypt( (string) $settings[ $secret ] );
		}

		if ( defined( 'MAILKITE_API_KEY' ) && MAILKITE_API_KEY ) {
			$settings['api_key'] = MAILKITE_API_KEY;
		}
		if ( defined( 'MAILKITE_DEFAULT_MAILER' ) && MAILKITE_DEFAULT_MAILER ) {
			$settings['mailer'] = MAILKITE_DEFAULT_MAILER;
		}

		return $settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sanitize and persist a partial update.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> The stored settings.
	 */
	public static function update( array $input ): array {
		$current = array_merge( self::DEFAULTS, (array) get_option( self::OPTION, [] ) );
		$clean   = self::sanitize( $input );
		$merged  = array_merge( $current, $clean );
		update_option( self::OPTION, $merged, false );

		return $merged;
	}

	/**
	 * Sanitize a raw settings array; unknown keys are dropped.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$clean = [];

		if ( isset( $input['mailer'] ) && in_array( $input['mailer'], self::MAILERS, true ) ) {
			$clean['mailer'] = $input['mailer'];
		}
		foreach ( [ 'smtp_host', 'smtp_username', 'mailgun_domain' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		foreach ( [ 'api_key', 'sendgrid_key', 'brevo_key', 'mailgun_key', 'inbound_secret' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = Crypto::encrypt( sanitize_text_field( (string) $input[ $key ] ) );
			}
		}
		if ( isset( $input['smtp_password'] ) ) {
			$clean['smtp_password'] = Crypto::encrypt( (string) $input['smtp_password'] ); // Passwords keep their bytes; never rendered back.
		}
		if ( isset( $input['mailgun_region'] ) && in_array( $input['mailgun_region'], [ 'us', 'eu' ], true ) ) {
			$clean['mailgun_region'] = $input['mailgun_region'];
		}
		foreach ( [ 'track_opens', 'track_clicks' ] as $key ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], [ 'default', 'on', 'off' ], true ) ) {
				$clean[ $key ] = $input[ $key ];
			}
		}
		if ( isset( $input['alert_webhook'] ) ) {
			$clean['alert_webhook'] = esc_url_raw( (string) $input['alert_webhook'] );
		}
		if ( isset( $input['inbound_forward'] ) ) {
			$clean['inbound_forward'] = sanitize_email( (string) $input['inbound_forward'] );
		}
		if ( isset( $input['routing_rules'] ) && is_array( $input['routing_rules'] ) ) {
			$rules = [];
			foreach ( $input['routing_rules'] as $rule ) {
				if ( ! is_array( $rule ) || '' === trim( (string) ( $rule['match'] ?? '' ) ) ) {
					continue;
				}
				if ( in_array( $rule['field'] ?? '', [ 'subject', 'to' ], true ) && in_array( $rule['mailer'] ?? '', self::MAILERS, true ) ) {
					$rules[] = [
						'field'  => $rule['field'],
						'match'  => sanitize_text_field( (string) $rule['match'] ),
						'mailer' => $rule['mailer'],
					];
				}
			}
			$clean['routing_rules'] = $rules;
		}
		if ( isset( $input['api_base'] ) ) {
			$clean['api_base'] = esc_url_raw( untrailingslashit( (string) $input['api_base'] ) );
		}
		foreach ( [ 'force_from_email', 'alert_email' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_email( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['force_from_name'] ) ) {
			$clean['force_from_name'] = sanitize_text_field( (string) $input['force_from_name'] );
		}
		if ( isset( $input['smtp_port'] ) ) {
			$clean['smtp_port'] = max( 1, min( 65535, (int) $input['smtp_port'] ) );
		}
		if ( isset( $input['smtp_encryption'] ) && in_array( $input['smtp_encryption'], [ 'none', 'ssl', 'tls' ], true ) ) {
			$clean['smtp_encryption'] = $input['smtp_encryption'];
		}
		foreach ( [ 'smtp_auth', 'log_enabled', 'log_redact_auth', 'fallback_enabled', 'alerts_enabled', 'inbound_enabled', 'summary_enabled' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( isset( $input['log_retention'] ) ) {
			$clean['log_retention'] = max( 1, (int) $input['log_retention'] );
		}

		return $clean;
	}

	/**
	 * wp_mail_from filter: apply forced from address when configured.
	 *
	 * @param string $email Current from email.
	 * @return string
	 */
	public static function filter_from_email( string $email ): string {
		$forced = (string) self::get( 'force_from_email' );

		return ( $forced && is_email( $forced ) ) ? $forced : $email;
	}

	/**
	 * wp_mail_from_name filter: apply forced from name when configured.
	 *
	 * @param string $name Current from name.
	 * @return string
	 */
	public static function filter_from_name( string $name ): string {
		$forced = (string) self::get( 'force_from_name' );

		return '' !== $forced ? $forced : $name;
	}
}
