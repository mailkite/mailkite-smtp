<?php
/**
 * REST endpoints (consumed by the React wizard in Phase 3; usable today).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Admin;

use MailKite\Smtp\Log\LogTable;
use MailKite\Smtp\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace mailkite-smtp/v1. All routes require manage_options; secrets are
 * never echoed back — only *_set booleans.
 */
final class Rest {

	private const NS = 'mailkite-smtp/v1';

	/**
	 * Register routes on rest_api_init.
	 */
	public function register_routes(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::NS,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => [ $this, 'get_settings' ],
				],
				[
					'methods'             => 'POST',
					'permission_callback' => $permission,
					'callback'            => [ $this, 'save_settings' ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/test',
			[
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => [ $this, 'send_test' ],
				'args'                => [
					'to' => [
						'type'              => 'string',
						'required'          => true,
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/logs',
			[
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => [ $this, 'get_logs' ],
			]
		);
	}

	/**
	 * GET /settings — secrets masked.
	 */
	public function get_settings(): WP_REST_Response {
		$settings = Options::all();

		$settings['api_key_set']       = '' !== (string) $settings['api_key'];
		$settings['smtp_password_set'] = '' !== (string) $settings['smtp_password'];
		unset( $settings['api_key'], $settings['smtp_password'] );

		return rest_ensure_response( $settings );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		Options::update( (array) $request->get_json_params() );

		return $this->get_settings();
	}

	/**
	 * POST /test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test( WP_REST_Request $request ) {
		$sent = wp_mail(
			(string) $request['to'],
			__( 'MailKite SMTP test email', 'mailkite-smtp' ),
			/* translators: %s: site URL. */
			sprintf( __( 'This is a test email from %s. If you are reading this, delivery works.', 'mailkite-smtp' ), home_url() )
		);

		if ( ! $sent ) {
			return new WP_Error( 'test_failed', __( 'Test email failed — check the email log.', 'mailkite-smtp' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [ 'sent' => true ] );
	}

	/**
	 * GET /logs — latest 100, no bodies.
	 */
	public function get_logs(): WP_REST_Response {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, mail_to, subject, mailer, status, error, redacted FROM %i ORDER BY id DESC LIMIT 100', LogTable::name() ) );

		return rest_ensure_response( $rows );
	}
}
