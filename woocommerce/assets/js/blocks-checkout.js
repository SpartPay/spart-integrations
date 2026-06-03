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

	registerPaymentMethod( {
		name:           'spart',
		label:          h( Label ),
		content:        h( Content ),
		edit:           h( Content ),
		canMakePayment: function () { return true; },
		ariaLabel:      labelText,
		supports:       { features: settings.supports || [ 'products' ] },
	} );
}( window.wc, window.wp ) );
