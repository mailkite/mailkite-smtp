<?php
/**
 * Inbound email webhook receiver — the capability no other SMTP plugin has.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

use MailKite\Smtp\Log\LogTable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Receives MailKite inbound-email webhooks at
 * POST /wp-json/mailkite-smtp/v1/inbound?token=<secret>, then:
 *  1. fires `do_action( 'mailkite_smtp_inbound', array $message )` for plugins,
 *  2. optionally forwards a copy to a configured address,
 *  3. records the message in the email log (direction: inbound).
 *
 * Auth: a per-site random token compared in constant time. The MailKite
 * dashboard webhook settings point at this URL.
 */
final class Inbound {

	/**
	 * Register the public REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'mailkite-smtp/v1',
			'/inbound',
			[
				'methods'             => 'POST',
				// Public by design: this is a webhook endpoint authenticated by the
				// constant-time token check in the callback, not by a WP user.
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'receive' ],
			]
		);
	}

	/**
	 * The site's inbound webhook URL (empty until enabled).
	 */
	public static function url(): string {
		$secret = (string) Options::get( 'inbound_secret' );
		if ( '' === $secret || ! Options::get( 'inbound_enabled' ) ) {
			return '';
		}

		return add_query_arg( 'token', $secret, rest_url( 'mailkite-smtp/v1/inbound' ) );
	}

	/**
	 * Generate (or rotate) the webhook secret.
	 */
	public static function rotate_secret(): void {
		Options::update( [ 'inbound_secret' => wp_generate_password( 32, false ) ] );
	}

