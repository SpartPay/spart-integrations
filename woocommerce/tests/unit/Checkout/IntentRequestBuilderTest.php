<?php
/**
 * Unit tests for Checkout\IntentRequestBuilder.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Dtos\CreateIntentRequest;
use Spart\WooCommerce\Checkout\FreeOrderException;
use Spart\WooCommerce\Checkout\IntentRequestBuilder;
use Spart\WooCommerce\Checkout\SessionIdComposer;

/**
 * @covers \Spart\WooCommerce\Checkout\IntentRequestBuilder
 * @covers \Spart\WooCommerce\Checkout\FreeOrderException
 */
final class IntentRequestBuilderTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Monkey\Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example/checkout/' );
		Monkey\Functions\when( 'wc_get_endpoint_url' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $permalink kept to match WC signature.
			static fn ( $endpoint, $value = '', $permalink = '' ) => 'https://shop.example/checkout/order-received/' . (string) $value . '/'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_builds_request_with_line_items(): void {
		$order = $this->make_order(
			array(
				'id'       => 42,
				'currency' => 'usd',
				'total'    => '129.99',
				'email'    => 'jane@example.com',
				'first'    => 'Jane',
				'last'     => 'Doe',
				'items'    => array(
					array(
						'name'  => 'T-shirt',
						'qty'   => 2,
						'image' => 'https://shop.example/wp-content/uploads/tshirt.jpg',
					),
					array(
						'name'  => 'Mug',
						'qty'   => 1,
						'image' => '/wp-content/uploads/mug.jpg',
					),
				),
			)
		);

		$req = ( new IntentRequestBuilder( 15 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		$this->assertInstanceOf( CreateIntentRequest::class, $req );
		$this->assertSame( 'USD', $req->total->currency );
		$this->assertSame( '129.99', $req->total->value );
		$this->assertCount( 2, $req->lineItems );
		$this->assertSame( 'T-shirt', $req->lineItems[0]->name );
		$this->assertSame( 2, $req->lineItems[0]->quantity );
		$this->assertSame( 'https://shop.example/wp-content/uploads/tshirt.jpg', $req->lineItems[0]->imageUri );
		$this->assertSame( 'https://shop.example/wp-content/uploads/mug.jpg', $req->lineItems[1]->imageUri );
		$this->assertSame( 'jane@example.com', $req->sparter->email );
		$this->assertSame( 'Jane', $req->sparter->firstName );
		$this->assertSame( 'spart-wc-a1b2c3d4-42', $req->sessionId );
		$this->assertSame( 'PT15M', $req->options->maxDuration->format( 'PT%iM' ) );
		$this->assertSame( 'https://shop.example/checkout/order-received/42/', $req->options->returnUri );
		$this->assertSame( 'https://shop.example/checkout/', $req->options->cancelUri );
	}

	public function test_synthesises_single_line_item_for_fees_only_order(): void {
		$order = $this->make_order(
			array(
				'id'       => 7,
				'currency' => 'EUR',
				'total'    => '12.50',
				'email'    => 'jane@example.com',
				'items'    => array(),
			)
		);

		$req = ( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		$this->assertCount( 1, $req->lineItems );
		$this->assertSame( 'Order', $req->lineItems[0]->name );
		$this->assertSame( 1, $req->lineItems[0]->quantity );
	}

	public function test_drops_unparseable_image_uri(): void {
		$order = $this->make_order(
			array(
				'id'       => 7,
				'currency' => 'EUR',
				'total'    => '12.50',
				'email'    => 'jane@example.com',
				'items'    => array(
					array(
						'name'  => 'Item',
						'qty'   => 1,
						'image' => 'not://a-url',
					),
				),
			)
		);

		$req = ( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );
		$this->assertNull( $req->lineItems[0]->imageUri );
	}

	public function test_throws_free_order_for_zero_total(): void {
		$order = $this->make_order(
			array(
				'id'       => 1,
				'currency' => 'USD',
				'total'    => '0.00',
				'email'    => 'jane@example.com',
				'items'    => array(
					array(
						'name' => 'Free thing',
						'qty'  => 1,
					),
				),
			)
		);
		$this->expectException( FreeOrderException::class );
		( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );
	}

	public function test_throws_free_order_for_negative_total(): void {
		$order = $this->make_order(
			array(
				'id'       => 1,
				'currency' => 'USD',
				'total'    => '-5.00',
				'email'    => 'jane@example.com',
				'items'    => array(
					array(
						'name' => 'Negative',
						'qty'  => 1,
					),
				),
			)
		);
		$this->expectException( FreeOrderException::class );
		( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );
	}

	public function test_normalises_currency_to_uppercase(): void {
		$order = $this->make_order(
			array(
				'id'       => 1,
				'currency' => 'gbp',
				'total'    => '9.99',
				'email'    => 'a@b.com',
				'items'    => array(
					array(
						'name' => 'Item',
						'qty'  => 1,
					),
				),
			)
		);
		$req   = ( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );
		$this->assertSame( 'GBP', $req->total->currency );
	}

	/**
	 * Regression: in admin/REST/integration contexts the global $post is null,
	 * so wc_get_endpoint_url(..., '') falls back to that null permalink and
	 * returns a *relative* URL. The builder must pass wc_get_checkout_url()
	 * as the explicit permalink fallback so OrderOptions never sees a relative
	 * URI and InvalidArgumentException-trips the checkout.
	 */
	public function test_passes_checkout_url_as_endpoint_permalink_fallback(): void {
		$captured = array();
		Monkey\Functions\when( 'wc_get_endpoint_url' )->alias(
			static function ( $endpoint, $value = '', $permalink = '' ) use ( &$captured ) {
				$captured[] = array( $endpoint, $value, $permalink );
				return rtrim( (string) $permalink, '/' ) . '/order-received/' . (string) $value . '/';
			}
		);
		$order = $this->make_order(
			array(
				'id'       => 99,
				'currency' => 'USD',
				'total'    => '10.00',
				'email'    => 'a@b.com',
				'items'    => array(
					array(
						'name' => 'Item',
						'qty'  => 1,
					),
				),
			)
		);

		$req = ( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		$this->assertNotEmpty( $captured, 'wc_get_endpoint_url should be invoked.' );
		$this->assertSame( 'order-received', $captured[0][0] );
		$this->assertSame( '99', $captured[0][1] );
		$this->assertSame(
			'https://shop.example/checkout/',
			$captured[0][2],
			'Builder must pass wc_get_checkout_url() as the permalink fallback so the endpoint resolves to an absolute URL.'
		);
		$this->assertStringStartsWith( 'https://', (string) $req->options->returnUri );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function make_order( array $data ): \WC_Order {
		$order = new \WC_Order();
		$order->__test_init( $data );
		return $order;
	}

	public function test_builds_intent_with_caller_specified_duration_when_provided(): void {
		$order = $this->make_order(
			array(
				'id'       => 42,
				'currency' => 'USD',
				'total'    => '10.00',
				'email'    => 'jane@example.com',
				'items'    => array(),
			)
		);

		$req = ( new IntentRequestBuilder( 30 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		$this->assertEquals( new \DateInterval( 'PT30M' ), $req->options->maxDuration );
	}

	public function test_builds_intent_with_seven_day_default_when_setting_is_seven_days(): void {
		$order = $this->make_order(
			array(
				'id'       => 42,
				'currency' => 'USD',
				'total'    => '10.00',
				'email'    => 'jane@example.com',
				'items'    => array(),
			)
		);

		$req = ( new IntentRequestBuilder( 10080 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		// DateInterval does NOT auto-normalise PT10080M → P7D, both formats
		// round-trip to the same wall time though.
		$this->assertEquals( new \DateInterval( 'PT10080M' ), $req->options->maxDuration );
	}

	public function test_clamps_duration_above_seven_days_to_seven_days(): void {
		$order = $this->make_order(
			array(
				'id'       => 42,
				'currency' => 'USD',
				'total'    => '10.00',
				'email'    => 'jane@example.com',
				'items'    => array(),
			)
		);

		$req = ( new IntentRequestBuilder( 20000 ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );

		// Defensive ceiling: anything above 7 days (10080 min) is clamped down,
		// mirroring the gateway save-time enforcement, in case the option row
		// was written by something other than the settings UI.
		$this->assertEquals( new \DateInterval( 'PT10080M' ), $req->options->maxDuration );
	}

	public function test_enforces_five_minute_floor_defensively_when_setting_below_min(): void {
		// Schema::sanitize already clamps below-5 to default at save time,
		// but the builder enforces the same floor defensively in case the
		// option row was written by something other than the settings UI
		// (WP-CLI, migration, raw SQL).
		$order = $this->make_order(
			array(
				'id'       => 42,
				'currency' => 'USD',
				'total'    => '10.00',
				'email'    => 'jane@example.com',
				'items'    => array(),
			)
		);

		foreach ( array( 0, 1, 4, -100 ) as $bad_minutes ) {
			$req = ( new IntentRequestBuilder( $bad_minutes ) )->build( $order, new SessionIdComposer( 'a1b2c3d4' ) );
			$this->assertEquals(
				new \DateInterval( 'PT5M' ),
				$req->options->maxDuration,
				sprintf( 'Builder must clamp %d minutes up to 5.', $bad_minutes )
			);
		}
	}
}
