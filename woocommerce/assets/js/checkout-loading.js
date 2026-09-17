( function ( window, document ) {
	'use strict';

	var config = window.spartCheckoutLoadingConfig;
	if ( ! config || window.spartCheckoutLoading ) {
		return;
	}

	var dialog;
	var indicator;
	var options;
	var previousFocus;
	var pendingFocus = false;
	var overflow;
	var overflowPriority;
	var motion = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	function renderIndicator() {
		indicator.replaceChildren();
		if ( options.imageUrl && ! motion.matches ) {
			var image = document.createElement( 'img' );
			image.className = 'spart-loading-image';
			image.alt = '';
			image.addEventListener( 'error', function () {
				if ( image.parentNode !== indicator ) {
					return;
				}
				options.imageUrl = '';
				renderIndicator();
			}, { once: true } );
			image.src = options.imageUrl;
			indicator.appendChild( image );
		} else {
			var spinner = document.createElement( 'span' );
			spinner.className = 'spart-loading-spinner';
			indicator.appendChild( spinner );
		}
	}

	function restoreFocus() {
		var error = ! options.preview && document.querySelector(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, [aria-invalid="true"]'
		);
		var focus = error || previousFocus;
		pendingFocus = false;
		if ( focus && focus.isConnected ) {
			var temporaryTabIndex = ! focus.hasAttribute( 'tabindex' ) && focus.tabIndex < 0;
			if ( temporaryTabIndex ) {
				focus.setAttribute( 'tabindex', '-1' );
			}
			focus.focus( { preventScroll: true } );
			if ( temporaryTabIndex ) {
				var restoreTabIndex = function () {
					if ( focus.getAttribute( 'tabindex' ) === '-1' ) {
						focus.removeAttribute( 'tabindex' );
					}
				};
				// Removing tabindex while focused sends native browser focus back to body.
				if ( document.activeElement === focus ) {
					focus.addEventListener( 'blur', restoreTabIndex, { once: true } );
				} else {
					restoreTabIndex();
				}
			}
			pendingFocus = document.activeElement !== focus && ! options.preview;
		}
	}

	function hide() {
		if ( ! dialog || ! dialog.open ) {
			if ( pendingFocus && document.activeElement === document.body ) {
				restoreFocus();
			}
			return;
		}
		dialog.close();
		dialog.remove();
		document.body.style.setProperty( 'overflow', overflow, overflowPriority );
		motion.removeEventListener( 'change', renderIndicator );
		restoreFocus();
	}

	function show( overrides ) {
		if ( dialog && dialog.open ) {
			return;
		}
		options = Object.assign( {}, config, overrides );
		pendingFocus = false;
		dialog = document.createElement( 'dialog' );
		// Leave older browsers to WooCommerce rather than applying a partial input lock.
		if ( typeof dialog.showModal !== 'function' ) {
			return;
		}
		dialog.className = 'spart-checkout-loading';
		dialog.tabIndex = -1;
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'spart-loading-title' );
		dialog.setAttribute( 'aria-describedby', 'spart-loading-description' );
		dialog.style.setProperty( '--spart-loading-backdrop', options.backdropColor );
		dialog.style.setProperty( '--spart-loading-opacity', String( options.backdropOpacity / 100 ) );

		indicator = document.createElement( 'div' );
		indicator.className = 'spart-loading-indicator';
		indicator.setAttribute( 'aria-hidden', 'true' );
		dialog.appendChild( indicator );
		renderIndicator();

		var status = document.createElement( 'div' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );
		var title = document.createElement( 'h2' );
		title.id = 'spart-loading-title';
		title.textContent = options.title;
		var description = document.createElement( 'p' );
		description.id = 'spart-loading-description';
		description.textContent = options.description;
		status.append( title, description );
		dialog.appendChild( status );

		if ( options.preview ) {
			var close = document.createElement( 'button' );
			close.type = 'button';
			close.className = 'spart-loading-close';
			close.textContent = options.closeLabel;
			close.addEventListener( 'click', hide );
			dialog.appendChild( close );
		}
		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			if ( options.preview ) {
				hide();
			}
		} );
		previousFocus = document.activeElement;
		overflow = document.body.style.getPropertyValue( 'overflow' );
		overflowPriority = document.body.style.getPropertyPriority( 'overflow' );
		document.body.appendChild( dialog );
		dialog.showModal();
		document.body.style.setProperty( 'overflow', 'hidden', 'important' );
		motion.addEventListener( 'change', renderIndicator );
	}

	window.spartCheckoutLoading = {
		show: show,
		hide: hide,
		isVisible: function () { return !! ( dialog && dialog.open ); },
	};
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			hide();
		}
	} );
}( window, document ) );
