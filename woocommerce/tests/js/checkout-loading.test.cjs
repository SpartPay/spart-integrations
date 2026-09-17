const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const jquery = require( 'jquery' );
const React = require( 'react' );
const { createRoot } = require( 'react-dom/client' );
const { act } = React;

const assets = path.join( __dirname, '../../assets/js' );

function load( window, file ) {
	const filename = path.join( assets, file );
	if ( fs.existsSync( filename ) ) {
		window.eval( fs.readFileSync( filename, 'utf8' ) );
	}
}

function page( t, config = {} ) {
	const dom = new JSDOM(
		'<main aria-busy="false"><form class="checkout"><input name="payment_method" value="spart" type="radio" checked><button id="place_order">Place order</button></form></main>',
		{ url: 'https://shop.example/checkout/', runScripts: 'outside-only' }
	);
	const { window } = dom;
	t.after( () => window.close() );
	const motion = new window.EventTarget();
	motion.matches = false;
	window.matchMedia = () => motion;
	// jsdom lacks the browser's modal dialog methods.
	window.HTMLDialogElement.prototype.showModal = function () {
		this.open = true;
		this.focus();
	};
	window.HTMLDialogElement.prototype.close = function () {
		this.open = false;
	};
	window.spartCheckoutLoadingConfig = {
		backdropColor: '#192a23',
		backdropOpacity: 55,
		imageUrl: '',
		title: 'Connecting to Spart...',
		description: 'Please wait while we prepare your checkout.',
		closeLabel: 'Close preview',
		...config,
	};
	load( window, 'checkout-loading.js' );
	return { window, document: window.document, motion, overlay: window.spartCheckoutLoading };
}

test( 'overlay opens once as a modal status without changing background attributes or form data', ( t ) => {
	const { document, overlay } = page( t );
	assert.ok( overlay, 'loading overlay must be available when its assets are loaded' );
	const button = document.querySelector( 'button' );
	button.focus();
	document.body.style.setProperty( 'overflow', 'scroll', 'important' );
	overlay.show();
	overlay.show();
	const dialog = document.querySelector( 'dialog' );
	assert.equal( dialog.open, true );
	assert.equal( document.querySelectorAll( 'dialog' ).length, 1 );
	assert.match( dialog.querySelector( '[role="status"]' ).textContent, /Connecting to Spart/ );
	assert.equal( dialog.getAttribute( 'aria-modal' ), 'true' );
	assert.equal( document.querySelector( 'main' ).getAttribute( 'aria-busy' ), 'false' );
	assert.equal( document.querySelector( 'input' ).disabled, false );
	assert.equal( document.body.style.overflow, 'hidden' );
	overlay.hide();
	assert.equal( overlay.isVisible(), false );
	assert.equal( document.activeElement, button );
	assert.equal( document.body.style.overflow, 'scroll' );
	assert.equal( document.body.style.getPropertyPriority( 'overflow' ), 'important' );
} );

test( 'failure restores focus to the WooCommerce error instead of leaving focus behind the modal', ( t ) => {
	const { document, overlay } = page( t );
	assert.ok( overlay );
	overlay.show();
	const error = document.createElement( 'div' );
	error.className = 'woocommerce-error';
	error.tabIndex = -1;
	document.querySelector( 'form' ).prepend( error );
	overlay.hide();
	assert.equal( document.activeElement, error );
} );

test( 'custom images never rotate and are replaced on reduced motion or failed image load', ( t ) => {
	const { document, motion, overlay, window } = page( t, { imageUrl: 'https://shop.example/logo.gif' } );
	assert.ok( overlay );
	overlay.show();
	assert.equal( document.querySelector( 'dialog img' ).src, 'https://shop.example/logo.gif' );
	assert.equal( document.querySelector( 'dialog img' ).classList.contains( 'spart-loading-spinner' ), false );
	motion.matches = true;
	motion.dispatchEvent( new window.Event( 'change' ) );
	assert.equal( document.querySelector( 'dialog img' ), null );
	assert.ok( document.querySelector( '.spart-loading-spinner' ) );
	overlay.hide();
	motion.matches = false;
	overlay.show();
	document.querySelector( 'dialog img' ).dispatchEvent( new window.Event( 'error' ) );
	assert.equal( document.querySelector( 'dialog img' ), null );
	assert.ok( document.querySelector( '.spart-loading-spinner' ) );
} );

test( 'reduced motion never fetches a custom animation on first display', ( t ) => {
	const { document, motion, overlay } = page( t, { imageUrl: 'https://shop.example/animation.webp' } );
	assert.ok( overlay );
	motion.matches = true;
	overlay.show();
	assert.equal( document.querySelector( 'dialog img' ), null );
} );

