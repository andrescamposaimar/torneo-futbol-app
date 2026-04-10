/**
 * Customizer notification system
 */


(function (api) {

	api.sectionConstructor['newscrunch-customizer-notify-section'] = api.Section.extend(
		{

			// No events for this type of section.
			attachEvents: function () {
			},

			// Always make the section active.
			isContextuallyActive: function () {
				return true;
			}
		}
	);

})( wp.customize );

					jQuery( document ).ready(
						function () {

							jQuery( '.newscrunch-customizer-notify-dismiss-recommended-action' ).click(
								function () {

									var id = jQuery( this ).attr( 'id' ),
									action = jQuery( this ).attr( 'data-action' );
									jQuery.ajax(
										{
											type: 'GET',
											data: {action: 'newscrunch_customizer_notify_dismiss_action', id: id, todo: action},
											dataType: 'html',
											url: newscrunchCustomizercompanionObject.ajaxurl,
											beforeSend: function () {
												jQuery( '#' + id ).parent().append( '<div id="temp_load" style="text-align:center"><img src="' + newscrunchCustomizercompanionObject.base_path + '/images/spinner-2x.gif" /></div>' );
											},
											success: function (data) {
												var container          = jQuery( '#' + data ).parent().parent();
												var index              = container.next().data( 'index' );
												var recommended_sction = jQuery( '#accordion-section-ti_customizer_notify_recomended_actions' );
												var actions_count      = recommended_sction.find( '.newscrunch-customizer-plugin-notify-actions-count' );
												var section_title      = recommended_sction.find( '.section-title' );
												jQuery( '.newscrunch-customizer-plugin-notify-actions-count .current-index' ).text( index );
												container.slideToggle().remove();
												if (jQuery( '.newscrunch-theme-recomended-actions_container > .epsilon-recommended-actions' ).length === 0) {

													actions_count.remove();

													if (jQuery( '.newscrunch-theme-recomended-actions_container > .epsilon-recommended-plugins' ).length === 0) {
														jQuery( '.control-section-ti-customizer-notify-recomended-actions' ).remove();
													} else {
														section_title.text( section_title.data( 'plugin_text' ) );
													}

												}
											},
											error: function (jqXHR, textStatus, errorThrown) {
												console.log( jqXHR + ' :: ' + textStatus + ' :: ' + errorThrown );
											}
										}
									);
								}
							);

										jQuery( '.newscrunch-customizer-notify-dismiss-button-recommended-plugin' ).click(
											function () {
												var id = jQuery( this ).attr( 'id' ),
												action = jQuery( this ).attr( 'data-action' );
												jQuery.ajax(
													{
														type: 'GET',
														data: {action: 'ti_customizer_notify_dismiss_recommended_plugins', id: id, todo: action},
														dataType: 'html',
														url: newscrunchCustomizercompanionObject.ajaxurl,
														beforeSend: function () {
															jQuery( '#' + id ).parent().append( '<div id="temp_load" style="text-align:center"><img src="' + newscrunchCustomizercompanionObject.base_path + '/images/spinner-2x.gif" /></div>' );
														},
														success: function (data) {
															var container = jQuery( '#' + data ).parent().parent();
															var index     = container.next().data( 'index' );
															jQuery( '.newscrunch-customizer-plugin-notify-actions-count .current-index' ).text( index );
															container.slideToggle().remove();

															if (jQuery( '.newscrunch-theme-recomended-actions_container > .epsilon-recommended-plugins' ).length === 0) {
																jQuery( '.control-section-ti-customizer-notify-recomended-section' ).remove();
															}
														},
														error: function (jqXHR, textStatus, errorThrown) {
															console.log( jqXHR + ' :: ' + textStatus + ' :: ' + errorThrown );
														}
													}
												);
											}
										);

										// Remove activate button and replace with activation in progress button.
										jQuery( document ).on(
											'DOMNodeInserted','.activate-now', function () {
												var activateButton = jQuery( '.activate-now' );
												if (activateButton.length) {
													var url = jQuery( activateButton ).attr( 'href' );
													if (typeof url !== 'undefined') {
														// Request plugin activation.
														jQuery.ajax(
															{
																beforeSend: function () {
																	jQuery( activateButton ).replaceWith( '<a class="button updating-message">' + newscrunchCustomizercompanionObject.activating_string + '...</a>' );
																},
																async: true,
																type: 'GET',
																url: url,
																success: function () {
																	// Reload the page.
																	location.reload();
																}
															}
														);
													}
												}
											}
										);
						}
					);
					
					
					
/**
 * Remove activate button and replace with activation in progress button.
 *
 * @package Newscrunch
 */


jQuery( document ).ready(
	function ($) {
		$( 'body' ).on(
			'click', ' .newscrunch-install-plugin ', function () {
				var slug = $( this ).attr( 'data-slug' );

				wp.updates.installPlugin(
					{
						slug: slug
					}
				);
				return false;
			}
		);

		$( '.activate-now' ).on(
			'click', function (e) {

				var tname = $(this).attr('class');
				var array = tname.split(" ");
				
				var activateButton = $( this );
				e.preventDefault();
				var slug = activateButton.data('slug');
		        var url = activateButton.attr('href');
		        var pathname = window.location.pathname.split( '/' );
		        var newURL1 = window.location.protocol + "//" + window.location.host + "/" + pathname[1]+ "/" + pathname[2];
		        var newURL2= newURL1 + "/admin.php?page="+array[0]+"-welcome";

        
				if ($( activateButton ).length) {
					var url = $( activateButton ).attr( 'href' );

					if (typeof url !== 'undefined') {
						// Request plugin activation.
						$.ajax(
							{
								beforeSend: function () {
									$( activateButton ).replaceWith( '<a class="button updating-message">'+"activating"+'...</a>' );
								},
								async: true,
								type: 'GET',
								url: url,
								success: function(data) {
                    if(slug=='spice-starter-sites'){
                        window.location.href = newURL2;
                    }else{
                        location.reload();
                    }
                }
							}
						);
					}
				}
			}
		);
	}
);