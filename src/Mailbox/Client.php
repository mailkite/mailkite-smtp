<?php
/**
 * MailKite API calls for mailboxes: minting app passwords, reading mail.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mailbox;

use MailKite\Smtp\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Two credentials, two jobs:
 *  - the SITE key mints and revokes app passwords (account-level, admin action),
 *  - a USER's app password reads that user's mail (`/api/mailbox/*`).
 * The site key is never used to read anyone's messages.
 */
final class Client {

	/**
	 * Mint an app password for one address, valid for IMAP and the mailbox API.
	 *
	 * @param string $domain Domain name (must belong to the account).
	 * @param string $local  Local part — the exact address, not a pattern.
	 * @param string $label  Shown in the MailKite dashboard.
	 * @return array{id: string, secret: string}|WP_Error
	 */
	public static function create_app_password( string $domain, string $local, string $label ) {
		$response = wp_remote_post(
			Options::get( 'api_base' ) . '/api/app-passwords',
			[
				'timeout' => 15,
				'headers' => self::site_auth() + [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'domain'    => $domain,
						'address'   => $local,
						'protocols' => [ 'imap', 'api' ],
						'label'     => $label,
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 2 !== intdiv( $code, 100 ) || empty( $payload['id'] ) || empty( $payload['secret'] ) ) {
			$detail = is_array( $payload ) ? (string) ( $payload['error'] ?? '' ) : '';
			if ( 403 === $code ) {
				$detail = __( 'the site is connected with a domain-scoped key — reconnect with the account key to mint mailboxes', 'mailkite-smtp' );
			}

			return new WP_Error(
				'app_password_failed',
				/* translators: %s: reason from the API. */
				sprintf( __( 'MailKite could not create the mailbox credential: %s', 'mailkite-smtp' ), $detail ? $detail : (string) $code )
			);
		}

		return [
			'id'     => (string) $payload['id'],
			'secret' => (string) $payload['secret'],
		];
	}

	/**
	 * Revoke an app password by id (best effort).
	 *
	 * @param string $id App password id (apw_…).
	 */
	public static function delete_app_password( string $id ): void {
		wp_remote_request(
			Options::get( 'api_base' ) . '/api/app-passwords/' . rawurlencode( $id ),
			[
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => self::site_auth(),
			]
		);
	}

	/**
	 * List a mailbox's messages with the user's own app password.
	 *
	 * @param string   $secret  The user's mk_pw_… secret.
	 * @param string   $address Full address.
	 * @param string   $mailbox INBOX or Sent.
	 * @param int|null $before  Pagination cursor (uid).
	 * @return array{messages: array<int, array<string, mixed>>, nextBefore: int|null}|WP_Error
	 */
	public static function list_messages( string $secret, string $address, string $mailbox = 'INBOX', ?int $before = null ) {
		$args = [
			'address' => $address,
			'mailbox' => 'Sent' === $mailbox ? 'Sent' : 'INBOX',
			'limit'   => 50,
		];
		if ( $before ) {
			$args['before'] = $before;
		}
		$response = wp_remote_get(
			add_query_arg( $args, Options::get( 'api_base' ) . '/api/mailbox/messages' ),
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $secret ],
			]
		);

		return self::decode( $response );
	}

	/**
	 * Fetch one message's raw RFC822 bytes.
	 *
	 * @param string $secret  The user's mk_pw_… secret.
	 * @param string $address Full address.
	 * @param int    $uid     Message uid.
	 * @param string $mailbox INBOX or Sent.
	 * @return string|WP_Error
	 */
	public static function raw( string $secret, string $address, int $uid, string $mailbox = 'INBOX' ) {
		$response = wp_remote_get(
			add_query_arg(
				[
					'address' => $address,
					'mailbox' => 'Sent' === $mailbox ? 'Sent' : 'INBOX',
				],
				Options::get( 'api_base' ) . '/api/mailbox/messages/' . (int) $uid . '/raw'
			),
			[
				'timeout' => 20,
				'headers' => [ 'Authorization' => 'Bearer ' . $secret ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 2 !== intdiv( (int) wp_remote_retrieve_response_code( $response ), 100 ) ) {
			return new WP_Error( 'mailbox_error', __( 'Could not fetch that message.', 'mailkite-smtp' ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Replace a message's IMAP flags (e.g. mark Seen).
	 *
	 * @param string $secret  The user's mk_pw_… secret.
	 * @param string $address Full address.
	 * @param int    $uid     Message uid.
	 * @param string $flags   Space-separated flags without backslashes.
	 * @param string $mailbox INBOX or Sent.
	 */
	public static function set_flags( string $secret, string $address, int $uid, string $flags, string $mailbox = 'INBOX' ): void {
		wp_remote_post(
			add_query_arg(
				[
					'address' => $address,
					'mailbox' => 'Sent' === $mailbox ? 'Sent' : 'INBOX',
				],
				Options::get( 'api_base' ) . '/api/mailbox/messages/' . (int) $uid . '/flags'
			),
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'flags' => $flags ] ),
			]
		);
	}

	/**
	 * Authorization header for account-level calls (the site's key).
	 *
	 * @return array<string, string>
	 */
	private static function site_auth(): array {
		return [ 'Authorization' => 'Bearer ' . (string) Options::get( 'api_key' ) ];
	}

	/**
	 * Decode a JSON API response into an array or WP_Error.
	 *
	 * @param array<string, mixed>|WP_Error $response wp_remote_* result.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 2 !== intdiv( $code, 100 ) || ! is_array( $payload ) ) {
			$detail = is_array( $payload ) ? (string) ( $payload['error'] ?? '' ) : '';

			return new WP_Error(
				'mailbox_error',
				/* translators: %s: reason from the API. */
				sprintf( __( 'MailKite returned an error: %s', 'mailkite-smtp' ), $detail ? $detail : (string) $code )
			);
		}

		return $payload;
	}
}
