/**
 * Mazyoud SKU Image Matcher — admin screen behavior.
 * Vanilla JS, loaded only on the plugin's own screen.
 */
( function () {
	'use strict';

	if ( typeof window.msimData === 'undefined' ) {
		return;
	}

	var data = window.msimData;
	var currentRunId = data.plannedRunId || 0;
	var pollTimer = null;

	function $( selector ) {
		return document.querySelector( selector );
	}

	function post( action, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text !== 'undefined' ) {
			node.textContent = text;
		}
		return node;
	}

	function notice( container, message ) {
		var box = el( 'div', 'msim-notice-inline', message );
		container.prepend( box );
		window.setTimeout( function () {
			box.remove();
		}, 8000 );
	}

	/* ------------------------------------------------------------------ */
	/* Report rendering                                                    */
	/* ------------------------------------------------------------------ */

	function summaryItem( label, value ) {
		var li = el( 'li' );
		li.appendChild( el( 'strong', '', String( value ) ) );
		li.appendChild( document.createTextNode( label ) );
		return li;
	}

	function csvLink( url, label ) {
		var a = el( 'a', 'button button-small', label );
		a.href = url;
		return a;
	}

	function sectionTable( title, headers, rows, total, csvUrl, container ) {
		if ( ! rows.length ) {
			return;
		}

		var heading = el( 'h3', '', title + ' (' + total + ')' );
		container.appendChild( heading );

		var table = el( 'table', 'widefat striped' );
		var thead = el( 'thead' );
		var headRow = el( 'tr' );
		headers.forEach( function ( h ) {
			headRow.appendChild( el( 'th', '', h ) );
		} );
		thead.appendChild( headRow );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		rows.forEach( function ( cells ) {
			var tr = el( 'tr' );
			cells.forEach( function ( cell ) {
				var td = el( 'td' );
				if ( cell && typeof cell === 'object' && cell.nodeType ) {
					td.appendChild( cell );
				} else {
					td.textContent = String( cell );
				}
				tr.appendChild( td );
			} );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		container.appendChild( table );

		if ( total > rows.length && csvUrl ) {
			var p = el( 'p' );
			p.appendChild( document.createTextNode( 'Showing first ' + rows.length + ' of ' + total + '. ' ) );
			p.appendChild( csvLink( csvUrl, 'Download full CSV' ) );
			container.appendChild( p );
		}
	}

	function renderReport( payload ) {
		var report = payload.report;
		var counts = report.counts;
		var container = $( '#msim-report' );

		container.hidden = false;
		container.textContent = '';

		var summary = el( 'ul', 'msim-summary' );
		summary.appendChild( summaryItem( 'files scanned', counts.files_scanned ) );
		summary.appendChild( summaryItem( 'products matched', counts.matched_products ) );
		summary.appendChild( summaryItem( 'files matched', counts.matched_files ) );
		summary.appendChild( summaryItem( 'SKU not found', counts.not_found ) );
		summary.appendChild( summaryItem( 'variation SKU (v2)', counts.variation ) );
		summary.appendChild( summaryItem( 'duplicate SKU errors', counts.duplicate_sku ) );
		summary.appendChild( summaryItem( 'duplicate renames', counts.duplicate_rename ) );
		summary.appendChild( summaryItem( 'skipped (has images)', counts.skipped_existing ) );
		summary.appendChild( summaryItem( 'warnings', counts.warnings ) );
		container.appendChild( summary );

		sectionTable(
			'Matched products',
			[ 'Product', 'Matched by', 'Files', 'Gallery size', 'Warnings' ],
			report.matched.map( function ( row ) {
				var link = el( 'a', '', '#' + row.product_id + ' ' + row.name );
				link.href = row.edit_link;
				return [
					link,
					row.matched_by,
					row.files,
					row.gallery,
					row.warnings.length ? row.warnings.join( ' — ' ) : '—',
				];
			} ),
			counts.matched_products,
			null,
			container
		);

		sectionTable(
			'Media whose SKU was not found',
			[ 'Attachment', 'Filename' ],
			report.not_found.map( function ( row ) {
				return [ '#' + row.id, row.file ];
			} ),
			counts.not_found,
			report.csv.not_found,
			container
		);

		sectionTable(
			'Variation SKU — planned for v2 (not assigned)',
			[ 'Attachment', 'Filename', 'Variation SKU' ],
			report.variation.map( function ( row ) {
				return [ '#' + row.id, row.file, row.sku ];
			} ),
			counts.variation,
			report.csv.variation,
			container
		);

		sectionTable(
			'Duplicate SKU across products (skipped — never guessing)',
			[ 'Attachment', 'Filename', 'SKU', 'Product IDs' ],
			report.duplicate_sku.map( function ( row ) {
				return [ '#' + row.id, row.file, row.sku, row.product_ids.join( ', ' ) ];
			} ),
			counts.duplicate_sku,
			report.csv.duplicate_sku,
			container
		);

		sectionTable(
			'WordPress duplicate-upload renames (flagged, not assigned)',
			[ 'Attachment', 'Stored filename', 'Original title' ],
			report.duplicate_rename.map( function ( row ) {
				return [ '#' + row.id, row.file, row.title ];
			} ),
			counts.duplicate_rename,
			report.csv.duplicate_rename,
			container
		);

		sectionTable(
			'Skipped — product already has images',
			[ 'Product', 'Name', 'Matched files' ],
			report.skipped_existing.map( function ( row ) {
				return [ '#' + row.product_id, row.name, row.files ];
			} ),
			counts.skipped_existing,
			report.csv.skipped_existing,
			container
		);

		if ( report.warnings.length ) {
			container.appendChild( el( 'h3', '', 'Warnings (' + counts.warnings + ')' ) );
			var list = el( 'ul', 'msim-warning-list' );
			report.warnings.forEach( function ( warning ) {
				list.appendChild( el( 'li', '', warning ) );
			} );
			container.appendChild( list );
			if ( counts.warnings > report.warnings.length ) {
				var more = el( 'p' );
				more.appendChild( csvLink( report.csv.warnings, 'Download all warnings as CSV' ) );
				container.appendChild( more );
			}
		}

		var runBtn = $( '#msim-run-btn' );
		runBtn.disabled = ! payload.can_run;
	}

	/* ------------------------------------------------------------------ */
	/* Progress polling                                                    */
	/* ------------------------------------------------------------------ */

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function pollStatus( runId ) {
		stopPolling();

		post( 'msim_status', { run_id: runId } ).then( function ( response ) {
			if ( ! response.success ) {
				pollTimer = window.setTimeout( function () {
					pollStatus( runId );
				}, 5000 );
				return;
			}

			var s = response.data;
			var box = $( '#msim-progress' );
			box.hidden = false;
			box.querySelector( '.msim-progress-fill' ).style.width = s.percent + '%';

			var text = s.processed + ' / ' + s.total + ' products processed';
			if ( s.failed > 0 ) {
				text += ' — ' + s.failed + ' failed';
			}
			if ( s.skipped > 0 ) {
				text += ' — ' + s.skipped + ' skipped';
			}
			if ( s.status === 'rolling_back' ) {
				text = 'Rolling back: ' + ( s.rolled_back + s.conflicts ) + ' restored/flagged of ' + s.total;
			}
			if ( s.stalled ) {
				text += ' — queue idle: use "Recover (re-enqueue)" in the history table if this persists';
			}
			box.querySelector( '.msim-progress-text' ).textContent = text;

			if ( s.active ) {
				pollTimer = window.setTimeout( function () {
					pollStatus( runId );
				}, 3000 );
			} else {
				box.querySelector( '.msim-progress-text' ).textContent = text + ' — finished (' + s.status.replace( /_/g, ' ' ) + '). Reloading…';
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			}
		} ).catch( function () {
			pollTimer = window.setTimeout( function () {
				pollStatus( runId );
			}, 5000 );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	function bind() {
		var scanBtn = $( '#msim-scan-btn' );
		var runBtn = $( '#msim-run-btn' );
		var cancelBtn = $( '#msim-cancel-btn' );
		var wrap = $( '.msim-wrap' );

		scanBtn.addEventListener( 'click', function () {
			var spinner = $( '#msim-scan-spinner' );
			scanBtn.disabled = true;
			spinner.classList.add( 'is-active' );

			post( 'msim_scan', {} ).then( function ( response ) {
				scanBtn.disabled = false;
				spinner.classList.remove( 'is-active' );

				if ( ! response.success ) {
					notice( wrap, ( response.data && response.data.message ) || data.i18n.error );
					return;
				}

				currentRunId = response.data.run_id;
				renderReport( response.data );
			} ).catch( function () {
				scanBtn.disabled = false;
				spinner.classList.remove( 'is-active' );
				notice( wrap, data.i18n.error );
			} );
		} );

		runBtn.addEventListener( 'click', function () {
			if ( ! currentRunId || ! window.confirm( data.i18n.confirmRun ) ) {
				return;
			}

			runBtn.disabled = true;

			post( 'msim_run', { run_id: currentRunId } ).then( function ( response ) {
				if ( ! response.success ) {
					runBtn.disabled = false;
					notice( wrap, ( response.data && response.data.message ) || data.i18n.error );
					return;
				}

				cancelBtn.hidden = false;
				pollStatus( currentRunId );
			} ).catch( function () {
				runBtn.disabled = false;
				notice( wrap, data.i18n.error );
			} );
		} );

		cancelBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( data.i18n.confirmCancel ) ) {
				return;
			}

			post( 'msim_cancel', {} ).then( function () {
				window.location.reload();
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( target.classList.contains( 'msim-rollback-btn' ) || target.classList.contains( 'msim-force-rollback-btn' ) ) {
				var force = target.classList.contains( 'msim-force-rollback-btn' );
				if ( ! window.confirm( force ? data.i18n.confirmForce : data.i18n.confirmRollback ) ) {
					return;
				}

				target.disabled = true;
				post( force ? 'msim_rollback_force' : 'msim_rollback', { run_id: target.getAttribute( 'data-run' ) } ).then( function ( response ) {
					if ( ! response.success ) {
						target.disabled = false;
						notice( wrap, ( response.data && response.data.message ) || data.i18n.error );
						return;
					}
					pollStatus( parseInt( target.getAttribute( 'data-run' ), 10 ) );
				} );
			}

			if ( target.classList.contains( 'msim-recover-btn' ) ) {
				target.disabled = true;
				post( 'msim_recover', { run_id: target.getAttribute( 'data-run' ) } ).then( function ( response ) {
					if ( ! response.success ) {
						target.disabled = false;
						notice( wrap, ( response.data && response.data.message ) || data.i18n.error );
						return;
					}
					pollStatus( parseInt( target.getAttribute( 'data-run' ), 10 ) );
				} );
			}
		} );

		// Restore state on page load.
		if ( data.activeRunId ) {
			currentRunId = data.activeRunId;
			pollStatus( data.activeRunId );
		} else if ( data.plannedRunId ) {
			post( 'msim_report', { run_id: data.plannedRunId } ).then( function ( response ) {
				if ( response.success ) {
					renderReport( response.data );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
}() );
