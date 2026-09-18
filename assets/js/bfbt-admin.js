/* BlueFairy Brew Tracker — admin: show the fieldset for the chosen entry / task type. */
( function () {
	'use strict';

	function sync( select ) {
		var target = select.getAttribute( 'data-target' );
		var type = select.value;
		var sets = document.querySelectorAll( '.bfbt-type-fields[data-for="' + target + '"]' );

		Array.prototype.forEach.call( sets, function ( set ) {
			var active = set.getAttribute( 'data-type' ) === type;

			set.classList.toggle( 'bfbt-active', active );

			// Inputs in a hidden fieldset must not post, so two fieldsets can
			// share a field name (temp_c on gravity and on temperature).
			Array.prototype.forEach.call( set.querySelectorAll( 'input, select, textarea' ), function ( input ) {
				input.disabled = ! active;
			} );
		} );
	}

	function init() {
		var switches = document.querySelectorAll( '.bfbt-type-switch' );

		Array.prototype.forEach.call( switches, function ( select ) {
			select.addEventListener( 'change', function () {
				sync( select );
			} );
			sync( select );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
