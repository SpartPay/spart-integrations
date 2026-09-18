const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const React = require( 'react' );
const { renderToStaticMarkup } = require( 'react-dom/server' );

test( 'Blocks label is bold before the wordmark, with no custom selector or explainer', ( t ) => {
	const dom = new JSDOM( '', { runScripts: 'outside-only' } );
	t.after( () => dom.window.close() );
	let registration;
	dom.window.wp = { element: React, htmlEntities: { decodeEntities: ( s ) => s }, i18n: { __: ( s ) => s } };
	dom.window.wc = {
		wcBlocksRegistry: { registerPaymentMethod( value ) { registration = value; } },
		wcSettings: { getSetting: () => ( {
			title: 'Condividi la tua spesa senza anticipare', description: 'Legacy text must not appear',
			logoUrl: 'https://shop.example/spart-logo.svg',
		} ) },
	};
	dom.window.eval( fs.readFileSync( path.join( __dirname, '../../assets/js/blocks-checkout.js' ), 'utf8' ) );
	const label = new JSDOM( renderToStaticMarkup( registration.label ) ).window.document;
	assert.equal( label.querySelector( 'strong' )?.textContent, 'Condividi la tua spesa senza anticipare' );
	assert.equal( label.querySelector( '.spart-blocks-label' ).lastElementChild.tagName, 'IMG' );
	assert.equal( label.querySelector( 'img' ).alt, 'SPART!' );
	assert.equal( label.querySelector( 'button, input, a, dialog' ), null );
	assert.equal( renderToStaticMarkup( registration.edit ), '' );
	assert.equal( registration.ariaLabel, 'Condividi la tua spesa senza anticipare' );
} );