test( 'Escape cannot dismiss an in-flight checkout but preview closes and restores focus', ( t ) => {
	const { window, document, overlay } = page( t );
	assert.ok( overlay );
	overlay.show();
	const event = new window.Event( 'cancel', { cancelable: true } );
	document.querySelector( 'dialog' ).dispatchEvent( event );
	assert.equal( event.defaultPrevented, true );
	assert.equal( overlay.isVisible(), true );
	overlay.hide();
	overlay.show( { preview: true, backdropColor: '#ffffff', backdropOpacity: 0 } );
	assert.equal( document.querySelector( 'dialog' ).style.getPropertyValue( '--spart-loading-backdrop' ), '#ffffff' );
	assert.equal( document.querySelector( 'dialog' ).style.getPropertyValue( '--spart-loading-opacity' ), '0' );
	document.querySelector( 'dialog button' ).click();
	assert.equal( overlay.isVisible(), false );
	overlay.show( { preview: true } );
	document.querySelector( 'dialog' ).dispatchEvent( new window.Event( 'cancel', { cancelable: true } ) );
	assert.equal( overlay.isVisible(), false );
} );

test( 'pageshow clears restored modal and scroll lock without corrupting background accessibility', ( t ) => {
	const { window, document, overlay } = page( t );
	assert.ok( overlay );
	overlay.show();
	window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: true } ) );
	assert.equal( overlay.isVisible(), false );
	assert.equal( document.body.style.overflow, '' );
	assert.equal( document.querySelector( 'main' ).getAttribute( 'aria-busy' ), 'false' );
} );

test( 'a pending image error cannot replace the image in a newer preview', ( t ) => {
	const { window, document, overlay } = page( t, { imageUrl: 'https://shop.example/old.gif' } );
	overlay.show();
	const old = document.querySelector( 'dialog img' );
	overlay.hide();
	overlay.show( { preview: true, imageUrl: 'https://shop.example/new.png' } );
	old.dispatchEvent( new window.Event( 'error' ) );
	assert.equal( document.querySelector( 'dialog img' )?.src, 'https://shop.example/new.png' );
} );

test( 'Blocks error remains focusable until blur then restores its original tabindex state', ( t ) => {
	const { document, overlay } = page( t );
	const button = document.querySelector( 'button' );
	button.focus();
	overlay.show();
	button.disabled = true;
	const error = document.createElement( 'div' );
	error.className = 'wc-block-components-notice-banner is-error';
	error.setAttribute( 'role', 'alert' );
	document.querySelector( 'main' ).prepend( error );
	overlay.hide();
	assert.equal( document.activeElement, error );
	assert.equal( error.getAttribute( 'tabindex' ), '-1' );
	button.disabled = false;
	button.focus();
	assert.equal( error.hasAttribute( 'tabindex' ), false );
} );

test( 'focus recovery can finish when Blocks returns to idle after the modal was already removed', ( t ) => {
	const { document, overlay } = page( t );
	const button = document.querySelector( 'button' );
	button.focus();
	button.disabled = true;
	overlay.show();
	overlay.hide();
	button.disabled = false;
	overlay.hide();
	assert.equal( document.activeElement, button );
} );

test( 'repeated failure cleanup preserves caller-owned error tabindex and adds no duplicate blur listener', ( t ) => {
	const { document, overlay } = page( t );
	const error = document.createElement( 'div' );
	error.className = 'woocommerce-error';
	error.tabIndex = 0;
	document.querySelector( 'main' ).prepend( error );
	let blurListeners = 0;
	const addEventListener = error.addEventListener.bind( error );
	error.addEventListener = function ( event, listener, options ) {
		if ( event === 'blur' ) blurListeners++;
		return addEventListener( event, listener, options );
	};
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		overlay.show();
		overlay.hide();
		overlay.hide();
		assert.equal( error.getAttribute( 'tabindex' ), '0' );
	}
	assert.equal( blurListeners, 0 );
	error.removeAttribute( 'tabindex' );
	overlay.show();
	overlay.hide();
	overlay.hide();
	assert.equal( blurListeners, 1 );
	error.tabIndex = 2;
	document.querySelector( 'button' ).focus();
	assert.equal( error.getAttribute( 'tabindex' ), '2' );
} );

