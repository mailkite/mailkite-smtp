<?php
/**
 * Plugin Name:       MailKite SMTP – Multi-Provider SMTP with Failover, Email Logs & Inbound Email
 * Plugin URI:        https://mailkite.dev/docs/integrations/wordpress
 * Description:       Fix WordPress email delivery: MailKite, SendGrid, Brevo, Mailgun or any SMTP, with free logs, automatic failover, alerts, and inbound email.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            MailKite
 * Author URI:        https://mailkite.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailkite-smtp
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.3.0';
const PLUGIN_FILE = __FILE__;

define( 'MAILKITE_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILKITE_SMTP_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$path     = MAILKITE_SMTP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		Log\LogTable::install();
		set_transient( 'mailkite_smtp_activated', 1, MINUTE_IN_SECONDS );
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		foreach ( [ 'mailkite_smtp_purge_logs', 'mailkite_smtp_health_check', 'mailkite_smtp_weekly_summary' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
);

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
