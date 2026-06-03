( function ( blocks, element ) {
	var el = element.createElement;

	var data = ( window.spartMessaging && typeof window.spartMessaging === 'object' )
		? window.spartMessaging
		: { codes: {}, previews: {} };
	var previews = data.previews || {};
	var codes    = data.codes || {};

	var productEdit = function () {
		return el(
			'div',
			{ className: 'spart-messaging spart-messaging--product' },
			el( 'p', { className: 'spart-messaging__line' }, previews.productLine1 || codes.productLine1 || 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1' ),
			el( 'p', { className: 'spart-messaging__line' }, previews.productLine2 || codes.productLine2 || 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2' )
		);
	};

	var cartEdit = function () {
		return el(
			'div',
			{ className: 'spart-messaging spart-messaging--cart' },
			el( 'p', { className: 'spart-messaging__line' }, previews.cartLine1 || codes.cartLine1 || 'SPART_MSG_CART_BEFORE_TOTALS_LINE_1' ),
			el( 'p', { className: 'spart-messaging__line' }, previews.cartLine2 || codes.cartLine2 || 'SPART_MSG_CART_BEFORE_TOTALS_LINE_2' )
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
