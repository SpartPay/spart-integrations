const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

function page( t, native = true ) {
	const dom = new JSDOM(
		'<main><button data-spart-dialog-open>Help</button><input type="radio" name="payment_method" value="other" checked></main>' +
		'<dialog id="spart-explainer" aria-labelledby="spart-explainer-title" tabindex="-1"><h2 id="spart-explainer-title">Buy together</h2><button data-spart-dialog-close>Close</button><a href="#details">Details</a></dialog>',
		{ runScripts: 'outside-only' }
	);
	t.after( () => dom.window.close() );
	const { window } = dom;
	if ( native ) {
		window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
		window.HTMLDialogElement.prototype.close = function () {
			this.open = false;
			this.dispatchEvent( new window.Event( 'close' ) );
		};
	}
	const file = path.join( __dirname, '../../assets/js/storefront-dialog.js' );
	if ( fs.existsSync( file ) ) window.eval( fs.readFileSync( file, 'utf8' ) );
	return { window, document: window.document, dialog: window.document.querySelector( 'dialog' ) };
}

for ( const native of [ true, false ] ) {
	test( `help opens on demand, traps focus and restores scroll/focus (${ native ? 'native' : 'fallback' })`, ( t ) => {
		const { window, document, dialog } = page( t, native );
		const opener = document.querySelector( '[data-spart-dialog-open]' );
		assert.equal( dialog.open, false );
		document.body.style.setProperty( 'overflow', 'scroll', 'important' );
		document.documentElement.style.setProperty( 'overflow', 'auto', 'important' );
		opener.focus();
		opener.click();
		assert.equal( dialog.open, true, 'help must open the shared dialog' );
		assert.equal( document.activeElement, dialog.querySelector( 'button' ) );
		assert.equal( document.body.style.overflow, 'hidden' );
		assert.equal( document.documentElement.style.overflow, 'hidden' );
		document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true } ) );
		assert.equal( document.activeElement, dialog.querySelector( 'a' ) );
		document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true, cancelable: true } ) );
		assert.equal( document.activeElement, dialog.querySelector( 'button' ) );
		document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
		assert.equal( dialog.open, false );
		assert.equal( document.activeElement, opener );
		assert.equal( document.body.style.overflow, 'scroll' );
		assert.equal( document.body.style.getPropertyPriority( 'overflow' ), 'important' );
		assert.equal( document.documentElement.style.overflow, 'auto' );
		assert.equal( document.documentElement.style.getPropertyPriority( 'overflow' ), 'important' );
		assert.equal( document.querySelector( 'input' ).checked, true );
		assert.equal( document.querySelector( 'main' ).hasAttribute( 'inert' ), false );
	} );
}

test( 'replacement cart markup opens the same dialog and close button restores its trigger', ( t ) => {
	const { document, dialog } = page( t );
	document.querySelector( 'main' ).innerHTML = '<button data-spart-dialog-open><svg><path></path></svg>New help</button>';
	const opener = document.querySelector( '[data-spart-dialog-open]' );
	opener.querySelector( 'path' ).dispatchEvent( new document.defaultView.MouseEvent( 'click', { bubbles: true } ) );
	assert.equal( dialog.open, true );
	assert.equal( document.querySelectorAll( '#spart-explainer' ).length, 1 );
	dialog.querySelector( 'button' ).click();
	assert.equal( dialog.open, false );
	assert.equal( document.activeElement, opener );
} );

test( 'native cancel and bfcache restore release the scroll lock', ( t ) => {
	const { window, document, dialog } = page( t );
	document.querySelector( '[data-spart-dialog-open]' ).click();
	dialog.dispatchEvent( new window.Event( 'cancel', { cancelable: true } ) );
	assert.equal( dialog.open, false );
	document.querySelector( '[data-spart-dialog-open]' ).click();
	assert.equal( dialog.open, true );
	window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: true } ) );
	assert.equal( dialog.open, false );
	assert.equal( document.body.style.overflow, '' );
} );

test( 'fallback blocks background clicks and restores preexisting accessibility attributes', ( t ) => {
	const { document, dialog } = page( t, false );
	const main = document.querySelector( 'main' );
	main.setAttribute( 'aria-hidden', 'false' );
	let changes = 0;
	main.addEventListener( 'click', () => changes++ );
	document.querySelector( '[data-spart-dialog-open]' ).click();
	changes = 0;
	document.querySelector( 'input' ).click();
	assert.equal( changes, 0 );
	dialog.querySelector( 'button' ).click();
	assert.equal( main.getAttribute( 'aria-hidden' ), 'false' );
} );

test( 'closing after cart replacement restores focus to the replacement help button', ( t ) => {
	const { document, dialog } = page( t );
	document.querySelector( '[data-spart-dialog-open]' ).click();
	document.querySelector( 'main' ).innerHTML = '<button data-spart-dialog-open>Replacement help</button>';
	dialog.querySelector( 'button' ).click();
	assert.equal( document.activeElement, document.querySelector( '[data-spart-dialog-open]' ) );
} );

test( 'nested fallback hides background siblings at every ancestor and restores exact attributes', ( t ) => {
	const { document, dialog } = page( t, false );
	const main = document.querySelector( 'main' );
	const opener = main.querySelector( 'button' );
	const wrapper = document.createElement( 'div' );
	wrapper.innerHTML = '<header aria-hidden="false">Header</header><footer><nav inert="inert" aria-hidden="true">Footer links</nav><section><p>Footer text</p></section></footer>';
	document.body.append( wrapper );
	wrapper.prepend( main );
	wrapper.querySelector( 'section' ).append( dialog );
	const siblings = [ main, wrapper.querySelector( 'header' ), wrapper.querySelector( 'nav' ), wrapper.querySelector( 'p' ) ];
	const original = siblings.map( ( node ) => [ node.getAttribute( 'inert' ), node.getAttribute( 'aria-hidden' ) ] );
	for ( let repeat = 0; repeat < 2; repeat++ ) {
		opener.click();
		for ( const node of siblings ) {
			assert.equal( node.hasAttribute( 'inert' ), true, node.tagName );
			assert.equal( node.getAttribute( 'aria-hidden' ), 'true', node.tagName );
		}
		for ( let node = dialog; node; node = node.parentElement ) {
			assert.equal( node.hasAttribute( 'inert' ), false, node.tagName );
			assert.notEqual( node.getAttribute( 'aria-hidden' ), 'true', node.tagName );
		}
		dialog.querySelector( 'button' ).click();
		assert.equal( document.activeElement, opener );
		siblings.forEach( ( node, index ) => assert.deepEqual(
			[ node.getAttribute( 'inert' ), node.getAttribute( 'aria-hidden' ) ], original[ index ]
		) );
	}
} );
