<?php

namespace Spart\WooCommerce\Tests\Unit\I18n;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Spart\WooCommerce\I18n\GettextFilter;
use Spart\WooCommerce\I18n\Strings;

final class GettextFilterTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bundled_locale_translation_is_not_logged_but_changed_overrides_are(): void {
		$locale = 'it_IT';
		Functions\when( 'determine_locale' )->alias(
			static function () use ( &$locale ) {
				return $locale;
			}
		);
		$reader  = \Mockery::mock( 'alias:WP_Translation_File' );
		$catalog = \Mockery::mock();
		$reader->shouldReceive( 'create' )->once()
			->with( dirname( __DIR__, 3 ) . '/languages/spart-woocommerce-it_IT.mo' )->andReturn( $catalog );
		$reader->shouldReceive( 'create' )->once()
			->with( dirname( __DIR__, 3 ) . '/languages/spart-woocommerce-en_US.mo' )->andReturn( false );
		$catalog->shouldReceive( 'translate' )->with( 'SPART_CHECKOUT_TITLE' )->andReturn( 'Condividi la tua spesa senza anticipare' );
		$catalog->shouldReceive( 'translate' )->with( 'SPART_DIALOG_HELP' )->andReturn( 'Informazioni su SPART!' );

		for ( $index = 0; $index < 2; ++$index ) {
			$this->assertSame( 'Condividi la tua spesa senza anticipare', GettextFilter::filter( 'Condividi la tua spesa senza anticipare', 'SPART_CHECKOUT_TITLE', Strings::TEXT_DOMAIN ) );
		}
		$this->assertSame( 'Informazioni su SPART!', GettextFilter::filter( 'Informazioni su SPART!', 'SPART_DIALOG_HELP', Strings::TEXT_DOMAIN ) );
		$this->assertSame( array(), $this->logger_spy->calls );

		for ( $index = 0; $index < 2; ++$index ) {
			$this->assertSame( 'Titolo personalizzato', GettextFilter::filter( 'Titolo personalizzato', 'SPART_CHECKOUT_TITLE', Strings::TEXT_DOMAIN ) );
		}
		$this->assertCount( 1, $this->logger_spy->calls );
		$this->assertSame( 'spart.i18n.unexpected_translation', $this->logger_spy->calls[0]['message'] );

		$locale = 'en_US';
		$this->assertSame( 'Informazioni su SPART!', GettextFilter::filter( 'Informazioni su SPART!', 'SPART_DIALOG_HELP', Strings::TEXT_DOMAIN ) );
		$this->assertCount( 2, $this->logger_spy->calls, 'A different locale must not inherit the Italian diagnostic exemption.' );
	}

	/** @var object{calls: list<array{level: string, message: string, context: array<string, mixed>}>} */
	private object $logger_spy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->logger_spy = $this->make_logger_spy();
		\Spart\WooCommerce\Plugin::set_logger_for_tests( $this->logger_spy );
		\Spart\WooCommerce\I18n\GettextFilter::reset_warned_for_tests();
	}

	protected function tearDown(): void {
		\Spart\WooCommerce\Plugin::set_logger_for_tests( null );
		\Spart\WooCommerce\I18n\GettextFilter::reset_warned_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_gettext_filter(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'gettext', array( GettextFilter::class, 'filter' ), PHP_INT_MAX - 100, 3 );

		GettextFilter::register();
		$this->addToAssertionCount( 1 );
	}

	public function test_filter_returns_translation_unchanged_for_other_text_domains(): void {
		$result = GettextFilter::filter( 'xyz', 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1', 'woocommerce' );
		$this->assertSame( 'xyz', $result );
	}

	public function test_filter_returns_real_translation_when_present(): void {
		// When $translation differs from $text, a real translation took effect — pass through.
		// Under the diagnostic-enabled filter this ALSO emits a one-shot warning, since
		// the translation is neither the symbolic code nor the canonical English.
		$result = GettextFilter::filter( 'Pagamento in 3 rate', 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1', Strings::TEXT_DOMAIN );
		$this->assertSame( 'Pagamento in 3 rate', $result );
	}

	public function test_filter_substitutes_english_copy_when_no_translation_loaded(): void {
		// When $translation === $text (no .mo loaded), substitute from CODES.
		$result = GettextFilter::filter(
			'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1',
			'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1',
			Strings::TEXT_DOMAIN
		);
		$this->assertSame( Strings::CODES['SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1'], $result );
	}

	public function test_filter_returns_translation_unchanged_for_unknown_code(): void {
		// Unknown code with no translation → return $translation as-is (preserve WP's default behaviour).
		$result = GettextFilter::filter( 'UNKNOWN_CODE', 'UNKNOWN_CODE', Strings::TEXT_DOMAIN );
		$this->assertSame( 'UNKNOWN_CODE', $result );
	}

	public function test_diagnostic_warns_when_third_party_translates_known_code(): void {
		$third_party = 'A third-party translation we did not ship';
		$out         = GettextFilter::filter(
			$third_party,
			\Spart\WooCommerce\Constants::MSG_CODE_CART_LINE_1,
			Strings::TEXT_DOMAIN
		);

		// Option B: real (third-party) translation passes through; diagnostic still fires.
		$this->assertSame( $third_party, $out );

		$warnings = array_filter( $this->logger_spy->calls, static fn ( $c ): bool => 'warning' === $c['level'] );
		$this->assertCount( 1, $warnings );

		$warning = array_values( $warnings )[0];
		$this->assertSame( 'spart.i18n.unexpected_translation', $warning['message'] );
		$this->assertSame( \Spart\WooCommerce\Constants::MSG_CODE_CART_LINE_1, $warning['context']['code'] );
	}

	public function test_diagnostic_does_not_warn_when_no_translation_is_present(): void {
		// Normal substitution path: gettext returns its default (untranslated) string equal to $text,
		// so $translation === $text — that's the "no real translation present" case.
		GettextFilter::filter(
			\Spart\WooCommerce\Constants::MSG_CODE_CART_LINE_1,
			\Spart\WooCommerce\Constants::MSG_CODE_CART_LINE_1,
			Strings::TEXT_DOMAIN
		);

		$warnings = array_filter( $this->logger_spy->calls, static fn ( $c ): bool => 'warning' === $c['level'] );
		$this->assertCount( 0, $warnings );
	}

	public function test_diagnostic_warns_only_once_per_code_per_request(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			GettextFilter::filter(
				'A third-party translation',
				\Spart\WooCommerce\Constants::MSG_CODE_CART_LINE_1,
				Strings::TEXT_DOMAIN
			);
		}

		$warnings = array_filter( $this->logger_spy->calls, static fn ( $c ): bool => 'warning' === $c['level'] );
		$this->assertCount( 1, $warnings, 'Diagnostic must rate-limit to one warning per code per request.' );
	}

	/**
	 * Build a logger spy that records every call for later assertion.
	 *
	 * @return object{calls: list<array{level: string, message: string, context: array<string, mixed>}>}
	 */
	private function make_logger_spy(): object {
		return new class() implements \Spart\WooCommerce\Logging\SpartLoggerInterface {
			/** @var list<array{level: string, message: string, context: array<string, mixed>}> */
			public array $calls = array();

			public function info( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'info',
					'message' => $message,
					'context' => $context,
				);
			}

			public function warning( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'warning',
					'message' => $message,
					'context' => $context,
				);
			}

			public function error( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'error',
					'message' => $message,
					'context' => $context,
				);
			}

			public function debug( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'debug',
					'message' => $message,
					'context' => $context,
				);
			}
		};
	}
}
