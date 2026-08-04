<?php
/**
 * Uninstall cleanup: remove options and the log table.
 *
 * @package MailKite\Smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mailkite_smtp_settings' );
delete_option( 'mailkite_smtp_db_version' );
delete_option( 'mailkite_smtp_health' );

foreach ( [ 'mailkite_smtp_purge_logs', 'mailkite_smtp_health_check', 'mailkite_smtp_weekly_summary' ] as $mailkite_smtp_hook ) {
	wp_clear_scheduled_hook( $mailkite_smtp_hook );
}

global $wpdb;
// Schema v3 split bodies and attachments into their own tables. Dropping only the log
// table left those two behind on every uninstall — invisible, and still holding message
// text. Children first so nothing outlives its parent row.
foreach ( [ 'mailkite_smtp_attachment', 'mailkite_smtp_body', 'mailkite_smtp_log' ] as $mailkite_smtp_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $mailkite_smtp_table ) );
}
