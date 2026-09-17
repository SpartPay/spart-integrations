( function ( $, window ) {
	'use strict';

	$( function () {
		var labels = window.spartLoadingScreenAdminConfig;
		var config = window.spartCheckoutLoadingConfig;
		var imageId = $( '#woocommerce_spart_loading_screen_image_id' );
		if ( ! labels || ! config || ! imageId.length ) {
			return;
		}

		var imageUrl = config.imageUrl;
		var frame;
		var controls = $( '<p>' ).insertAfter( imageId );
		var error = $( '<p>', { role: 'alert', class: 'description' } ).insertAfter( controls );

		function button( label, callback ) {
			$( '<button>', { type: 'button', class: 'button', text: label } )
				.on( 'click', callback ).appendTo( controls );
			controls.append( ' ' );
		}

		button( labels.chooseImage, function () {
			if ( ! frame ) {
				frame = window.wp.media( {
					title: labels.chooseImage,
					button: { text: labels.chooseImage },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var selected = frame.state().get( 'selection' ).first();
					if ( ! selected ) {
						return;
					}
					var image = selected.toJSON();
					if ( ! /^image\/(jpeg|png|gif|webp|avif|bmp|tiff|x-icon|vnd\.microsoft\.icon)$/.test( image.mime ) ) {
						error.text( labels.invalidImage );
						return;
					}
					error.text( '' );
					imageId.val( image.id ).trigger( 'change' );
					imageUrl = image.url;
				} );
			}
			frame.open();
		} );

		button( labels.clearImage, function () {
			error.text( '' );
			imageId.val( 0 ).trigger( 'change' );
			imageUrl = '';
		} );

		button( labels.preview, function () {
			var color = $( '#woocommerce_spart_loading_screen_backdrop_color' ).val();
			var rawOpacity = $( '#woocommerce_spart_loading_screen_backdrop_opacity' ).val();
			var opacity = /^\d+$/.test( rawOpacity ) ? Number( rawOpacity ) : NaN;
			if ( window.spartCheckoutLoading ) {
				window.spartCheckoutLoading.show( {
					preview: true,
					closeLabel: labels.closeLabel,
					backdropColor: /^#[0-9a-f]{6}$/i.test( color ) ? color.toLowerCase() : '#192a23',
					backdropOpacity: opacity >= 0 && opacity <= 100 ? opacity : 55,
					imageUrl: imageUrl,
				} );
			}
		} );
	} );
}( jQuery, window ) );
