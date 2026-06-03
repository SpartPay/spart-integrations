<?php
/**
 * Unit tests for Gateway\Blocks\SpartBlocksSupport.
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway\Blocks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Gateway\Blocks\PaymentMethodDataBuilder;
use Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;

/**
 * @covers \Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport
 */
final class SpartBlocksSupportTest extends TestCase {

	private const ASSETS_URL = 'https://example.com/wp-content/plugins/spart-woocommerce/assets/';
	private const VERSION    = '0.4.0';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function build( ?PaymentMethodDataBuilder $builder = null ): SpartBlocksSupport {
		return new SpartBlocksSupport(
			$builder ?? new PaymentMethodDataBuilder(),
			self::ASSETS_URL,
			self::VERSION
		);
	}

	public function test_name_is_spart_gateway_id(): void {
		$this->assertSame( WC_Gateway_Spart::GATEWAY_ID, $this->build()->get_name() );
	}

	public function test_initialize_loads_settings_option_and_is_active_true_when_enabled(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_spart_settings', array() )
			->andReturn( array( 'enabled' => 'yes' ) );

		$support = $this->build();
		$support->initialize();

		$this->assertTrue( $support->is_active() );
	}

	/**
	 * @dataProvider providerNonEnabledSettings
	 *
	 * @param array<string, mixed> $settings Settings shape returned by get_option.
	 */
	public function test_is_active_returns_false_when_enabled_no_or_missing( array $settings ): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_spart_settings', array() )
			->andReturn( $settings );

		$support = $this->build();
		$support->initialize();

		$this->assertFalse( $support->is_active() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function providerNonEnabledSettings(): array {
		return array(
			'missing key'   => array( array() ),
			'enabled is no' => array( array( 'enabled' => 'no' ) ),
			'enabled blank' => array( array( 'enabled' => '' ) ),
		);
	}

	public function test_script_handles_registers_with_correct_deps_and_returns_handle(): void {
		Functions\expect( 'wp_register_script' )
			->once()
			->with(
				'spart-blocks-checkout',
				self::ASSETS_URL . 'js/blocks-checkout.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				self::VERSION,
				true
			);
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'spart-blocks-checkout', 'spart-woocommerce' );

		$handles = $this->build()->get_payment_method_script_handles();

		$this->assertSame( array( 'spart-blocks-checkout' ), $handles );
	}

	public function test_payment_method_data_delegates_to_builder(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_spart_settings', array() )
			->andReturn(
				array(
					'title'       => 'T',
					'description' => 'D',
					'enabled'     => 'yes',
				)
			);

		$builder = Mockery::mock( PaymentMethodDataBuilder::class );
		$builder->shouldReceive( 'build' )
			->once()
			->with(
				Mockery::on(
					static function ( $arg ): bool {
						return is_array( $arg )
							&& ( $arg['title'] ?? null ) === 'T'
							&& ( $arg['description'] ?? null ) === 'D'
							&& ( $arg['enabled'] ?? null ) === 'yes';
					}
				),
				self::ASSETS_URL
			)
			->andReturn( array( 'sentinel' => 1 ) );

		$support = $this->build( $builder );
		$support->initialize();

		$this->assertSame( array( 'sentinel' => 1 ), $support->get_payment_method_data() );
	}

	public function test_initialize_merges_schema_defaults_under_saved_partial_option(): void {
		// A partial option (e.g. saved via WP-CLI with only `enabled => yes`)
		// must still surface the schema's default title/description to the
		// Blocks payload, mirroring what WC_Gateway_Spart's get_option()
		// fallback gives the classic checkout. Otherwise Blocks renders
		// with empty copy while classic renders the schema defaults.
		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_spart_settings', array() )
			->andReturn( array( 'enabled' => 'yes' ) );

		$builder = Mockery::mock( PaymentMethodDataBuilder::class );
		$builder->shouldReceive( 'build' )
			->once()
			->with(
				Mockery::on(
					static function ( $arg ): bool {
						return is_array( $arg )
							&& ( $arg['enabled'] ?? null ) === 'yes'
							&& ( $arg['title'] ?? null ) === 'Pay with Spart'
							&& ( $arg['description'] ?? null ) === 'Split the payment with your friends!';
					}
				),
				self::ASSETS_URL
			)
			->andReturn( array() );

		$support = $this->build( $builder );
		$support->initialize();
		$support->get_payment_method_data();
		$this->addToAssertionCount( 1 );
	}

	public function test_payment_method_data_passes_empty_settings_when_initialize_not_called(): void {
		// initialize() may be called by WC Blocks BEFORE registry asks for the
		// payload, but defensive: with empty settings the builder receives [].
		$builder = Mockery::mock( PaymentMethodDataBuilder::class );
		$builder->shouldReceive( 'build' )
			->once()
			->with( array(), self::ASSETS_URL )
			->andReturn( array( 'fallback' => true ) );

		$this->assertSame(
			array( 'fallback' => true ),
			$this->build( $builder )->get_payment_method_data()
		);
	}
}
