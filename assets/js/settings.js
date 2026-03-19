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
			testSection.style.display = ( isSE || isSS ) ? '' : 'none';
		}
	}

	// -------------------------------------------------------------------------
	// AJAX test connection
	// -------------------------------------------------------------------------

	/**
	 * Handle a click on the "Test Connection" button.  Sends an AJAX request
	 * to the server and displays the result inline without reloading the page.
	 * Credentials are read from the current form values so the test works even
	 * before the settings have been saved.
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

		var select = document.getElementById( 'fk_usps_optimizer_settings_carrier' );
		var carrier = select ? select.value : '';
		var optKey = fkUspsOptimizer.settingsKey;

		var data = new FormData();
		data.append( 'action', 'fk_usps_test_connection' );
		data.append( 'nonce', fkUspsOptimizer.nonce );
		data.append( 'carrier', carrier );

		// Pass credentials from the current form so the test works before saving.
		if ( 'shipstation' === carrier ) {
			var ssKey    = document.querySelector( '[name="' + optKey + '[shipstation_api_key]"]' );
			var ssSecret = document.querySelector( '[name="' + optKey + '[shipstation_api_secret]"]' );
			data.append( 'shipstation_api_key', ssKey ? ssKey.value : '' );
			data.append( 'shipstation_api_secret', ssSecret ? ssSecret.value : '' );
		} else {
			var seKey       = document.querySelector( '[name="' + optKey + '[shipengine_api_key]"]' );
			var seCarrierId = document.querySelector( '[name="' + optKey + '[shipengine_carrier_id]"]' );
			data.append( 'shipengine_api_key', seKey ? seKey.value : '' );
			data.append( 'shipengine_carrier_id', seCarrierId ? seCarrierId.value : '' );
		}

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
