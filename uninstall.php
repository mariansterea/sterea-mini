<?php
/**
 * Removes plugin data on uninstall (themes, bookings, settings, slot locks).
 *
 * @package Sterea_Mini
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'stm_settings' );
delete_option( 'stm_db_version' );
delete_option( 'stm_version' );
delete_option( 'stm_gcal_status' );
delete_transient( 'stm_gcal_token' );
delete_site_transient( 'stm_github_release' );
wp_clear_scheduled_hook( 'stm_send_reminders' );

foreach ( array( 'stm_theme', 'stm_booking', 'stm_coupon' ) as $stm_type ) {
	$stm_ids = get_posts(
		array(
			'post_type'   => $stm_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $stm_ids as $stm_id ) {
		wp_delete_post( $stm_id, true );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}stm_locks" );
