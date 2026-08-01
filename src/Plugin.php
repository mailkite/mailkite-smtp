<?php
/**
 * Plugin bootstrap: wires hooks.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrapper. All hooks are registered here so the wiring is auditable.
 */
final class Plugin {

	/**
	 * Boot the plugin once on plugins_loaded.
	 */
	public static function boot(): void {
		$interceptor = new Mail\Interceptor();
		add_filter( 'pre_wp_mail', [ $interceptor, 'maybe_send' ], 10, 2 );
		add_action( 'phpmailer_init', [ new Mail\Mailers\SmtpMailer(), 'configure' ] );

		add_filter( 'wp_mail_from', [ Options::class, 'filter_from_email' ], 20 );
		add_filter( 'wp_mail_from_name', [ Options::class, 'filter_from_name' ], 20 );

		$logger = Log\Logger::instance();
		add_filter( 'wp_mail', [ $logger, 'capture' ], PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', [ $logger, 'on_succeeded' ] );
		add_action( 'wp_mail_failed', [ $logger, 'on_failed' ] );

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
		}
		add_action( 'rest_api_init', [ new Admin\Rest(), 'register_routes' ] );
	}
}
