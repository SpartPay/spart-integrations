<?php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\I18n\Strings;
use Spart\WooCommerce\Messaging\StorefrontDialog;

final class StorefrontDialogTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Spart\WooCommerce\Plugin::set_plugin_file_for_tests( '/plugin/spart-woocommerce.php' );
		Functions\when( 'esc_html__' )->alias( static fn( $code ) => htmlspecialchars( Strings::CODES[ $code ] ?? $code, ENT_QUOTES ) );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://shop.example/' . $path );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_shared_dialog_is_labeled_closed_and_contains_steps_benefits_without_fees(): void {
		$this->assertTrue( class_exists( StorefrontDialog::class ), 'Shared explainer renderer must exist.' );
		$html = StorefrontDialog::render();
		$this->assertStringContainsString( '<dialog id="spart-explainer"', $html );
		$this->assertStringContainsString( 'aria-labelledby="spart-explainer-title"', $html );
		$this->assertStringNotContainsString( ' open', $html );
		$this->assertStringContainsString( 'data-spart-dialog-close', $html );
		$this->assertStringContainsString( 'Buy together.<br>', $html );
		$this->assertStringContainsString( '<strong>nothing will be charged to your card</strong>', $html );
		$this->assertSame( 7, substr_count( $html, '<li>' ) );
		$this->assertSame( 8, substr_count( $html, '<svg ' ) );
		$this->assertStringNotContainsString( 'per participant', $html );
		$this->assertStringNotContainsString( 'fixed amount', $html );
	}

	public function test_checkout_never_enqueues_or_prints_the_explainer(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->never();
		StorefrontDialog::enqueue();
		ob_start();
		StorefrontDialog::render_footer();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_footer_renders_only_when_a_message_requested_assets(): void {
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'wp_script_is' )->justReturn( false );
		ob_start();
		StorefrontDialog::render_footer();
		$this->assertSame( '', ob_get_clean() );
		Functions\when( 'wp_script_is' )->justReturn( true );
		ob_start();
		StorefrontDialog::render_footer();
		$this->assertSame( 1, substr_count( ob_get_clean(), '<dialog ' ) );
	}
}
