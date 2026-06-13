/**
 * WWU Withdrawal Button — Debug Inspector (vanilla JS, no dependencies).
 *
 * Talks to the /debug/snapshot and /debug/run-tests REST endpoints using the
 * standard X-WP-Nonce header. Polls for new collector entries every 2 seconds
 * and renders the smoke-test report.
 *
 * @package WWU\WithdrawalButton
 */
( function () {
	'use strict';

	var data = window.wwuWbData || {};
	var i18n = data.i18n || {};
	var root = document.querySelector( '.wwu-wb-inspector' );
	if ( ! root || ! data.restUrl ) {
		return;
	}

	var polling = true;
	var since = 0;
	var pollTimer = null;

	/**
	 * Perform a REST request and return a promise resolving to parsed JSON.
	 *
	 * @param {string} path   Endpoint path relative to the namespace.
	 * @param {string} method HTTP method.
	 * @return {Promise<Object>}
	 */
	function api( path, method ) {
		return fetch( data.restUrl + path, {
			method: method || 'GET',
			headers: {
				'X-WP-Nonce': data.restNonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin'
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			// Auto-unwrap the { success, data } envelope.
			return ( json && Object.prototype.hasOwnProperty.call( json, 'data' ) ) ? json.data : json;
		} );
	}

	/**
	 * Escape a string for safe HTML insertion.
	 *
	 * @param {*} value Value to escape.
	 * @return {string}
	 */
	function esc( value ) {
		var div = document.createElement( 'div' );
		div.textContent = ( null === value || undefined === value ) ? '' : String( value );
		return div.innerHTML;
	}

	/**
	 * Append collector entries to the live table.
	 *
	 * @param {Array} entries Entry objects.
	 */
	function renderEntries( entries ) {
		if ( ! entries || ! entries.length ) {
			return;
		}
		var tbody = root.querySelector( '[data-role="entries"]' );
		entries.forEach( function ( entry ) {
			if ( entry.at && entry.at > since ) {
				since = entry.at;
			}
			var tr = document.createElement( 'tr' );
			tr.className = 'wwu-wb-level-' + esc( entry.level );
			tr.innerHTML =
				'<td>' + esc( entry.level ) + '</td>' +
				'<td>' + esc( entry.channel ) + '</td>' +
				'<td>' + esc( entry.event ) + '</td>' +
				'<td><code>' + esc( JSON.stringify( entry.context || {} ) ) + '</code></td>';
			tbody.insertBefore( tr, tbody.firstChild );
		} );
		root.querySelector( '[data-role="entry-count"]' ).textContent = String( tbody.children.length );
	}

	/**
	 * Poll for new entries.
	 */
	function poll() {
		if ( ! polling ) {
			return;
		}
		api( 'debug/snapshot?since=' + encodeURIComponent( since ), 'GET' ).then( function ( snap ) {
			if ( snap && snap.entries ) {
				renderEntries( snap.entries );
			}
		} ).catch( function () {} );
	}

	/**
	 * Render a smoke-test report.
	 *
	 * @param {Object} report Report payload.
	 */
	function renderReport( report ) {
		var target = root.querySelector( '[data-role="test-report"]' );
		if ( ! report || ! report.summary ) {
			target.innerHTML = '<p>' + esc( JSON.stringify( report ) ) + '</p>';
			return;
		}
		var s = report.summary;
		var html = '<p><strong>' + esc( s.pass ) + ' pass</strong>, ' +
			esc( s.fail ) + ' fail, ' + esc( s.skip ) + ' skip (' + esc( s.total ) + ' total)</p>';
		( report.suites || [] ).forEach( function ( suite ) {
			html += '<details open><summary>' + esc( suite.name ) + '</summary><ul>';
			( suite.tests || [] ).forEach( function ( t ) {
				var icon = 'pass' === t.status ? '✓' : ( 'skip' === t.status ? '∅' : '✗' );
				html += '<li class="wwu-wb-test-' + esc( t.status ) + '">' + icon + ' <code>' +
					esc( t.name ) + '</code> — ' + esc( t.output ) + '</li>';
			} );
			html += '</ul></details>';
		} );
		target.innerHTML = html;
	}

	/**
	 * Handle clicks via delegation.
	 */
	root.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-action]' );
		if ( ! btn ) {
			return;
		}
		var action = btn.getAttribute( 'data-action' );

		if ( 'run-suite' === action ) {
			var suite = btn.getAttribute( 'data-suite' ) || 'all';
			root.querySelector( '[data-role="test-report"]' ).textContent = i18n.running || 'Running…';
			api( 'debug/run-tests?suite=' + encodeURIComponent( suite ), 'POST' ).then( renderReport ).catch( function ( e ) {
				root.querySelector( '[data-role="test-report"]' ).textContent = String( e );
			} );
		} else if ( 'snapshot' === action ) {
			api( 'debug/snapshot', 'GET' ).then( function ( snap ) {
				root.querySelector( '[data-role="snapshot"]' ).textContent = JSON.stringify( snap, null, 2 );
			} );
		} else if ( 'copy-snapshot' === action ) {
			var text = root.querySelector( '[data-role="snapshot"]' ).textContent;
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( text );
			}
		} else if ( 'toggle-poll' === action ) {
			polling = ! polling;
			root.querySelector( '[data-role="poll-state"]' ).textContent = polling ? ( i18n.pollOn || 'Polling: on' ) : ( i18n.pollOff || 'Polling: off' );
			btn.textContent = polling ? ( i18n.pause || 'Pause' ) : ( i18n.resume || 'Resume' );
		} else if ( 'clear' === action ) {
			root.querySelector( '[data-role="entries"]' ).innerHTML = '';
			root.querySelector( '[data-role="entry-count"]' ).textContent = '0';
		}
	} );

	// Pause polling when the tab is hidden to save resources.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			polling = false;
		}
	} );

	pollTimer = window.setInterval( poll, 2000 );
	poll();
}() );
