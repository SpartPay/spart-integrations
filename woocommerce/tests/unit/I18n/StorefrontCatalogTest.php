<?php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\I18n\GettextFilter;
use Spart\WooCommerce\I18n\Strings;
use Spart\WooCommerce\Logging\NullSpartLogger;
use Spart\WooCommerce\Plugin;

final class StorefrontCatalogTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Plugin::set_logger_for_tests( new NullSpartLogger() );
		GettextFilter::reset_warned_for_tests();
	}

	protected function tearDown(): void {
		Plugin::set_logger_for_tests( null );
		GettextFilter::reset_warned_for_tests();
		parent::tearDown();
	}

	public function test_italian_catalog_supplies_the_first_render_without_replacing_external_translations(): void {
		$file = dirname( __DIR__, 3 ) . '/languages/spart-woocommerce-it_IT.mo';
		$this->assertFileExists( $file, 'Bundled Italian catalog must be available to WordPress on init.' );
		$data   = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalog fixture.
		$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', $data );
		$this->assertSame( 0x950412de, $header['magic'] );
		$messages = array();
		for ( $index = 0; $index < $header['count']; ++$index ) {
			$source = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + 8 * $index, 8 ) );
			$target = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + 8 * $index, 8 ) );
			$messages[ substr( $data, $source['offset'], $source['length'] ) ] = substr( $data, $target['offset'], $target['length'] );
		}
		$this->assertSame( 'Condividi e dividi il pagamento.', $messages['SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1'] );
		$this->assertSame( 'Nessun anticipo.', $messages['SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2'] );
		$this->assertSame( 'Condividi la tua spesa.', $messages['SPART_MSG_CART_BEFORE_TOTALS_LINE_1'] );
		$this->assertSame( 'Condividi la tua spesa senza anticipare', $messages['SPART_CHECKOUT_TITLE'] );
		$this->assertSame(
			"Appena tutti avranno pagato, l'ordine sarà sbloccato. Se non pagano tutti, non ti verrà addebitato nulla sulla tua carta.",
			sprintf( $messages['SPART_DIALOG_UNLOCK_BODY'], $messages['SPART_DIALOG_NO_CHARGE'] )
		);
		foreach ( Strings::CODES as $code => $english ) {
			if ( str_starts_with( $code, 'SPART_DIALOG_' ) ) {
				$this->assertNotEmpty( $messages[ $code ] ?? '', $code );
			}
			$this->assertSame( $english, GettextFilter::filter( $code, $code, Strings::TEXT_DOMAIN ) );
		}
		$this->assertSame( 'Traduzione personalizzata', GettextFilter::filter( 'Traduzione personalizzata', 'SPART_CHECKOUT_TITLE', Strings::TEXT_DOMAIN ) );
	}
}
