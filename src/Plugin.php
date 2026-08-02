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
		add_action( 'wp_mail_failed', [ new Alerts(), 'on_failed' ], 20 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mailkite', Cli\Commands::class );
		}

		if ( is_admin() ) {
			( new Admin\Menu() )->register();
			( new Admin\Connect() )->register();
			add_action( 'admin_init', [ self::class, 'maybe_activation_redirect' ] );
		}
		add_action( 'rest_api_init', [ new Admin\Rest(), 'register_routes' ] );
		add_action( 'rest_api_init', [ new Inbound(), 'register_routes' ] );
		add_filter( 'site_status_tests', [ new SiteHealth(), 'register' ] );

		add_action(
			'mailkite_smtp_purge_logs',
			static function (): void {
				Log\LogTable::purge( (int) Options::get( 'log_retention' ) );
			}
		);
		add_action( 'mailkite_smtp_health_check', [ Health::class, 'cron_check' ] );
		add_action( 'mailkite_smtp_weekly_summary', [ Summary::class, 'cron_send' ] );

		if ( ! wp_next_scheduled( 'mailkite_smtp_purge_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mailkite_smtp_purge_logs' );
		}
		if ( ! wp_next_scheduled( 'mailkite_smtp_health_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'mailkite_smtp_health_check' );
		}
		if ( ! wp_next_scheduled( 'mailkite_smtp_weekly_summary' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'weekly', 'mailkite_smtp_weekly_summary' );
		}
	}

	/**
	 * One-time redirect to our settings page right after activation
	 * (skipped on bulk/network activation).
	 */
	public static function maybe_activation_redirect(): void {
		if ( ! get_transient( 'mailkite_smtp_activated' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}
		delete_transient( 'mailkite_smtp_activated' );
		if ( isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bulk-activation signal.
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mailkite-smtp' ) );
		exit;
	}
}
