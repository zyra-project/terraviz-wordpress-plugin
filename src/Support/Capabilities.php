<?php
/**
 * WordPress capability mapping for Terraviz publishing (Phase 2, Integration F).
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WordPress capabilities to the Terraviz publish tier a WP user is
 * *intended* to have through the plugin.
 *
 * This is deliberately **local authorization only**. When the plugin
 * publishes, every call is proxied through PHP carrying **one** shared
 * service-token identity — Terraviz sees the same `service` publisher no matter
 * which WP user acted (upstream WORDPRESS_INTEGRATION_PLAN §5, Option 1). So the
 * per-user gate has to live here, in WordPress: this class decides who in
 * `wp-admin` may trigger which publish action. It performs no catalog mutation
 * and makes no network calls.
 *
 * The custom `manage_terraviz` capability is the plugin's own admin gate,
 * granted to `administrator` on activation so a site owner can delegate
 * Terraviz configuration (node origin, the future credential) without handing
 * out full `manage_options`.
 */
final class Capabilities {

	/**
	 * The plugin's custom capability: configure the node/credential and
	 * (in a later phase) publish anything.
	 */
	public const MANAGE = 'manage_terraviz';

	/**
	 * Publish-intent tiers, most-privileged first. These name what a WP user
	 * may do *through the plugin*; they are not Terraviz roles.
	 */
	public const INTENT_CONFIGURE = 'configure';
	public const INTENT_PUBLISH   = 'publish';
	public const INTENT_DRAFT     = 'draft';
	public const INTENT_EMBED     = 'embed';

	/**
	 * Grant the custom capability to the administrator role. Idempotent;
	 * called on activation.
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof \WP_Role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Remove the custom capability from every role. Called on deactivation so
	 * the plugin leaves no orphaned capability behind.
	 */
	public static function revoke(): void {
		$roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
		if ( null === $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $name ) {
			$role = get_role( $name );
			if ( $role instanceof \WP_Role && $role->has_cap( self::MANAGE ) ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}

	/**
	 * The publish intent for a set of capability checks.
	 *
	 * Pure function of a capability map, so it is trivially testable and has
	 * no dependency on a live WordPress user. `intent_for()` is the
	 * WP-facing wrapper.
	 *
	 * @param array<string,bool> $caps Map of capability => granted.
	 */
	public static function intent_from_caps( array $caps ): string {
		if ( ! empty( $caps[ self::MANAGE ] ) ) {
			return self::INTENT_CONFIGURE;
		}
		if ( ! empty( $caps['edit_others_posts'] ) ) {
			return self::INTENT_PUBLISH;
		}
		if ( ! empty( $caps['publish_posts'] ) ) {
			return self::INTENT_DRAFT;
		}

		return self::INTENT_EMBED;
	}

	/**
	 * The publish intent for a WordPress user (defaults to the current user).
	 *
	 * @param \WP_User|int|null $user User or user id; null = current user.
	 */
	public static function intent_for( $user = null ): string {
		$probe = static function ( string $cap ) use ( $user ): bool {
			return null === $user ? current_user_can( $cap ) : user_can( $user, $cap );
		};

		return self::intent_from_caps(
			array(
				self::MANAGE        => $probe( self::MANAGE ),
				'edit_others_posts' => $probe( 'edit_others_posts' ),
				'publish_posts'     => $probe( 'publish_posts' ),
			)
		);
	}

	/**
	 * Whether a user may create/edit drafts through the plugin (draft tier and
	 * above). This is the minimum bar to reach the publisher dashboard.
	 *
	 * @param \WP_User|int|null $user User or id; null = current user.
	 */
	public static function can_draft( $user = null ): bool {
		return in_array(
			self::intent_for( $user ),
			array( self::INTENT_DRAFT, self::INTENT_PUBLISH, self::INTENT_CONFIGURE ),
			true
		);
	}

	/**
	 * Whether a user may publish/retract/delete through the plugin (publish
	 * tier and above).
	 *
	 * @param \WP_User|int|null $user User or id; null = current user.
	 */
	public static function can_publish( $user = null ): bool {
		return in_array(
			self::intent_for( $user ),
			array( self::INTENT_PUBLISH, self::INTENT_CONFIGURE ),
			true
		);
	}

	/**
	 * A short, translatable label for an intent tier (for the settings UI).
	 *
	 * @param string $intent One of the INTENT_* constants.
	 */
	public static function intent_label( string $intent ): string {
		switch ( $intent ) {
			case self::INTENT_CONFIGURE:
				return __( 'Configure & publish (full)', 'terraviz' );
			case self::INTENT_PUBLISH:
				return __( 'Create, edit & publish', 'terraviz' );
			case self::INTENT_DRAFT:
				return __( 'Draft; request publish', 'terraviz' );
			default:
				return __( 'Embed blocks only', 'terraviz' );
		}
	}
}