async function classic( t ) {
	const p = page( t );
	const $ = jquery( p.window );
	p.window.jQuery = $;
	p.window.wc_checkout_params = { checkout_url: '/?wc-ajax=checkout' };
	const requests = [];
	$.ajaxTransport( '+*', () => ( {
		send( headers, complete ) { requests.push( complete ); },
		abort() {},
	} ) );
	load( p.window, 'classic-checkout-loading.js' );
	await new Promise( ( resolve ) => $( resolve ) );
	const submit = ( data = 'payment_method=spart', url = '/?wc-ajax=checkout' ) => $.ajax( {
		url, type: 'POST', data, dataType: 'json',
	} );
	const respond = ( result, index = requests.length - 1, status = 200 ) => requests[ index ](
		status, status === 200 ? 'OK' : 'error', { text: JSON.stringify( result ) }, 'Content-Type: application/json'
	);
	return { ...p, $, submit, respond };
}

test( 'classic only shows for an actual Spart checkout request, never clicks, vetoed validation or other AJAX', async ( t ) => {
	const { document, $, overlay, submit, respond } = await classic( t );
	assert.ok( overlay );
	$( 'form.checkout' ).on( 'checkout_place_order_spart', () => false ).triggerHandler( 'checkout_place_order_spart' );
	assert.equal( overlay.isVisible(), false );
	submit( 'payment_method=cod' );
	assert.equal( overlay.isVisible(), false );
	respond( { result: 'success' } );
	submit( 'payment_method=spart', '/?wc-ajax=update_order_review' );
	assert.equal( overlay.isVisible(), false );
	respond( {} );
	submit();
	assert.equal( document.querySelector( 'dialog' ).open, true );
	respond( { result: 'success', redirect: 'https://spart.example/checkout' } );
	assert.equal( overlay.isVisible(), true );
} );

for ( const failure of [ 'server', 'network', 'abort', 'invalid-response', 'checkout-error' ] ) {
	test( `classic releases overlay on ${failure} and permits a successful retry`, async ( t ) => {
		const { $, window, overlay, submit, respond } = await classic( t );
		assert.ok( overlay );
		const xhr = submit();
		assert.equal( overlay.isVisible(), true );
		if ( failure === 'abort' ) xhr.abort();
		if ( failure === 'server' ) respond( { result: 'failure' } );
		if ( failure === 'network' ) respond( {}, undefined, 500 );
		if ( failure === 'invalid-response' ) respond( { result: 'success' } );
		if ( failure === 'checkout-error' ) {
			$( window.document.body ).trigger( 'checkout_error' );
			respond( { result: 'success', redirect: '/blocked-by-validation' } );
		}
		assert.equal( overlay.isVisible(), false );
		submit();
		assert.equal( overlay.isVisible(), true );
		respond( { result: 'success', redirect: 'https://spart.example/checkout' } );
		assert.equal( overlay.isVisible(), true );
	} );
}

test( 'classic gateway changes and stale aborted requests cannot hide a newer attempt', async ( t ) => {
	const { $, window, document, overlay, submit, respond } = await classic( t );
	assert.ok( overlay );
	const old = submit();
	$( 'input' ).val( 'cod' );
	$( document.body ).trigger( 'payment_method_selected' );
	assert.equal( overlay.isVisible(), false );
	$( 'input' ).val( 'spart' );
	submit();
	old.abort();
	assert.equal( overlay.isVisible(), true );
	respond( { result: 'success', redirect: '/spart' } );
	$( 'form' ).addClass( 'processing' );
	window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: true } ) );
	assert.equal( overlay.isVisible(), false );
	assert.equal( $( 'form' ).hasClass( 'processing' ), false );
} );

test( 'initial pageshow never interrupts an in-flight request while persisted pageshow still recovers it', async ( t ) => {
	const { window, $, overlay, submit, respond } = await classic( t );
	submit();
	window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: false } ) );
	assert.equal( overlay.isVisible(), true );
	respond( { result: 'success', redirect: '/spart' } );
	$( 'form' ).addClass( 'processing' );
	window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: true } ) );
	assert.equal( overlay.isVisible(), false );
	assert.equal( $( 'form' ).hasClass( 'processing' ), false );
} );

test( 'classic honors WooCommerce configured custom checkout endpoint and ignores unrelated calls', async ( t ) => {
	const { window, overlay, submit, respond } = await classic( t );
	window.wc_checkout_params.checkout_url = 'https://shop.example/custom/checkout?token=abc';
	submit( 'payment_method=spart', '/?wc-ajax=checkout' );
	assert.equal( overlay.isVisible(), false );
	respond( {} );
	submit( 'billing_email=a%40example.com&payment_method=spart', window.wc_checkout_params.checkout_url );
	assert.equal( overlay.isVisible(), true );
	respond( { result: 'failure' } );
	assert.equal( overlay.isVisible(), false );
} );

