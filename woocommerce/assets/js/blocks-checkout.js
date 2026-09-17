( function ( wc, wp ) {
	if ( ! wc || ! wc.wcBlocksRegistry || ! wp ||
		! wp.htmlEntities || ! wp.element || ! wp.i18n ) {
		return;
	}
	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var decodeEntities        = wp.htmlEntities.decodeEntities;
	var h                     = wp.element.createElement;
	var __                    = wp.i18n.__;

	var settings = ( wc.wcSettings && typeof wc.wcSettings.getSetting === 'function' )
		? wc.wcSettings.getSetting( 'spart_data', {} )
		: {};

	var labelText = decodeEntities( settings.title || __( 'Pay with Spart', 'spart-woocommerce' ) );
	var descText  = decodeEntities( settings.description || '' );
	var logoUrl   = settings.logoUrl || '';

	var Label = function () {
		return h(
			'span',
			{ className: 'spart-blocks-label' },
			logoUrl
				? h( 'img', {
				src:       logoUrl,
				alt:       '',
				className: 'spart-logo',
				width:     60,
				height:    24,
			} )
				: null,
			' ',
			labelText
		);
	};

	var Content = function () {
		return h( 'p', { className: 'spart-blocks-description' }, descText );
	};

	var CheckoutContent = function ( props ) {
		var overlay = window.spartCheckoutLoading;
		var status = props.checkoutStatus || {};
		var payment = props.paymentStatus || {};
		var onCheckoutFail = props.eventRegistration && props.eventRegistration.onCheckoutFail;

		wp.element.useEffect( function () {
			if ( ! overlay ) {
				return;
			}
			if ( props.activePaymentMethod !== 'spart' || status.isIdle || payment.hasError || payment.hasFailed ) {
				overlay.hide();
			} else if ( status.isProcessing ) {
				overlay.show();
			}
			// Keep feedback visible through AFTER_PROCESSING and redirect.
		}, [ overlay, props.activePaymentMethod, status.isIdle, status.isProcessing, payment.hasError, payment.hasFailed ] );

		wp.element.useEffect( function () {
			if ( ! overlay || ! onCheckoutFail ) {
				return;
			}
			return onCheckoutFail( function () {
				overlay.hide();
				return true;
			} );
		}, [ overlay, onCheckoutFail ] );

		wp.element.useEffect( function () {
			return function () {
				if ( overlay ) {
					overlay.hide();
				}
			};
		}, [ overlay ] );

		return h( Content );
	};

	registerPaymentMethod( {
		name:           'spart',
		label:          h( Label ),
		content:        h( CheckoutContent ),
		edit:           h( Content ),
		canMakePayment: function () { return true; },
		ariaLabel:      labelText,
		supports:       { features: settings.supports || [ 'products' ] },
	} );
}( window.wc, window.wp ) );
