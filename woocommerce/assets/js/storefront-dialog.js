( function () {
	'use strict';

	var dialog;
	var trigger;
	var scrollStyles = [];
	var background = [];
	var active = false;

	function focusable() {
		return Array.from( dialog.querySelectorAll( 'button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])' ) )
			.filter( function ( node ) { return ! node.hidden && ! node.closest( '[hidden], [inert]' ); } );
	}

	function restore() {
		if ( ! active ) {
			return;
		}
		active = false;
		scrollStyles.forEach( function ( item ) {
			item.node.style.setProperty( 'overflow', item.overflow, item.priority );
		} );
		scrollStyles = [];
		background.forEach( function ( item ) {
			if ( item.inert === null ) {
				item.node.removeAttribute( 'inert' );
			} else {
				item.node.setAttribute( 'inert', item.inert );
			}
			if ( item.hidden === null ) {
				item.node.removeAttribute( 'aria-hidden' );
			} else {
				item.node.setAttribute( 'aria-hidden', item.hidden );
			}
		} );
		background = [];
		dialog.classList.remove( 'spart-explainer--fallback' );
		var focusTarget = trigger && trigger.isConnected ? trigger : document.querySelector( '[data-spart-dialog-open]' );
		if ( focusTarget ) {
			focusTarget.focus();
		}
	}

	function close() {
		if ( ! active ) {
			return;
		}
		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
		restore();
	}

	function open( button ) {
		if ( active ) {
			return;
		}
		var nextDialog = document.getElementById( 'spart-explainer' );
		if ( ! nextDialog ) {
			return;
		}
		if ( dialog !== nextDialog ) {
			dialog = nextDialog;
			dialog.addEventListener( 'close', restore );
			dialog.addEventListener( 'cancel', function ( event ) {
				event.preventDefault();
				close();
			} );
		}
		trigger = button;
		scrollStyles = [ document.body, document.documentElement ].map( function ( node ) {
			return { node: node, overflow: node.style.getPropertyValue( 'overflow' ), priority: node.style.getPropertyPriority( 'overflow' ) };
		} );
		active = true;
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
			dialog.classList.add( 'spart-explainer--fallback' );
			for ( var ancestor = dialog; ancestor.parentElement && ancestor !== document.body; ancestor = ancestor.parentElement ) {
				Array.from( ancestor.parentElement.children ).forEach( function ( node ) {
					if ( node !== ancestor ) {
						background.push( { node: node, inert: node.getAttribute( 'inert' ), hidden: node.getAttribute( 'aria-hidden' ) } );
					}
				} );
			}
		}
		scrollStyles.forEach( function ( item ) {
			item.node.style.setProperty( 'overflow', 'hidden', 'important' );
		} );
		dialog.scrollTop = 0;
		( focusable()[ 0 ] || dialog ).focus();
		background.forEach( function ( item ) {
			item.node.setAttribute( 'inert', '' );
			item.node.setAttribute( 'aria-hidden', 'true' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( active && ! dialog.contains( event.target ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );

	document.addEventListener( 'click', function ( event ) {
		if ( ! ( event.target instanceof Element ) ) {
			return;
		}
		var button = event.target.closest( '[data-spart-dialog-open]' );
		if ( button ) {
			event.preventDefault();
			open( button );
		} else if ( event.target.closest( '#spart-explainer [data-spart-dialog-close]' ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! active ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();
		} else if ( event.key === 'Tab' ) {
			var nodes = focusable();
			var first = nodes[ 0 ] || dialog;
			var last = nodes[ nodes.length - 1 ] || dialog;
			if ( ! dialog.contains( document.activeElement ) || document.activeElement === dialog ||
				( event.shiftKey && document.activeElement === first ) ||
				( ! event.shiftKey && document.activeElement === last ) ) {
				event.preventDefault();
				( event.shiftKey ? last : first ).focus();
			}
		}
	} );

	document.addEventListener( 'focusin', function ( event ) {
		if ( active && ! dialog.contains( event.target ) ) {
			( focusable()[ 0 ] || dialog ).focus();
		}
	} );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			close();
		}
	} );
} )();
