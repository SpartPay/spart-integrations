const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const React = require( 'react' );
const { renderToStaticMarkup } = require( 'react-dom/server' );

test( 'editor previews match product and cart hierarchy without a nonfunctional help control', ( t ) => {
	const dom = new JSDOM( '', { runScripts: 'outside-only' } );
	t.after( () => dom.window.close() );
	const registrations = {};
	dom.window.wp = { element: React, blocks: { registerBlockType( name, config ) { registrations[ name ] = config; } } };
	dom.window.spartMessaging = {
		previews: { productLine1: 'Share and split the payment.', productLine2: 'No upfront payment.', cartLine1: 'Share your purchase.' },
		logoUrl: 'https://shop.example/spart-logo.svg', symbolUrl: 'https://shop.example/spart-symbol.svg',
	};
	dom.window.eval( fs.readFileSync( path.join( __dirname, '../../assets/js/messaging-blocks.js' ), 'utf8' ) );
	const product = renderToStaticMarkup( React.createElement( registrations[ 'spart/product-messaging' ].edit ) );
	const cart = renderToStaticMarkup( React.createElement( registrations[ 'spart/cart-messaging' ].edit ) );
	assert.match( product, /<strong>Share and split the payment\.<\/strong>/ );
	assert.match( product, /No upfront payment/ );
	assert.match( product, /spart-symbol.svg/ );
	assert.match( cart, /<strong>Share your purchase\.<\/strong>/ );
	assert.match( cart, /spart-logo.svg/ );
	assert.equal( ( cart.match( /<p /g ) || [] ).length, 1 );
	assert.doesNotMatch( cart, /<button/ );
} );
