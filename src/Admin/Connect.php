<?php
/**
 * MailKite account connection: in-admin registration, OAuth connect, verification status.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Admin;

use MailKite\Smtp\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Three ways to a working API key without leaving wp-admin:
 *  1. Create account: email → POST /api/v1/provision (register-by-email, no password).
 *     The key is returned immediately; SENDING stays blocked until the email is
 *     verified (the status line below polls /v1/me).
 *  2. Connect existing account via OAuth: RFC 7591 dynamic client registration +
 *     authorization-code + PKCE against MailKite's authorization server, then
 *     GET /api/keys with the access token. No credentials are typed into WordPress.
 *  3. Paste an API key (the plain field that was always there).
 */
final class Connect {

	private const AFFILIATE_REF = 'xmbf3bd0'; // MailKite's own attribution code for the plugin channel.
	private const CHANNEL       = 'wordpress-plugin';
	private const STATE_KEY     = 'mailkite_smtp_oauth_state'; // transient: state → {verifier, client_id, redirect_uri}.
	private const STATUS_KEY    = 'mailkite_smtp_me';          // transient cache of GET /v1/me.

	/**
	 * Hook the admin-post handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_mailkite_smtp_provision', [ $this, 'handle_provision' ] );
		add_action( 'admin_post_mailkite_smtp_oauth_start', [ $this, 'handle_oauth_start' ] );
		add_action( 'admin_post_mailkite_smtp_oauth_cb', [ $this, 'handle_oauth_cb' ] );
		add_action( 'admin_post_mailkite_smtp_recheck', [ $this, 'handle_recheck' ] );
		add_action( 'admin_post_mailkite_smtp_disconnect', [ $this, 'handle_disconnect' ] );
	}

	/**
	 * Forget the stored API key (admin-post). The MailKite account itself is untouched.
	 */
	public function handle_disconnect(): void {
		$this->guard( 'mailkite_smtp_disconnect' );
		Options::update( [ 'api_key' => '' ] );
		delete_transient( self::STATUS_KEY );
		$this->redirect( 'saved' );
	}

