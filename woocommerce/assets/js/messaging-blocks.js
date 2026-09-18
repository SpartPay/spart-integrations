( function ( blocks, element ) {
	var el = element.createElement;

	var data = ( window.spartMessaging && typeof window.spartMessaging === 'object' )
		? window.spartMessaging
		: { codes: {}, previews: {} };
	var previews = data.previews || {};
	var codes    = data.codes || {};

	var brand = function () {
		return el( 'span', { className: 'spart-messaging__brand' },
			el( 'span', { className: 'spart-messaging__badge', 'aria-hidden': true },
				el( 'img', { src: data.symbolUrl, alt: '', width: 20, height: 20 } )
			),
			el( 'img', { className: 'spart-wordmark', src: data.logoUrl, alt: 'SPART!', width: 1044, height: 205 } )
		);
	};

	var productEdit = function () {
		return el(
			'div',
			{ className: 'spart-messaging spart-messaging--product' },
			brand(),
			el( 'div', { className: 'spart-messaging__copy' },
				el( 'p', { className: 'spart-messaging__line' }, el( 'strong', null, previews.productLine1 || codes.productLine1 || 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1' ) ),
				el( 'p', { className: 'spart-messaging__line' }, previews.productLine2 || codes.productLine2 || 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2' )
			)
		);
	};

	var cartEdit = function () {
		return el(
			'div',
			{ className: 'spart-messaging spart-messaging--cart' },
			brand(),
			el( 'div', { className: 'spart-messaging__copy' },
				el( 'p', { className: 'spart-messaging__line' }, el( 'strong', null, previews.cartLine1 || codes.cartLine1 || 'SPART_MSG_CART_BEFORE_TOTALS_LINE_1' ) )
			)
		);
	};

	blocks.registerBlockType( 'spart/product-messaging', {
		edit: productEdit,
		save: function () {
			return null;
		},
	} );

	blocks.registerBlockType( 'spart/cart-messaging', {
		edit: cartEdit,
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element );
