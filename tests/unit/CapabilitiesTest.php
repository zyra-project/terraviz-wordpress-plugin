<?php
/**
 * Tests for the WP capability → publish-intent mapping.
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

use Terraviz\Support\Capabilities;

/**
 * @covers \Terraviz\Support\Capabilities
 */
class CapabilitiesTest extends WP_UnitTestCase {

	public function test_intent_from_caps_ordering(): void {
		$this->assertSame(
			Capabilities::INTENT_CONFIGURE,
			Capabilities::intent_from_caps(
				array(
					Capabilities::MANAGE => true,
					'edit_others_posts'  => true,
					'publish_posts'      => true,
				)
			)
		);
		$this->assertSame(
			Capabilities::INTENT_PUBLISH,
			Capabilities::intent_from_caps(
				array(
					'edit_others_posts' => true,
					'publish_posts'     => true,
				)
			)
		);
		$this->assertSame(
			Capabilities::INTENT_DRAFT,
			Capabilities::intent_from_caps( array( 'publish_posts' => true ) )
		);
		$this->assertSame(
			Capabilities::INTENT_EMBED,
			Capabilities::intent_from_caps( array() )
		);
	}

	public function test_manage_capability_outranks_lower_caps(): void {
		$this->assertSame(
			Capabilities::INTENT_CONFIGURE,
			Capabilities::intent_from_caps( array( Capabilities::MANAGE => true ) )
		);
	}

	public function test_intent_for_real_roles(): void {
		Capabilities::grant();

		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$this->assertSame( Capabilities::INTENT_CONFIGURE, Capabilities::intent_for( $admin ) );
		$this->assertSame( Capabilities::INTENT_PUBLISH, Capabilities::intent_for( $editor ) );
		$this->assertSame( Capabilities::INTENT_DRAFT, Capabilities::intent_for( $author ) );
		$this->assertSame( Capabilities::INTENT_EMBED, Capabilities::intent_for( $contributor ) );
	}

	public function test_grant_and_revoke_manage_capability(): void {
		Capabilities::grant();
		$admin = get_role( 'administrator' );
		$this->assertTrue( $admin->has_cap( Capabilities::MANAGE ) );

		Capabilities::revoke();
		$admin = get_role( 'administrator' );
		$this->assertFalse( $admin->has_cap( Capabilities::MANAGE ) );
	}

	public function test_every_intent_has_a_label(): void {
		foreach (
			array(
				Capabilities::INTENT_CONFIGURE,
				Capabilities::INTENT_PUBLISH,
				Capabilities::INTENT_DRAFT,
				Capabilities::INTENT_EMBED,
			) as $intent
		) {
			$this->assertNotSame( '', Capabilities::intent_label( $intent ) );
		}
	}
}