	/**
	 * The account's domains via GET /api/domains (5-minute cache).
	 *
	 * @return array<int, array{id: string, domain: string}>|null Null when no key or unreachable.
	 */
	public static function list_domains(): ?array {
		$key = (string) Options::get( 'api_key' );
		if ( '' === $key ) {
			return null;
		}
		$cached = get_transient( 'mailkite_smtp_domains' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			Options::get( 'api_base' ) . '/api/domains',
			[
				'timeout' => 10,
				'headers' => [ 'Authorization' => 'Bearer ' . $key ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$domains = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && ! empty( $row['domain'] ) ) {
				$domains[] = [
					'id'     => (string) $row['id'],
					'domain' => (string) $row['domain'],
				];
			}
		}
		set_transient( 'mailkite_smtp_domains', $domains, 5 * MINUTE_IN_SECONDS );

		return $domains;
	}

	/**
	 * Fully automated inbound setup on a MailKite domain: enable the local
	 * endpoint, install its URL as the domain webhook, and fetch the domain's
	 * signing secret so signature verification is on from the first delivery.
	 *
	 * @param string $domain_id The dom_… to connect.
	 * @return true|\WP_Error
	 */
	public static function connect( string $domain_id ) {
		$key  = (string) Options::get( 'api_key' );
		$base = (string) Options::get( 'api_base' );
		if ( '' === $key ) {
			return new WP_Error( 'no_key', __( 'Connect a MailKite account first.', 'mailkite-smtp' ) );
		}

		$domains = self::list_domains() ?? [];
		$name    = '';
		foreach ( $domains as $candidate ) {
			if ( $candidate['id'] === $domain_id ) {
				$name = $candidate['domain'];
			}
		}
		if ( '' === $name ) {
			return new WP_Error( 'bad_domain', __( 'That domain does not belong to the connected account.', 'mailkite-smtp' ) );
		}

		if ( '' === (string) Options::get( 'inbound_secret' ) ) {
			self::rotate_secret();
		}
		Options::update( [ 'inbound_enabled' => true ] );

		$auth = [ 'Authorization' => 'Bearer ' . $key ];
		$put  = wp_remote_request(
			$base . '/api/domains/' . rawurlencode( $domain_id ) . '/webhook',
			[
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => $auth + [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'url' => self::url() ] ),
			]
		);
		if ( is_wp_error( $put ) || 2 !== intdiv( (int) wp_remote_retrieve_response_code( $put ), 100 ) ) {
			Options::update( [ 'inbound_enabled' => false ] );

			return new WP_Error( 'webhook_failed', __( 'MailKite rejected the webhook — is the domain verified?', 'mailkite-smtp' ) );
		}

		// Signing secret → automatic HMAC verification (best-effort: token auth still guards
		// the endpoint if this call fails).
		$sec    = wp_remote_get(
			$base . '/api/domains/' . rawurlencode( $domain_id ) . '/webhook/secret',
			[
				'timeout' => 10,
				'headers' => $auth,
			]
		);
		$secret = is_wp_error( $sec ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $sec ), true )['secret'] ?? '' );

		Options::update(
			[
				'inbound_domain'      => $name,
				'inbound_domain_id'   => $domain_id,
				'inbound_hmac_secret' => $secret,
			]
		);

		return true;
	}

	/**
	 * Tear inbound down: remove the domain webhook and disable the endpoint.
	 */
	public static function disconnect(): void {
		$key       = (string) Options::get( 'api_key' );
		$domain_id = (string) Options::get( 'inbound_domain_id' );
		if ( '' !== $key && '' !== $domain_id ) {
			wp_remote_request(
				Options::get( 'api_base' ) . '/api/domains/' . rawurlencode( $domain_id ) . '/webhook',
				[
					'method'  => 'DELETE',
					'timeout' => 10,
					'headers' => [ 'Authorization' => 'Bearer ' . $key ],
				]
			);
		}
		Options::update(
			[
				'inbound_enabled'     => false,
				'inbound_domain'      => '',
				'inbound_domain_id'   => '',
				'inbound_hmac_secret' => '',
			]
		);
	}

	/**
	 * Webhook callback.
	 *
	 * @param WP_REST_Request $request Incoming webhook.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( WP_REST_Request $request ) {
		$secret = (string) Options::get( 'inbound_secret' );
		$token  = (string) $request->get_param( 'token' );

		if ( ! Options::get( 'inbound_enabled' ) || '' === $secret || ! hash_equals( $secret, $token ) ) {
			return new WP_Error( 'forbidden', 'Invalid token.', [ 'status' => 403 ] );
		}

		// When a signing secret is configured, additionally require MailKite's
		// x-mailkite-signature header: `t=<ms>,v1=<hex>` where
		// v1 = HMAC-SHA256(secret, "{t}.{raw body}"), fresh within ±5 minutes.
		// (Scheme per the MailKite spec's verifyWebhook; constant-time compare.)
		$hmac_secret = (string) Options::get( 'inbound_hmac_secret' );
		if ( '' !== $hmac_secret && ! $this->valid_signature( $request, $hmac_secret ) ) {
			return new WP_Error( 'bad_signature', 'Invalid or stale signature.', [ 'status' => 403 ] );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'bad_request', 'Expected a JSON body.', [ 'status' => 400 ] );
		}

		// MailKite webhook payloads nest the parsed email under `message`; accept
		// a bare message object too so manual tests work.
		$message = is_array( $payload['message'] ?? null ) ? $payload['message'] : $payload;

		/**
		 * An inbound email arrived via MailKite.
		 *
		 * @param array<string, mixed> $message Parsed message (from, to, subject, text, html, …).
		 * @param array<string, mixed> $payload Full webhook payload.
		 */
		do_action( 'mailkite_smtp_inbound', $message, $payload );

		$subject = (string) ( $message['subject'] ?? '' );
		$from    = (string) ( $message['from'] ?? '' );

		$forward = (string) Options::get( 'inbound_forward' );
		if ( is_email( $forward ) ) {
			wp_mail(
				$forward,
				/* translators: %s: original subject. */
				sprintf( __( '[Inbound] %s', 'mailkite-smtp' ), $subject ),
				(string) ( $message['text'] ?? wp_strip_all_tags( (string) ( $message['html'] ?? '' ) ) )
				. "\n\n-- \n" . sprintf( 'From: %s', $from )
			);
		}

		if ( Options::get( 'log_enabled' ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
			$wpdb->insert(
				LogTable::name(),
				[
					'created_at' => current_time( 'mysql', true ),
					'mail_to'    => implode( ', ', (array) ( $message['to'] ?? [] ) ),
					'subject'    => sprintf( '%s (from %s)', $subject, $from ),
					'body'       => null,
					'headers'    => null,
					'mailer'     => 'inbound',
					'status'     => 'received',
					'redacted'   => 1,
				]
			);
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * Verify `x-mailkite-signature: t=<ms>,v1=<hex>` over the raw request body.
	 *
	 * @param WP_REST_Request $request Incoming webhook.
	 * @param string          $secret  The whsec_… signing secret.
	 */
	private function valid_signature( WP_REST_Request $request, string $secret ): bool {
		$header = (string) $request->get_header( 'x-mailkite-signature' );
		if ( '' === $header ) {
			return false;
		}

		$parts = [];
		foreach ( explode( ',', $header ) as $pair ) {
			$eq = strpos( $pair, '=' );
			if ( false !== $eq ) {
				$parts[ trim( substr( $pair, 0, $eq ) ) ] = trim( substr( $pair, $eq + 1 ) );
			}
		}
		$timestamp = $parts['t'] ?? '';
		$given     = $parts['v1'] ?? '';
		if ( ! ctype_digit( $timestamp ) || '' === $given ) {
			return false;
		}
		if ( abs( time() * 1000 - (int) $timestamp ) > 5 * MINUTE_IN_SECONDS * 1000 ) {
			return false; // Stale or replayed.
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );

		return hash_equals( $expected, strtolower( $given ) );
	}
}
