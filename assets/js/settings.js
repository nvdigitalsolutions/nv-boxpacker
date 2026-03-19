/* global fkUspsOptimizer */
( function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Carrier field show/hide
	// -------------------------------------------------------------------------

	/**
	 * Show or hide carrier-specific settings rows based on the current carrier
	 * dropdown value.
	 */
	function toggleCarrierFields() {
		var select = document.getElementById( 'fk_usps_optimizer_settings_carrier' );
		if ( ! select ) {
			return;
		}

		var isSE = select.value === 'shipengine';
		var isSS = select.value === 'shipstation';

		document.querySelectorAll( '.fk-shipengine-field' ).forEach( function ( row ) {
			row.style.display = isSE ? '' : 'none';
		} );

		document.querySelectorAll( '.fk-shipstation-field' ).forEach( function ( row ) {
			row.style.display = isSS ? '' : 'none';
		} );

		var testSection = document.getElementById( 'fk-usps-test-connection' );
		if ( testSection ) {
			testSection.style.display = isSE ? '' : 'none';
		}
	}

	// -------------------------------------------------------------------------
	// AJAX test connection
	// -------------------------------------------------------------------------

	/**
	 * Handle a click on the "Test Connection" button.  Sends an AJAX request
	 * to the server and displays the result inline without reloading the page.
	 *
	 * @param {Event} event The click event.
	 */
	function handleTestConnection( event ) {
		event.preventDefault();

		var button = document.getElementById( 'fk-usps-test-btn' );
		var result = document.getElementById( 'fk-usps-test-result' );

		if ( ! button || ! result ) {
			return;
		}

		button.disabled = true;
		result.className = 'notice notice-info inline';
		result.style.display = '';
		result.querySelector( 'p' ).textContent = fkUspsOptimizer.testing;

		var data = new FormData();
		data.append( 'action', 'fk_usps_test_connection' );
		data.append( 'nonce', fkUspsOptimizer.nonce );

		fetch( fkUspsOptimizer.ajaxUrl, {
			method: 'POST',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var testPassed = json.success && json.data && json.data.success;
				result.className = 'notice ' + ( testPassed ? 'notice-success' : 'notice-error' ) + ' inline';
				result.querySelector( 'p' ).textContent =
					json.data && json.data.message ? json.data.message : fkUspsOptimizer.error;
			} )
			.catch( function () {
				result.className = 'notice notice-error inline';
				result.querySelector( 'p' ).textContent = fkUspsOptimizer.error;
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	// -------------------------------------------------------------------------
	// Initialise on DOMContentLoaded
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.getElementById( 'fk_usps_optimizer_settings_carrier' );
		if ( select ) {
			toggleCarrierFields();
			select.addEventListener( 'change', toggleCarrierFields );
		}

		var btn = document.getElementById( 'fk-usps-test-btn' );
		if ( btn ) {
			btn.addEventListener( 'click', handleTestConnection );
		}
	} );
}() );
