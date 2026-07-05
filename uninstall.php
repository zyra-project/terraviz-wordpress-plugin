<?php
/**
 * Uninstall cleanup for the Terraviz plugin.
 *
 * Removes the single settings option and any cached transients. Runs only
 * when the user deletes the plugin from wp-admin.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove settings.
delete_option( 'terraviz_settings' );

// Remove cached catalog/dataset transients (single site).
global $wpdb;
if ( isset( $wpdb ) ) {
	$terraviz_like         = $wpdb->esc_like( '_transient_terraviz_' ) . '%';
	$terraviz_timeout_like = $wpdb->esc_like( '_transient_timeout_terraviz_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_like ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_timeout_like ) );
}

// Multisite: clean each site.
if ( is_multisite() ) {
	$terraviz_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $terraviz_sites as $terraviz_site_id ) {
		switch_to_blog( (int) $terraviz_site_id );
		delete_option( 'terraviz_settings' );
		restore_current_blog();
	}
}
