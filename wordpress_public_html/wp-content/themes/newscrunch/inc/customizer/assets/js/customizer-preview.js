jQuery( document ).ready(function($) {

	// Change the width of logo
	wp.customize('newscrunch_logo_length', function(control) {

		control.bind(function( controlValue ) {
			$('.custom-logo, .dark-custom-logo ').css('max-width', '500px');
			$('.custom-logo, .dark-custom-logo').css('width', controlValue + 'px');
			$('.custom-logo, .dark-custom-logo').css('height', 'auto');
		});

	});
	// Change scroll to top border radius
	wp.customize('scroll_to_top_button_radious', function(control) {

        control.bind(function( borderRadius ) {
            $('.scroll-up a').css('border-radius', borderRadius + 'px');  
        });

    });
	// Change site title
  	wp.customize( 'blogname', function( value ) {
		value.bind( function( to ) {
			$( '.site-title a' ).text( to );
		} );
	} );
	// Change site tagline
	wp.customize( 'blogdescription', function( value ) {
		value.bind( function( to ) {
			$( '.site-description' ).text( to );
		} );
	} );
	// Change highlight title
	wp.customize( 'news_highlight_title', function( value ) {
		value.bind( function( to ) {
			$( '.spnc-highlights-1 .spnc-highlights-title h3' ).text( to );
		} );
	} );
	// Change video title
	wp.customize( 'featured_video_title', function( value ) {
		value.bind( function( to ) {
			$( '.spnc-video .spnc-blog-1-heading h4' ).text( to );
		} );
	} );
	// Change copyright
	wp.customize( 'footer_copyright', function( value ) {
		value.bind( function( to ) {
			$( '.site-info .copyright-section' ).text( to );
		} );
	} );
	// Change sale badeg
	wp.customize('newscrunch_wc_product_sale_badge_text', function(value) {
    value.bind(function(to) {
        jQuery('.onsale').text(to);
    	});
	});

});