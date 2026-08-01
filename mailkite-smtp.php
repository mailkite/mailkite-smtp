<?php
/**
 * Plugin Name:       MailKite SMTP – SMTP and Email Log Plugin for Any SMTP Provider
 * Plugin URI:        https://mailkite.dev/docs/integrations/wordpress
 * Description:       Fix WordPress email delivery. Send via MailKite (2-minute setup, inbound included) or your own SMTP server, with free email logs and resend.
 * Version:           0.1.0
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

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define( 'MAILKITE_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILKITE_SMTP_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$path     = MAILKITE_SMTP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, [ Log\LogTable::class, 'install' ] );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'mailkite_smtp_purge_logs' );
	}
);

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
