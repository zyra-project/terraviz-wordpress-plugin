<?php
/**
 * Uninstall cleanup for the Terraviz plugin.
 *
 * Removes the settings option, the stored publish credential, any cached
 * transients, and the custom `manage_terraviz` capability. Runs only when the
 * user deletes the plugin from wp-admin. On multisite, every site's option and
 * transients are cleaned.
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
	delete_option( 'terraviz_credential' );

	// Drop the custom capability from every role that carries it.
	if ( function_exists( 'wp_roles' ) ) {
		$terraviz_roles = wp_roles();
		foreach ( array_keys( $terraviz_roles->roles ) as $terraviz_role_name ) {
			$terraviz_role = get_role( $terraviz_role_name );
			if ( $terraviz_role instanceof WP_Role && $terraviz_role->has_cap( 'manage_terraviz' ) ) {
				$terraviz_role->remove_cap( 'manage_terraviz' );
			}
		}
	}

	if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
		return;
	}

	$terraviz_like         = $wpdb->esc_like( '_transient_terraviz_' ) . '%';
	$terraviz_timeout_like = $wpdb->esc_like( '_transient_timeout_terraviz_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_like ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $terraviz_timeout_like ) );

	// Drop the blog-sync post meta the plugin wrote.
	foreach ( array( '_terraviz_blog_optin', '_terraviz_blog_id', '_terraviz_blog_slug', '_terraviz_blog_state' ) as $terraviz_meta_key ) {
		delete_post_meta_by_key( $terraviz_meta_key );
	}
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
