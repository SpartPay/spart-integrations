<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\StateBadge;

final class StateBadgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		// sanitize_html_class() is defined as a faithful pass-through in
		// tests/unit/stubs.php (loaded before Patchwork), so it cannot be redefined
		// here via Functions\when(); the test inputs are already valid class names.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_markup_for_known_state_includes_class_and_label(): void {
		$html = StateBadge::markup( 'applied' );
		$this->assertStringContainsString( 'spart-state-badge', $html );
		$this->assertStringContainsString( 'spart-state-applied', $html );
		$this->assertStringContainsString( 'applied', $html );
	}

	public function test_markup_for_unknown_state_falls_back_to_received(): void {
		$html = StateBadge::markup( 'bogus' );
		$this->assertStringContainsString( 'spart-state-received', $html );
	}

	public function test_style_block_is_emitted_only_once(): void {
		StateBadge::reset_style_block_for_tests();
		$first  = StateBadge::style_block();
		$second = StateBadge::style_block();
		$this->assertStringContainsString( '<style', $first );
		$this->assertSame( '', $second );
	}
}