	/**
	 * Create a MailKite account from an email (admin-post).
	 */
	public function handle_provision(): void {
		$this->guard( 'mailkite_smtp_provision' );

		$email = isset( $_POST['account_email'] ) ? sanitize_email( wp_unslash( $_POST['account_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			$this->redirect( 'provision_invalid' );
		}

		$response = wp_remote_post(
			Options::get( 'api_base' ) . '/api/v1/provision',
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'email'    => $email,
						'channel'  => self::CHANNEL,
						'ref'      => self::AFFILIATE_REF,
						'referrer' => home_url(),
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			$this->redirect( 'provision_failed' );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === $code && ! empty( $payload['api_key'] ) ) {
			Options::update(
				[
					'api_key' => (string) $payload['api_key'],
					'mailer'  => 'mailkite',
				]
			);
			delete_transient( self::STATUS_KEY );
			$this->redirect( 'provisioned' );
		}
		if ( 409 === $code ) {
			$this->redirect( 'account_exists' );
		}
		if ( 429 === $code ) {
			$this->redirect( 'rate_limited' );
		}
		$this->redirect( 'provision_failed' );
	}

	/**
	 * Begin the OAuth connect flow (admin-post): register an ephemeral client
	 * (RFC 7591), stash PKCE verifier + CSRF state, redirect to authorize.
	 */
	public function handle_oauth_start(): void {
		$this->guard( 'mailkite_smtp_oauth_start' );

		$base         = (string) Options::get( 'api_base' );
		$redirect_uri = admin_url( 'admin-post.php?action=mailkite_smtp_oauth_cb' );

		$reg = wp_remote_post(
			$base . '/oauth/register',
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'client_name'   => 'MailKite SMTP for WordPress (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')',
						'redirect_uris' => [ $redirect_uri ],
					]
				),
			]
		);
		$client_id = is_wp_error( $reg ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $reg ), true )['client_id'] ?? '' );
		if ( '' === $client_id ) {
			$this->redirect( 'oauth_failed' );
		}

		$verifier  = wp_generate_password( 64, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 per RFC 7636.
		$state     = wp_generate_password( 32, false );

		set_transient(
			self::STATE_KEY,
			[
				'state'        => $state,
				'verifier'     => $verifier,
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
				'user'         => get_current_user_id(),
			],
			15 * MINUTE_IN_SECONDS
		);

		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external authorize URL by design.
			$base . '/oauth/authorize?' . http_build_query(
				[
					'response_type'         => 'code',
					'client_id'             => $client_id,
					'redirect_uri'          => $redirect_uri,
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
					'scope'                 => 'mcp',
					'state'                 => $state,
				]
			)
		);
		exit;
	}

	/**
	 * OAuth callback (admin-post): validate state, exchange the code (PKCE),
	 * fetch the account API key with the access token, store it.
	 */
	public function handle_oauth_cb(): void {
		// No WP nonce here by design: the OAuth `state` value (single-use transient,
		// bound to the initiating admin user) is the CSRF protection for this leg.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}

		$stored = get_transient( self::STATE_KEY );
		delete_transient( self::STATE_KEY ); // Single use, success or not.

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- state IS the CSRF token, checked below.
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_array( $stored ) || '' === $code || '' === $state
			|| ! hash_equals( (string) $stored['state'], $state )
			|| get_current_user_id() !== (int) $stored['user'] ) {
			$this->redirect( 'oauth_failed' );
		}

		$base = (string) Options::get( 'api_base' );
		$tok  = wp_remote_post(
			$base . '/oauth/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => (string) $stored['redirect_uri'],
					'client_id'     => (string) $stored['client_id'],
					'code_verifier' => (string) $stored['verifier'],
				],
			]
		);
		$access = is_wp_error( $tok ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $tok ), true )['access_token'] ?? '' );
		if ( '' === $access ) {
			$this->redirect( 'oauth_failed' );
		}

		$keys = wp_remote_get(
			$base . '/api/keys',
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $access ],
			]
		);
		$key = is_wp_error( $keys ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $keys ), true )['key'] ?? '' );
		if ( '' === $key ) {
			$this->redirect( 'oauth_failed' );
		}

		Options::update(
			[
				'api_key' => $key,
				'mailer'  => 'mailkite',
			]
		);
		delete_transient( self::STATUS_KEY );
		$this->redirect( 'connected' );
	}

	/**
	 * Clear the cached /v1/me status (admin-post "Re-check").
	 */
	public function handle_recheck(): void {
		$this->guard( 'mailkite_smtp_recheck' );
		delete_transient( self::STATUS_KEY );
		$this->redirect( 'saved' );
	}

	/**
	 * The connected account per GET /v1/me, cached for a minute.
	 *
	 * @return array{email: string, emailVerified: bool, plan: string}|null Null when no key or unreachable.
	 */
	public static function account_status(): ?array {
		if ( '' === (string) Options::get( 'api_key' ) ) {
			return null;
		}
		$cached = get_transient( self::STATUS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			Options::get( 'api_base' ) . '/v1/me',
			[
				'timeout' => 10,
				'headers' => [ 'Authorization' => 'Bearer ' . (string) Options::get( 'api_key' ) ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) || empty( $payload['email'] ) ) {
			return null;
		}
		$status = [
			'email'         => (string) $payload['email'],
			'emailVerified' => ! empty( $payload['emailVerified'] ),
			'plan'          => (string) ( $payload['plan'] ?? '' ),
		];
		set_transient( self::STATUS_KEY, $status, MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Nonce + capability guard.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the settings tab with a notice.
	 *
	 * @param string $notice Notice code.
	 * @return never
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => 'mailkite-smtp', 'tab' => 'settings', 'notice' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
