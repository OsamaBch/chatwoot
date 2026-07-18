/**
 * Maz Allowlist settings page: dry-run preview.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'maz-preview-btn' );
		var out = document.getElementById( 'maz-preview-result' );
		var form = document.getElementById( 'maz-config-form' );
		if ( ! btn || ! out || ! form || typeof MazAllowlist === 'undefined' ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			out.innerHTML = '<p><em>…</em></p>';

			var data = new FormData( form );
			// Appended last => wins over the form's own hidden action field.
			data.append( 'action', 'maz_preview' );
			data.append( 'maz_preview_nonce', MazAllowlist.nonce );

			fetch( MazAllowlist.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( payload ) {
					btn.disabled = false;
					if ( ! payload || ! payload.success ) {
						out.innerHTML = '<div class="notice notice-error inline"><p>' + MazAllowlist.error + '</p></div>';
						return;
					}
					var d = payload.data;
					var fmt = function ( n ) { return Number( n ).toLocaleString(); };
					var lines = [];
					lines.push( '<strong>This configuration would hide ' + fmt( d.hidden ) + ' of ' + fmt( d.total ) + ' orders:</strong>' );
					if ( d.per_rule.amount !== null ) {
						lines.push( fmt( d.per_rule.amount ) + ' hidden by amount threshold' );
					}
					if ( d.per_rule.sample !== null ) {
						lines.push( fmt( d.per_rule.sample ) + ' hidden by random sample' );
					}
					if ( d.per_rule.allowlist !== null ) {
						lines.push( fmt( d.per_rule.allowlist ) + ' hidden by allowlist' );
					}
					lines.push( fmt( d.visible ) + ' visible' );

					var html = '<div class="notice notice-info inline"><p>' + lines.join( '<br>&nbsp;&nbsp;' ) + '</p>';
					if ( d.warnings && d.warnings.length ) {
						html += '<p><em>' + d.warnings.join( '<br>' ) + '</em></p>';
					}
					html += '</div>';
					out.innerHTML = html;
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					out.innerHTML = '<div class="notice notice-error inline"><p>' + MazAllowlist.error + '</p></div>';
					if ( window.console ) {
						console.error( 'maz-allowlist preview:', err );
					}
				} );
		} );
	} );
}() );
