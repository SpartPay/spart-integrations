( function ( $, window, document ) {
	'use strict';

	if ( ! $ || ! window.spartCheckoutLoading || ! window.wc_checkout_params ) {
		return;
	}
	var overlay = window.spartCheckoutLoading;
	var activeRequest;

	function reset() {
		activeRequest = null;
		overlay.hide();
	}

	function absoluteUrl( url ) {
		var link = document.createElement( 'a' );
		link.href = url;
		return link.href;
	}

	$( function () {
		// Gateway submit hooks run before all validation vetoes; observe the actual WC request instead.
		$( document ).on( 'ajaxSend.spartLoading', function ( event, xhr, settings ) {
			if ( absoluteUrl( settings.url ) !== absoluteUrl( window.wc_checkout_params.checkout_url ) ||
				settings.type.toUpperCase() !== 'POST' ||
				typeof settings.data !== 'string' ||
				new URLSearchParams( settings.data ).get( 'payment_method' ) !== 'spart' ||
				$( 'form.checkout input[name="payment_method"]:checked' ).val() !== 'spart' ) {
				return;
			}
			activeRequest = xhr;
			overlay.show();
			xhr.done( function ( result ) {
				if ( activeRequest === xhr &&
					( ! result || result.result !== 'success' || typeof result.redirect !== 'string' || ! result.redirect ) ) {
					reset();
				}
			} ).fail( function () {
				if ( activeRequest === xhr ) {
					reset();
				}
			} );
		} );
		$( document.body ).on( 'checkout_error.spartLoading', reset );
		$( document.body ).on( 'payment_method_selected.spartLoading', function () {
			if ( $( 'form.checkout input[name="payment_method"]:checked' ).val() !== 'spart' ) {
				reset();
			}
		} );
		window.addEventListener( 'pageshow', function ( event ) {
			if ( ! event.persisted ) {
				return;
			}
			if ( activeRequest ) {
				var form = $( 'form.checkout' ).removeClass( 'processing' );
				if ( typeof form.unblock === 'function' ) {
					form.unblock();
				}
			}
			reset();
		} );
	} );
}( window.jQuery, window, document ) );
