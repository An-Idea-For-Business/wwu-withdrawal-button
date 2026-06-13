/**
 * WWU Withdrawal Button — frontend two-step controller (vanilla JS).
 *
 * Step 1 submits the withdrawal statement and receives a single-use confirmation
 * token; Step 2 confirms. Talks to the wwu-wb REST endpoints with X-WP-Nonce.
 * Progressive: with JS disabled, the form shows a noscript fallback.
 *
 * @package WWU\WithdrawalButton
 */
( function () {
	'use strict';

	var data = window.wwuWbData || {};
	var i18n = data.i18n || {};

	if ( ! data.restUrl ) {
		return;
	}

	/**
	 * POST JSON to a withdrawal endpoint, returning the unwrapped data.
	 *
	 * @param {string} path Endpoint path.
	 * @param {Object} body Request body.
	 * @return {Promise<Object>}
	 */
	function post( path, body ) {
		return fetch( data.restUrl + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.restNonce
			},
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					var msg = ( json && json.message ) ? json.message : ( i18n.genericError || 'Error' );
					throw new Error( msg );
				}
				return ( json && Object.prototype.hasOwnProperty.call( json, 'data' ) ) ? json.data : json;
			} );
		} );
	}

	/**
	 * Wire a single withdrawal form wrapper.
	 *
	 * @param {HTMLElement} wrap The .wwu-wb-form-wrap element.
	 */
	function initForm( wrap ) {
		var orderRef = wrap.getAttribute( 'data-order-ref' ) || '';
		var key = wrap.getAttribute( 'data-key' ) || '';
		var accessToken = wrap.getAttribute( 'data-access-token' ) || '';
		var form = wrap.querySelector( '.wwu-wb-form' );
		var step2 = wrap.querySelector( '.wwu-wb-step2' );
		var confirmBtn = wrap.querySelector( '.wwu-wb-confirm' );
		var result = wrap.querySelector( '.wwu-wb-result' );
		var state = { requestUid: '', confirmToken: '' };

		if ( ! form || ! step2 || ! confirmBtn || ! result ) {
			return;
		}

		function readFields() {
			return {
				order_ref: orderRef,
				key: key,
				access_token: accessToken,
				name: ( wrap.querySelector( '[name="name"]' ) || {} ).value || '',
				email: ( wrap.querySelector( '[name="email"]' ) || {} ).value || '',
				reason: ( wrap.querySelector( '[name="reason"]' ) || {} ).value || ''
			};
		}

		function showResult( message, isError ) {
			result.hidden = false;
			result.textContent = message;
			result.className = 'wwu-wb-result' + ( isError ? ' is-error' : ' is-success' );
		}

		// Step 1 — submit the statement.
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var btn = form.querySelector( '.wwu-wb-continue' );
			var fields = readFields();
			if ( btn ) {
				btn.disabled = true;
				btn.textContent = i18n.submitting || 'Submitting…';
			}
			post( 'withdrawal/statement', fields ).then( function ( res ) {
				state.requestUid = res.request_uid;
				state.confirmToken = res.confirm_token;
				form.setAttribute( 'hidden', 'hidden' );
				step2.removeAttribute( 'hidden' );
			} ).catch( function ( err ) {
				showResult( err.message, true );
				if ( btn ) {
					btn.disabled = false;
				}
			} );
		} );

		// Step 2 — confirm.
		confirmBtn.addEventListener( 'click', function () {
			confirmBtn.disabled = true;
			confirmBtn.textContent = i18n.confirming || 'Confirming…';
			var body = readFields();
			body.request_uid = state.requestUid;
			body.confirm_token = state.confirmToken;
			post( 'withdrawal/confirm', body ).then( function () {
				step2.setAttribute( 'hidden', 'hidden' );
				showResult( i18n.confirmed || 'Your withdrawal has been registered.', false );
			} ).catch( function ( err ) {
				showResult( err.message, true );
				confirmBtn.disabled = false;
				confirmBtn.textContent = confirmBtn.getAttribute( 'data-confirm-label' ) || 'Confirm';
			} );
		} );
	}

	/**
	 * Wire the guest lookup form (order number + email → access token → form).
	 *
	 * On success the server returns a token bound to (order_ref, email); we reload
	 * the same page with ?wwu_wb_order & ?access_token so the server renders the
	 * two-step form. On failure we show a single generic message (no enumeration).
	 *
	 * @param {HTMLElement} form The .wwu-wb-lookup form element.
	 */
	function initLookup( form ) {
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var btn = form.querySelector( 'button[type="submit"]' );
			var result = form.querySelector( '.wwu-wb-result' );
			var orderRef = ( form.querySelector( '[name="order_ref"]' ) || {} ).value || '';
			var email = ( form.querySelector( '[name="email"]' ) || {} ).value || '';
			var label = btn ? btn.textContent : '';

			if ( btn ) {
				btn.disabled = true;
				btn.textContent = i18n.submitting || 'Submitting…';
			}

			post( 'withdrawal/lookup', { order_ref: orderRef, email: email } ).then( function ( res ) {
				var url = new URL( window.location.href );
				url.searchParams.set( 'wwu_wb_order', res.order_ref );
				url.searchParams.set( 'access_token', res.access_token );
				window.location.assign( url.toString() );
			} ).catch( function () {
				// Always the same generic message — never reveal which field failed.
				if ( result ) {
					result.hidden = false;
					result.textContent = i18n.lookupFailed || 'If those details match an eligible order, you can continue. Please check and try again.';
					result.className = 'wwu-wb-result is-error';
				}
				if ( btn ) {
					btn.disabled = false;
					btn.textContent = label || i18n.lookupSubmit || 'Find my order';
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wraps = document.querySelectorAll( '.wwu-wb-form-wrap:not(.wwu-wb-lookup)' );
		Array.prototype.forEach.call( wraps, initForm );

		var lookups = document.querySelectorAll( '.wwu-wb-lookup' );
		Array.prototype.forEach.call( lookups, initLookup );
	} );
}() );
