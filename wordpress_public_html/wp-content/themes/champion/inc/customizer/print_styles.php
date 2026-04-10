<?php


function champion_customizer_print_style() {
	$base_color                = ( get_theme_mod( 'base_color' ) == '' ) ? '#d61919' : get_theme_mod( 'base_color' );
	$bg_color                  = ( get_theme_mod( 'bg_color' ) == '' ) ? '#f5f5f5' : get_theme_mod( 'bg_color' );
	$bg_content_color          = ( get_theme_mod( 'bg_content_color' ) == '' ) ? '#f5f5f5' : get_theme_mod( 'bg_content_color' );
	$bg_top_nav_color          = ( get_theme_mod( 'bg_top_nav_color' ) == '' ) ? '#f5f5f5' : get_theme_mod( 'bg_top_nav_color' );
	$bg_footer_color           = ( get_theme_mod( 'bg_footer_color' ) == '' ) ? '#fff' : get_theme_mod( 'bg_footer_color' );
	$copyright_color           = ( get_theme_mod( 'copyright_color' ) == '' ) ? '#868686' : get_theme_mod( 'copyright_color' );
	$bg_custom_image           = get_theme_mod( 'bg_custom_image' );
	$general_font_family       = ( get_theme_mod( 'general_font_family' ) == '' ) ? 'Arial, sans-serif' : get_theme_mod( 'general_font_family' );
	$heading_font_family       = ( get_theme_mod( 'heading_font_family' ) == '' ) ? 'Oswald, Arial, sans-serif' : get_theme_mod( 'heading_font_family' );
	$logo_font_size            = ( get_theme_mod( 'logo_font_size' ) == '' ) ? '20' : get_theme_mod( 'logo_font_size' );
	$heading_color             = ( get_theme_mod( 'heading_color' ) == '' ) ? '#252c33' : get_theme_mod( 'heading_color' );
	$heading_font_weight       = ( get_theme_mod( 'heading_font_weight' ) == '' ) ? '400' : get_theme_mod( 'heading_font_weight' );
	$h1_font_size              = ( get_theme_mod( 'h1_font_size' ) == '' ) ? '60' : get_theme_mod( 'h1_font_size' );
	$h2_font_size              = ( get_theme_mod( 'h2_font_size' ) == '' ) ? '30' : get_theme_mod( 'h2_font_size' );
	$h3_font_size              = ( get_theme_mod( 'h3_font_size' ) == '' ) ? '20' : get_theme_mod( 'h3_font_size' );
	$h4_font_size              = ( get_theme_mod( 'h4_font_size' ) == '' ) ? '17' : get_theme_mod( 'h4_font_size' );
	$h5_font_size              = ( get_theme_mod( 'h5_font_size' ) == '' ) ? '15' : get_theme_mod( 'h5_font_size' );
	$h6_font_size              = ( get_theme_mod( 'h6_font_size' ) == '' ) ? '14' : get_theme_mod( 'h6_font_size' );
	$body_font_color           = ( get_theme_mod( 'body_font_color' ) == '' ) ? '#252c33' : get_theme_mod( 'body_font_color' );
	$body_font_size            = ( get_theme_mod( 'body_font_size' ) == '' ) ? '14' : get_theme_mod( 'body_font_size' );

	?>
	<style type="text/css">
		body {
			background-color: <?php echo esc_attr($bg_color); ?>;
			font-family: <?php echo esc_attr( $general_font_family ); ?>;
			font-size: <?php echo esc_attr( $body_font_size ); ?>px;
			color: <?php echo esc_attr( $body_font_color ); ?>;
			<?php if(!empty($bg_custom_image)){ ?>
				background-image: url("<?php echo esc_url( $bg_custom_image ); ?>");
			<?php } ?>
		}

		#wrapper {
			background-color: <?php echo esc_attr( $bg_content_color ); ?>;
		}

		.pre_top_nav,
		.wpml_switcher ul
		{
			background-color: <?php echo esc_attr( $bg_top_nav_color ); ?>;
		}

		.footer {
			background-color: <?php echo esc_attr( $bg_footer_color ); ?>;
		}

		.footer .copyright {
			color: <?php echo esc_attr( $copyright_color ); ?>;
		}

		.navbar-brand{
			font-size: <?php echo esc_attr( $logo_font_size ); ?>px;
		}

		h1, .h1,
		h2, .h2,
		h3, .h3,
		h4, .h4,
		h5, .h5,
		h6, .h6,
		.entry-title,
		.page-title
		{
			color: <?php echo esc_attr( $heading_color ); ?>;
			font-weight: <?php echo esc_attr( $heading_font_weight ); ?>;
			<?php if ( strpos( $heading_font_weight, 'italic' ) ) { ?>
				font-style: italic;
			<?php } ?>
            font-family: <?php echo esc_attr( $heading_font_family ); ?>;
		}

		h1, .h1{
			font-size: <?php echo esc_attr( $h1_font_size ); ?>px;
		}

		h2, .h2{
			font-size: <?php echo esc_attr( $h2_font_size ); ?>px;
		}

		h3, .h3{
			font-size: <?php echo esc_attr( $h3_font_size ); ?>px;
		}

		h4, .h4{
			font-size: <?php echo esc_attr( $h4_font_size ); ?>px;
		}

		h5, .h5{
			font-size: <?php echo esc_attr( $h5_font_size ); ?>px;
		}

		h6, .h6{
			font-size: <?php echo esc_attr( $h6_font_size ); ?>px;
		}
		
		.countdown span small,
		.widget_sp_event_blocks .sp-event-blocks .event-time,
		.upcoming_events .event_date .date span
		{
			font-family: <?php echo esc_attr( $general_font_family ); ?>;
		}
		
		.btn,
		body .vc_column_container .vc_btn, body .vc_column_container .wpb_button,
		#wrapper .ts-newsticker-oneliner .header,
		.sp-data-table tbody td,
		.vc_upcoming_fixtures .commands .command_vs,
		.countdown span,
		.vc_next_match .command_vs,
		.vc_league_table .sp-view-all-link,
		.add_to_cart_button,
		.woocommerce ul.products li.product .price, .woocommerce-page ul.products li.product .price,
		.woocommerce ul.products li.product h3, .woocommerce-page ul.products li.product h3,
		.title_block_module h3,
		body .ivan-projects .entry-inner .excerpt,
		.player_gallery .player_info .position,
		.player_gallery .player_info .number,
		.footer_widget .widget_title,
		.footer_menu li,
		.fixture_detail .command_info .score,
		.widget_title,
		.check li, .angle li, .asterisk li,
		.nav-tabs>li>a,
		.upcoming_events .commands h3,
		.upcoming_events .event_date .date,
		.fixture_detail.future .command_left .command_info .score:before,
		.player_detail .player_info table th,
		.player_detail .player_info table td,
		.woocommerce #content div.product form.cart .button.single_add_to_cart_button, .woocommerce div.product form.cart .button, .woocommerce-page #content div.product form.cart .button.single_add_to_cart_button, .woocommerce-page div.product form.cart .button.single_add_to_cart_button,
		.woocommerce #content .quantity input.qty, .woocommerce .quantity input.qty, .woocommerce-page #content .quantity input.qty, .woocommerce-page .quantity input.qty,
		.woocommerce #content div.product .woocommerce-tabs ul.tabs li a, .woocommerce div.product .woocommerce-tabs ul.tabs li a, .woocommerce-page #content div.product .woocommerce-tabs ul.tabs li a, .woocommerce-page div.product .woocommerce-tabs ul.tabs li a,
		.comment-info cite,
		.comment-form-rating label,
		.woocommerce .woocommerce-error, .woocommerce .woocommerce-info, .woocommerce .woocommerce-message, .woocommerce-page .woocommerce-error, .woocommerce-page .woocommerce-info, .woocommerce-page .woocommerce-message, .woocommerce-checkout-info,
		.woocommerce table.shop_table th, .woocommerce-page table.shop_table th,
		.woocommerce #content table.cart td.product-name h4, .woocommerce table.cart td.product-name h4, .woocommerce-page #content table.cart td.product-name h4, .woocommerce-page table.cart td.product-name h4,
		.woocommerce table.shop_table td.product-price, .woocommerce-page table.shop_table td.product-price, .woocommerce table.shop_table td.product-subtotal, .woocommerce-page table.shop_table td.product-subtotal,
		.woocommerce .cart .button, .woocommerce .cart input.button, .woocommerce-page .cart .button, .woocommerce-page .cart input.button,
		.woocommerce .cart-collaterals .cart_totals table th, .woocommerce-page .cart-collaterals .cart_totals table th,
		.woocommerce .cart-collaterals .cart_totals table td, .woocommerce-page .cart-collaterals .cart_totals table td,
		.woocommerce-billing-fields h3, .woocommerce-shipping-fields h3,
		#order_review_heading,
		.woocommerce #order_review table.shop_table th, .woocommerce #order_review table.shop_table td, .woocommerce-page #order_review table.shop_table th, .woocommerce-page #order_review table.shop_table td,
		.woocommerce #payment ul.payment_methods li label, .woocommerce-page #payment ul.payment_methods li,
		.woocommerce #payment #place_order, .woocommerce-page #payment #place_order
		{
			font-family: <?php echo esc_attr( $heading_font_family ); ?>;
		}
		
		body .vc_custom_heading h2,
		.wpb_content_element .wpb_tabs_nav li a,
		body .wpb_content_element .wpb_tour_tabs_wrapper .wpb_tabs_nav a, body .wpb_content_element .wpb_accordion_header a,
		body .ivan-projects .entry-inner h3
		{
			font-family: <?php echo esc_attr( $heading_font_family ); ?> !important;
		}
		
		
		.tp-button.red:hover, .purchase.red:hover,
		body #main .rev_slider_wrapper .rev_slider .tp-loader.spinner1,
		#reset_customizer,
		.fixture_detail h3:after, .latest_result > .fixture_detail > h2:after, .vc_latest_result .fixture_detail > h2:after,
		.fixture_detail .command_info .score,
		.btn-danger:hover, .woocommerce #payment #place_order:hover, .woocommerce-page #payment #place_order:hover, body .vc_btn-juicy_pink:hover,
		.vc_next_match .title,
		.vc_upcoming_fixtures .title,
		body .wpb_content_element .wpb_tabs_nav li.ui-tabs-active a,
		body .wpb_content_element .wpb_tabs_nav li a:hover,
		.sp-sortable-table thead th:hover,
		.owl-controls .owl-buttons div:hover, .owl-controls .owl-page:hover,
		.add_to_cart_button,
		.woocommerce ul.products li.product .product_footer:hover .price, .woocommerce-page ul.products li.product .product_footer:hover .price,
		.player_gallery .player_info .number,
		.pagination > li > a:hover, .pagination > li > span:hover, .pagination > li > a:focus, .pagination > li > span:focus, .pagination > .active > a, .pagination > .active > span, .pagination > .active > a:hover, .pagination > .active > span:hover, .pagination > .active > a:focus, .pagination > .active > span:focus, .pagination span.current,
		.widget_categories > ul > li > a:hover, .widget_archive li:hover, .widget_pages > ul > li > a:hover, .widget_meta li a:hover, .widget_recent_entries li a:hover, .widget_nav_menu ul.menu > li > a:hover, .widget_product_categories > ul > li > a:hover,
		.wpb_content_element .wpb_tabs_nav li.ui-tabs-active a,
		body .wpb_accordion.skin_2 .ui-accordion-header-active a,
		body .wpb_accordion.skin_2 .wpb_accordion_header a:hover,
		.bx-wrapper .bx-controls-direction a:hover,
		.nav-tabs>li.active>a, .nav-tabs>li.active>a:hover, .nav-tabs>li.active>a:focus,
		.nav>li>a:hover, .nav>li>a:focus,
		.woocommerce #content div.product form.cart .button.single_add_to_cart_button:hover, .woocommerce div.product form.cart .button:hover, .woocommerce-page #content div.product form.cart .button.single_add_to_cart_button:hover, .woocommerce-page div.product form.cart .button.single_add_to_cart_button:hover,
		.woocommerce #content .quantity .minus:hover, .woocommerce #content .quantity .plus:hover, .woocommerce .quantity .minus:hover, .woocommerce .quantity .plus:hover, .woocommerce-page #content .quantity .minus:hover, .woocommerce-page #content .quantity .plus:hover, .woocommerce-page .quantity .minus:hover, .woocommerce-page .quantity .plus:hover,
		.woocommerce .woocommerce-error, .woocommerce .woocommerce-info, .woocommerce .woocommerce-message, .woocommerce-page .woocommerce-error, .woocommerce-page .woocommerce-info, .woocommerce-page .woocommerce-message, .woocommerce-checkout-info,
		.woocommerce #content div.product .woocommerce-tabs ul.tabs li.active, .woocommerce div.product .woocommerce-tabs ul.tabs li.active, .woocommerce-page #content div.product .woocommerce-tabs ul.tabs li.active, .woocommerce-page div.product .woocommerce-tabs ul.tabs li.active, .woocommerce #content div.product .woocommerce-tabs ul.tabs li:hover, .woocommerce div.product .woocommerce-tabs ul.tabs li:hover, .woocommerce-page #content div.product .woocommerce-tabs ul.tabs li:hover, .woocommerce-page div.product .woocommerce-tabs ul.tabs li:hover
		{
			background: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.upcoming_events ul li:hover .event_date,
		.upcoming_events ul li .btn:hover,
		.upcoming_events ul li:hover .btn,
		.woocommerce .cart .checkout-button, .woocommerce .cart input.checkout-button, .woocommerce-page .cart .checkout-button, .woocommerce-page .cart input.checkout-button,
		.woocommerce .woocommerce-error li, .woocommerce-page .woocommerce-error li,
		.woocommerce .woocommerce-error, .woocommerce .woocommerce-info, .woocommerce .woocommerce-message, .woocommerce-page .woocommerce-error, .woocommerce-page .woocommerce-info, .woocommerce-page .woocommerce-message, .woocommerce-checkout-info,
		.customizer_option_button.active
		{
			background: <?php echo champion_filtered_output($base_color); ?>;
		}
		
		.tp-caption .post_title,
		.tp-bullets.simplebullets .bullet:hover, .tp-bullets.simplebullets .bullet.selected,
		.tp-leftarrow.round:hover, .tp-rightarrow.round:hover,
		.ivan-projects .with-lightbox .ivan-project-inner .thumbnail:hover:after
		{
			background: <?php echo champion_hex2rgba($base_color, '0.9'); ?> !important;
		}
		
		.tp-bannertimer{
			background: <?php echo champion_hex2rgba($base_color, '0.5'); ?> !important;
		}
		
		.tp-button.red, .purchase.red,
		.btn-danger, .woocommerce #payment #place_order, .woocommerce-page #payment #place_order, body .vc_btn-juicy_pink,
		.woocommerce #content div.product form.cart .button.single_add_to_cart_button, .woocommerce div.product form.cart .button, .woocommerce-page #content div.product form.cart .button.single_add_to_cart_button, .woocommerce-page div.product form.cart .button.single_add_to_cart_button
		{
			background: <?php echo champion_hex2rgba($base_color, '0.9'); ?> !important;
		}
		
		.tp-bullets.simplebullets .bullet:hover, .tp-bullets.simplebullets .bullet.selected,
		.tp-leftarrow.round:hover, .tp-rightarrow.round:hover,
		.fixture_detail .goals h2,
		.vc_next_match .commands,
		.countdown span,
		.vc_upcoming_fixtures .commands,
		.owl-controls .owl-buttons div:hover, .owl-controls .owl-page:hover,
		.woocommerce ul.products li.product a:hover img, .woocommerce-page ul.products li.product a:hover img,
		.btn-info.red,
		.bx-wrapper .bx-controls-direction a:hover,
		.upcoming_events ul li .btn:hover,
		.upcoming_events ul li:hover .btn,
		.woocommerce .cart .button:hover, .woocommerce .cart input.button:hover, .woocommerce-page .cart .button:hover, .woocommerce-page .cart input.button:hover,
		.woocommerce .cart .checkout-button, .woocommerce .cart input.checkout-button, .woocommerce-page .cart .checkout-button, .woocommerce-page .cart input.checkout-button,
		.customizer_option_button.active,
		#frontend_customizer #background_images ul li a.active
		{
			border-color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.fixture_detail .command_info .score:after,
		blockquote
		{
			border-left-color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.fixture_detail .command_right .command_info .score:after{
			border-right-color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.add_to_cart_button:after,
		.player_gallery .player_info .number:after
		{
			border-top-color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.product_footer:hover .add_to_cart_button:before,
		.title_block_module h2:after,
		body .ivan-image-block .thumbnail:after
		{
			border-bottom-color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		.upcoming_events .event_date .date
		{
			border-bottom-color: <?php echo champion_filtered_output($base_color); ?>;
		}
		
		#wrapper .ts-newsticker-oneliner .ts-newsticker-datetime,
		#wrapper .ts-newsticker-oneliner .ts-newsticker-datetime:before,
		#wrapper .ts-newsticker-oneliner .header,
		.base_color-color,
		.latest_result h3, .vc_latest_result h3,
		.fixture_detail .goals h2 a:hover,
		.fixture_detail h4,
		.fixture_detail .players li span,
		.fixture_detail .fixture_info .venue,
		article .sp-data-table tbody tr.red td.data-rank,
		.woocommerce .product_info .star-rating,
		.woocommerce .product_header h3 a:hover,
		.woocommerce .product_header .reviews:hover,
		.posts_list > article .post_info .comments_num a:hover,
		.title_block_module h3,
		.like_button:hover .fa,
		.player_gallery.players_carousel h4 a:hover,
		.player_gallery .like_button.disabled .fa,
		.footer_menu li a:hover, .footer_menu li.current-menu-item a,
		.vc_league_table .sp-view-all-link:hover,
		.sp-data-table tbody td a:hover,
		.vc_upcoming_fixtures .command h5 a:hover,
		.vc_next_match .command h5 a:hover,
		body .ivan-project-inner .entry h3 a:hover,
		body .wpb_accordion.skin_1 .ui-accordion-header-active a,
		body .wpb_accordion.skin_1 .ui-accordion-header-active a:hover,
		body .wpb_accordion.skin_1 .ui-accordion-header-active span,
		body .wpb_accordion.skin_1 .ui-accordion-header-active:hover span,
		body .wpb_accordion.skin_1 .wpb_accordion_header a:hover,
		body .wpb_accordion.skin_1 .wpb_accordion_header:hover span,
		.player_detail .player_info a:hover
		{
			color: <?php echo champion_filtered_output($base_color); ?> !important;
		}
		
		a:hover, a:focus,
		.entry-meta .entry-author span,
		.entry-meta .comments-link .fa,
		.entry-meta .comments-link a:hover,
		.author_name,
		.comment-reply-title:before,
		.widget_mailchimp .success_message,
		.widget_mailchimp .required,
		.btn.red .fa,
		.btn-info.red,
		ul.circle li:before, ol.circle li:before,
		blockquote.quote:before, .quote:before,
		.check li:after,
		.upcoming_events .commands h3 span,
		.fixture_detail.future .command_left .command_info .score:before,
		.tp-caption .shoptitle span, .shoptitle span, .shoptitleblack span,
		.woocommerce #content div.product .summary p.price, .woocommerce #content div.product .summary span.price, .woocommerce div.product .summary p.price, .woocommerce div.product .summary span.price, .woocommerce-page #content div.product .summary p.price, .woocommerce-page #content div.product .summary span.price, .woocommerce-page div.product .summary p.price, .woocommerce-page div.product .summary span.price,
		.woocommerce .woocommerce-product-rating .woocommerce-review-link:hover, .woocommerce-page .woocommerce-product-rating .woocommerce-review-link:hover,
		.woocommerce .woocommerce-product-rating .star-rating, .woocommerce-page .woocommerce-product-rating .star-rating,
		.woocommerce .product_comments .star-rating, .woocommerce-page .product_comments .star-rating,
		.product_comments .comment-info .star-rating span,
		.woocommerce #content div.product .woocommerce-tabs .panel .panel_title span, .woocommerce div.product .woocommerce-tabs .panel .panel_title span, .woocommerce-page #content div.product .woocommerce-tabs .panel .panel_title span, .woocommerce-page div.product .woocommerce-tabs .panel .panel_title span,
		.woocommerce table.shop_table td.product-subtotal, .woocommerce-page table.shop_table td.product-subtotal,
		.woocommerce #content table.cart td.product-name a:hover, .woocommerce table.cart td.product-name a:hover, .woocommerce-page #content table.cart td.product-name a:hover, .woocommerce-page table.cart td.product-name a:hover,
		.woocommerce #content table.cart a.remove:hover, .woocommerce table.cart a.remove:hover, .woocommerce-page #content table.cart a.remove:hover, .woocommerce-page table.cart a.remove:hover,
		.woocommerce .cart .button:hover, .woocommerce .cart input.button:hover, .woocommerce-page .cart .button:hover, .woocommerce-page .cart input.button:hover,
		.woocommerce .cart-collaterals .cart_totals table tr.order-total td strong, .woocommerce-page .cart-collaterals .cart_totals table tr.order-total td strong,
		.woocommerce form .form-row .required, .woocommerce-page form .form-row .required,
		.woocommerce #order_review table.shop_table .product-quantity, .woocommerce-page #order_review table.shop_table .product-quantity,
		.woocommerce #order_review table.shop_table tfoot .order-total .amount, .woocommerce-page #order_review table.shop_table tfoot .order-total .amount
		{
			color: <?php echo champion_filtered_output($base_color); ?>;
		}
		
		

	</style>

<?php
}


add_action( 'wp_head', 'champion_customizer_print_style', 500, 0 );

function champion_hex2rgba($color, $opacity = false) {

	$default = 'rgb(0,0,0)';

	if(empty($color))
          return $default; 

    if ($color[0] == '#' ) {
    	$color = substr( $color, 1 );
    }

    if (strlen($color) == 6) {
            $hex = array( $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] );
    } elseif ( strlen( $color ) == 3 ) {
            $hex = array( $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] );
    } else {
            return $default;
    }

    $rgb =  array_map('hexdec', $hex);

    if($opacity){
    	if(abs($opacity) > 1)
    		$opacity = 1.0;
    	$output = 'rgba('.implode(",",$rgb).','.$opacity.')';
    } else {
    	$output = 'rgb('.implode(",",$rgb).')';
    }

    return $output;
}