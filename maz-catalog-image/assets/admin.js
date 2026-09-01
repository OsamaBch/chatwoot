/**
 * Catalogue image picker for the product edit screen.
 * Vanilla JS on top of wp.media; no jQuery UI dependencies.
 */
( function () {
	'use strict';

	function init() {
		var input = document.getElementById( 'maz-catalog-image-id' );
		var preview = document.getElementById( 'maz-catalog-image-preview' );
		var setLink = document.getElementById( 'maz-catalog-image-set' );
		var removeLink = document.getElementById( 'maz-catalog-image-remove' );
		var frame = null;

		if ( ! input || ! preview || ! setLink || ! removeLink || ! window.wp || ! window.wp.media ) {
			return;
		}

		function renderEmpty() {
			var p = document.createElement( 'p' );
			p.className = 'description';
			p.textContent = setLink.getAttribute( 'data-empty-text' ) || '';
			preview.textContent = '';
			preview.appendChild( p );
		}

		function renderThumb( url, alt ) {
			var img = document.createElement( 'img' );
			img.src = url;
			img.alt = alt || '';
			img.style.maxWidth = '100%';
			img.style.height = 'auto';
			preview.textContent = '';
			preview.appendChild( img );
		}

		function openFrame( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: setLink.getAttribute( 'data-frame-title' ) || '',
					button: { text: setLink.getAttribute( 'data-frame-button' ) || '' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var url = ( attachment.sizes && attachment.sizes.thumbnail )
						? attachment.sizes.thumbnail.url
						: attachment.url;

					input.value = String( attachment.id );
					renderThumb( url, attachment.alt );
					setLink.textContent = setLink.getAttribute( 'data-replace-text' ) || setLink.textContent;
					removeLink.style.display = '';
				} );
			}

			frame.open();
		}

		function removeImage( event ) {
			event.preventDefault();
			input.value = '';
			renderEmpty();
			setLink.textContent = setLink.getAttribute( 'data-set-text' ) || setLink.textContent;
			removeLink.style.display = 'none';
		}

		setLink.addEventListener( 'click', openFrame );
		removeLink.addEventListener( 'click', removeImage );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
