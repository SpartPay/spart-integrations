const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

test( 'popup text inherits its body font instead of merchant heading fonts', ( t ) => {
	const css = fs.readFileSync( path.join( __dirname, '../../assets/css/spart.css' ), 'utf8' );
	const dom = new JSDOM( `<style>
		body { font-family: Arial, sans-serif; }
		h2, h3, button { font-family: "Caveat Brush", cursive; }
	</style><style>${ css }</style>
	<h2 id="merchant-heading">Store heading</h2><button id="merchant-button">Store button</button>
	<dialog class="spart-explainer" open>
		<h2>Shop together.</h2><h3>How does it work?</h3>
		<p>Share your purchase <strong>with friends</strong>.</p>
		<ul><li><span>No upfront payment</span></li></ul>
		<button>Close</button>
	</dialog>` );
	t.after( () => dom.window.close() );
	const { document } = dom.window;
	const popup = document.querySelector( '.spart-explainer' );
	assert.match( dom.window.getComputedStyle( popup ).fontFamily, /Segoe UI/ );
	for ( const element of popup.querySelectorAll( '*' ) ) {
		assert.equal( dom.window.getComputedStyle( element ).fontFamily, 'inherit', element.tagName );
	}
	assert.equal( dom.window.getComputedStyle( document.querySelector( '#merchant-heading' ) ).fontFamily, '"Caveat Brush", cursive' );
	assert.equal( dom.window.getComputedStyle( document.querySelector( '#merchant-button' ) ).fontFamily, '"Caveat Brush", cursive' );
} );

test( 'storefront fixes bump the shared asset cache version and plugin header together', () => {
	const root = path.join( __dirname, '../..' );
	const plugin = fs.readFileSync( path.join( root, 'src/Spart/WooCommerce/Plugin.php' ), 'utf8' );
	const bootstrap = fs.readFileSync( path.join( root, 'spart-woocommerce.php' ), 'utf8' );
	const version = plugin.match( /public const VERSION = '([^']+)'/ )[ 1 ];
	assert.equal( version, '0.5.4' );
	assert.equal( bootstrap.match( /Version:\s+(\S+)/ )[ 1 ], version );
} );

test( 'popup font inheritance takes priority over later high-specificity theme rules', ( t ) => {
	const css = fs.readFileSync( path.join( __dirname, '../../assets/css/spart.css' ), 'utf8' );
	const dom = new JSDOM( `<style>${ css }</style>
		<style>body.shop-theme #site-footer h2, body.shop-theme dialog.spart-explainer { font-family: "Caveat Brush", cursive; }</style>
		<body class="shop-theme"><footer id="site-footer">
		<h2 id="merchant-heading">Store heading</h2>
		<dialog id="merchant-dialog">Store dialog</dialog>
		<dialog class="spart-explainer"><h2>Shop together.</h2><h3>How?</h3><p>Share <strong>together</strong>.</p><button>Close</button></dialog>
		</footer></body>` );
	t.after( () => dom.window.close() );
	const { document } = dom.window;
	const baseFontRule = Array.from( document.styleSheets[ 0 ].cssRules ).find(
		( rule ) => rule.selectorText === '.spart-explainer'
	);
	assert.match( baseFontRule.style.getPropertyValue( 'font-family' ), /Segoe UI/ );
	assert.equal( baseFontRule.style.getPropertyPriority( 'font-family' ), 'important' );
	assert.equal( document.querySelector( '#merchant-dialog' ).matches( baseFontRule.selectorText ), false );
	const fontRule = Array.from( document.styleSheets[ 0 ].cssRules ).find(
		( rule ) => rule.style && rule.style.getPropertyValue( 'font-family' ) === 'inherit'
	);
	assert.ok( fontRule );
	assert.equal( fontRule.style.getPropertyPriority( 'font-family' ), 'important' );
	for ( const element of document.querySelectorAll( '.spart-explainer *' ) ) {
		assert.ok( element.matches( fontRule.selectorText ), element.tagName );
	}
	assert.equal( document.querySelector( '#merchant-heading' ).matches( fontRule.selectorText ), false );
} );

test( 'storefront styles keep closed dialogs hidden and scope the neutral panel to Spart', ( t ) => {
	const css = fs.readFileSync( path.join( __dirname, '../../assets/css/spart.css' ), 'utf8' );
	const dom = new JSDOM( `<style>${ css }</style><div class="spart-messaging"></div><button>Store button</button><dialog class="spart-explainer"></dialog>` );
	t.after( () => dom.window.close() );
	const { document } = dom.window;
	const panel = dom.window.getComputedStyle( document.querySelector( '.spart-messaging' ) );
	assert.equal( panel.backgroundColor, 'rgb(255, 255, 255)' );
	assert.equal( panel.display, 'flex' );
	const dialog = document.querySelector( 'dialog' );
	assert.equal( dom.window.getComputedStyle( dialog ).display, 'none' );
	dialog.open = true;
	const opened = dom.window.getComputedStyle( dialog );
	assert.equal( opened.overflowY, 'auto' );
	assert.equal( opened.overflowX, 'hidden' );
	assert.equal( opened.boxSizing, 'border-box' );
	assert.notEqual( dom.window.getComputedStyle( document.querySelector( 'button' ) ).backgroundColor, 'rgb(255, 255, 255)' );
} );

test( 'classic checkout keeps its plain label bold and wordmark right without affecting other gateways', ( t ) => {
	const css = fs.readFileSync( path.join( __dirname, '../../assets/css/spart.css' ), 'utf8' );
	const dom = new JSDOM( `<style>${ css }</style>
		<ul><li class="wc_payment_method payment_method_spart"><input type="radio" id="payment_method_spart">
		<label for="payment_method_spart">Share your purchase without paying upfront<img class="spart-checkout-logo" alt="SPART!"></label></li>
		<li class="payment_method_other"><label>Other gateway</label></li></ul>` );
	t.after( () => dom.window.close() );
	const { document } = dom.window;
	assert.equal( dom.window.getComputedStyle( document.querySelector( '.payment_method_spart label' ) ).fontWeight, '700' );
	assert.equal( dom.window.getComputedStyle( document.querySelector( '.spart-checkout-logo' ) ).float, 'right' );
	assert.notEqual( dom.window.getComputedStyle( document.querySelector( '.payment_method_other label' ) ).fontWeight, '700' );
} );
