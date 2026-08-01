<?php
/**
 * Uninstall cleanup: remove options and the log table.
 *
 * @package MailKite\Smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mailkite_smtp_settings' );
delete_option( 'mailkite_smtp_db_version' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup of our own table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mailkite_smtp_log" );
