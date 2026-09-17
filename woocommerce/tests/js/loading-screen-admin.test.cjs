const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const jquery = require( 'jquery' );

async function setup( t ) {
	const dom = new JSDOM(
		'<input id="woocommerce_spart_loading_screen_backdrop_color" value="#192a23">' +
		'<input id="woocommerce_spart_loading_screen_backdrop_opacity" value="55">' +
		'<div><input id="woocommerce_spart_loading_screen_image_id" value="0" readonly></div>',
		{ runScripts: 'outside-only', url: 'https://shop.example/wp-admin/' }
	);
	const w = dom.window;
	t.after( () => w.close() );
	w.jQuery = jquery( w );
	w.matchMedia = () => Object.assign( new w.EventTarget(), { matches: false } );
	w.HTMLDialogElement.prototype.showModal = function () { this.open = true; this.focus(); };
	w.HTMLDialogElement.prototype.close = function () { this.open = false; };
	w.spartCheckoutLoadingConfig = {
		backdropColor: '#192a23', backdropOpacity: 55, imageUrl: '',
		title: 'Connecting to Spart...', description: 'Please wait while we prepare your checkout.',
	};
	w.spartLoadingScreenAdminConfig = {
		chooseImage: 'Choose <image>', clearImage: 'Clear image', preview: 'Preview',
		closeLabel: 'Close translated', invalidImage: 'Choose a supported raster image, not SVG.',
	};
	let selection;
	let selected;
	const frame = {
		on( name, callback ) { if ( name === 'select' ) selection = callback; },
		state: () => ( { get: () => ( { first: () => ( { toJSON: () => selected } ) } ) } ),
		open() {},
	};
	w.wp = {
		media( options ) {
			assert.equal( options.library.type, 'image' );
			assert.equal( options.multiple, false );
			return frame;
		},
	};
	for ( const file of [ 'checkout-loading.js', 'loading-screen-admin.js' ] ) {
		w.eval( fs.readFileSync( path.join( __dirname, '../../assets/js', file ), 'utf8' ) );
	}
	await new Promise( ( resolve ) => w.jQuery( resolve ) );
	return {
		w,
		button: ( text ) => [ ...w.document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === text ),
		select( image ) { selected = image; selection(); },
	};
}

test( 'admin media selection and clear change saved ID and the actual preview without rendering label markup', async ( t ) => {
	const h = await setup( t );
	assert.ok( h.button( 'Choose <image>' ) );
	assert.equal( h.w.document.querySelector( 'image' ), null );
	h.button( 'Choose <image>' ).click();
	h.select( { id: 12, mime: 'image/gif', url: 'https://shop.example/loading.gif' } );
	assert.equal( h.w.document.querySelector( '#woocommerce_spart_loading_screen_image_id' ).value, '12' );
	h.button( 'Preview' ).click();
	assert.equal( h.w.document.querySelector( 'dialog img' ).src, 'https://shop.example/loading.gif' );
	h.button( 'Close translated' ).click();
	h.button( 'Clear image' ).click();
	assert.equal( h.w.document.querySelector( '#woocommerce_spart_loading_screen_image_id' ).value, '0' );
	h.button( 'Preview' ).click();
	assert.equal( h.w.document.querySelector( 'dialog img' ), null );
	assert.ok( h.w.document.querySelector( '.spart-loading-spinner' ) );
} );

test( 'admin preview uses unsaved appearance, preserves zero opacity and exposes translated close control', async ( t ) => {
	const h = await setup( t );
	const color = h.w.document.querySelector( '#woocommerce_spart_loading_screen_backdrop_color' );
	const opacity = h.w.document.querySelector( '#woocommerce_spart_loading_screen_backdrop_opacity' );
	color.value = '#ABCDEF';
	opacity.value = '0';
	h.button( 'Preview' ).click();
	let dialog = h.w.document.querySelector( 'dialog' );
	assert.equal( dialog.style.getPropertyValue( '--spart-loading-backdrop' ), '#abcdef' );
	assert.equal( dialog.style.getPropertyValue( '--spart-loading-opacity' ), '0' );
	h.button( 'Close translated' ).click();
	assert.equal( h.w.document.activeElement, h.w.document.body );
	color.value = 'red; background:url(evil)';
	opacity.value = '101';
	h.button( 'Preview' ).click();
	dialog = h.w.document.querySelector( 'dialog' );
	assert.equal( dialog.style.getPropertyValue( '--spart-loading-backdrop' ), '#192a23' );
	assert.equal( dialog.style.getPropertyValue( '--spart-loading-opacity' ), '0.55' );
} );

test( 'admin rejects SVG and nonimage selections visibly without replacing the saved image', async ( t ) => {
	const h = await setup( t );
	h.button( 'Choose <image>' ).click();
	h.select( { id: 12, mime: 'image/png', url: 'https://shop.example/good.png' } );
	for ( const mime of [ 'image/svg+xml', 'application/pdf' ] ) {
		h.select( { id: 13, mime, url: 'https://shop.example/bad' } );
		assert.equal( h.w.document.querySelector( '#woocommerce_spart_loading_screen_image_id' ).value, '12' );
		assert.match( h.w.document.querySelector( '[role="alert"]' )?.textContent || '', /supported raster/ );
	}
	h.select( { id: 14, mime: 'image/gif', url: 'https://shop.example/good.gif' } );
	assert.equal( h.w.document.querySelector( '[role="alert"]' ).textContent, '' );
	h.button( 'Preview' ).click();
	assert.equal( h.w.document.querySelector( 'dialog img' ).src, 'https://shop.example/good.gif' );
} );