test( 'classic matches a protocol-relative custom endpoint after jQuery normalizes its URL', async ( t ) => {
	const { window, overlay, submit, respond } = await classic( t );
	window.wc_checkout_params.checkout_url = '//shop.example/custom/checkout';
	submit( 'payment_method=spart', window.wc_checkout_params.checkout_url );
	assert.equal( overlay.isVisible(), true );
	respond( { result: 'failure' } );
	assert.equal( overlay.isVisible(), false );
} );

async function blocks( t, enabled = true ) {
	const p = page( t );
	let registration;
	const callbacks = new Set();
	const onCheckoutFail = ( callback ) => {
		callbacks.add( callback );
		return () => callbacks.delete( callback );
	};
	p.window.wp = { element: React, htmlEntities: { decodeEntities: ( s ) => s }, i18n: { __: ( s ) => s } };
	p.window.wc = {
		wcBlocksRegistry: { registerPaymentMethod( value ) { registration = value; } },
		wcSettings: { getSetting: () => ( { description: 'Spart description' } ) },
	};
	if ( ! enabled ) delete p.window.spartCheckoutLoading;
	load( p.window, 'blocks-checkout.js' );
	const previousWindow = global.window;
	const previousDocument = global.document;
	global.window = p.window;
	global.document = p.document;
	global.IS_REACT_ACT_ENVIRONMENT = true;
	const root = createRoot( p.document.querySelector( 'main' ) );
	t.after( async () => {
		await act( () => root.unmount() );
		global.window = previousWindow;
		global.document = previousDocument;
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	const render = async ( state = 'idle', extra = {} ) => act( () => root.render(
		React.cloneElement( registration.content, {
			activePaymentMethod: 'spart',
			checkoutStatus: {
				isIdle: state === 'idle', isProcessing: state === 'processing',
				isComplete: state === 'complete', isCalculating: state === 'calculating',
			},
			paymentStatus: { hasError: false, hasFailed: false },
			eventRegistration: { onCheckoutFail },
			...extra,
		} )
	) );
	return { ...p, root, render, callbacks };
}

test( 'Blocks follows validated processing through after-processing and redirect, not idle or validation', async ( t ) => {
	const { overlay, render } = await blocks( t );
	assert.ok( overlay );
	for ( const state of [ 'idle', 'before', 'calculating' ] ) {
		await render( state );
		assert.equal( overlay.isVisible(), false );
	}
	for ( const state of [ 'processing', 'after', 'complete' ] ) {
		await render( state );
		assert.equal( overlay.isVisible(), true, state );
	}
} );

test( 'Blocks fail event releases immediately, does not alter payment response, and unsubscribes on unmount', async ( t ) => {
	const { overlay, render, callbacks, root } = await blocks( t );
	assert.ok( overlay );
	await render( 'processing' );
	assert.equal( overlay.isVisible(), true );
	assert.equal( callbacks.size, 1 );
	for ( const callback of callbacks ) assert.equal( callback(), true );
	assert.equal( overlay.isVisible(), false );
	await render( 'idle' );
	await render( 'processing' );
	assert.equal( overlay.isVisible(), true );
	await act( () => root.unmount() );
	assert.equal( overlay.isVisible(), false );
	assert.equal( callbacks.size, 0 );
} );

test( 'Blocks resets on payment error, cancelled idle and gateway change', async ( t ) => {
	const { overlay, render } = await blocks( t );
	assert.ok( overlay );
	await render( 'processing', { activePaymentMethod: 'cod' } );
	assert.equal( overlay.isVisible(), false );
	for ( const extra of [
		{ paymentStatus: { hasError: true } },
		{ paymentStatus: { hasFailed: true } },
		{ activePaymentMethod: 'cod' },
	] ) {
		await render( 'idle' );
		await render( 'processing' );
		assert.equal( overlay.isVisible(), true );
		await render( 'processing', extra );
		assert.equal( overlay.isVisible(), false );
	}
	await render( 'processing' );
	await render( 'idle' );
	assert.equal( overlay.isVisible(), false );
} );

test( 'Blocks with loading disabled retains description and registers no loading observers', async ( t ) => {
	const { document, callbacks, render } = await blocks( t, false );
	await render( 'processing' );
	assert.match( document.querySelector( 'main' ).textContent, /Spart description/ );
	assert.equal( callbacks.size, 0 );
	assert.equal( document.querySelector( 'dialog' ), null );
} );
