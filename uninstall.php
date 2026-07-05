<?php
/**
 * Uninstall cleanup for the Terraviz plugin.
 *
 * Removes the single settings option and any cached transients. Runs only
 * when the user deletes the plugin from wp-admin. On multisite, every site's
 * option and transients are cleaned.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's option and cached transients for the current site.
 *
 * On multisite this operates on whichever blog is currently switched to, so
 * `$wpdb->options` targets that site's options table.
 */
function terraviz_uninstall_current_site(): void {
	global $wpdb;

	delete_option( 'terraviz_settings' );

	if ( ! isset( $wpdb ) ) {
		return;
	}

	$terraviz_like         = $wpdb->esc_like( '_transient_terraviz_' ) . '%';
	$terraviz_timeout_like = $wpdb->esc_like( '_transient_timeout_terraviz_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_like ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_timeout_like ) );
}

if ( is_multisite() ) {
	$terraviz_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $terraviz_sites as $terraviz_site_id ) {
		switch_to_blog( (int) $terraviz_site_id );
		terraviz_uninstall_current_site();
		restore_current_blog();
	}
} else {
	terraviz_uninstall_current_site();
}
