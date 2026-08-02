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
