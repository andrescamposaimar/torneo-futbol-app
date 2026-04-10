/**
 * Script for the customizer tabs control focus function.
 *
 * @since    0.1
 * @package Newscrunch

 */

/* global wp */

var newscrunch_customize_tabs_focus = function ( $ ) {
	'use strict';
	$(
		function () {
				var customize = wp.customize;
				$( '.customize-partial-edit-shortcut' ).on(
					'click', function () {
						$( this ).on(
							'click', function() {
								var controlId     = $( this ).attr( 'class' );
								var tabToActivate = '';

								if ( controlId.indexOf( 'widget' ) !== -1 ) {
									tabToActivate = $( '.newscrunch-customizer-tab>.widgets' );
								} else {
									var controlFinalId = controlId.split( ' ' ).pop().split( '-' ).pop();
									tabToActivate      = $( '.newscrunch-customizer-tab>.' + controlFinalId );
								}

								customize.preview.send( 'tab-previewer-edit', tabToActivate );
							}
						);
					}
				);
		}
	);
};

newscrunch_customize_tabs_focus( jQuery );