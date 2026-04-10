<?php
/**
 * Use Color Background settings in the theme package
 *
 * @package Newscrunch
*/
function newscrunch_color_back_custom_css() {
    // Get values from the customizer settings
    $newscrunch_enable_date_time_color             =   get_theme_mod('hide_show_date_time_color', true);
    $newscrunch_enable_social_icon_color           =   get_theme_mod('hide_show_social_icon_color', true);
    $newscrunch_enable_site_identity_color         =   get_theme_mod('hide_show_site_identity_color', true);
    $newscrunch_enable_menu_submenu_color          =   get_theme_mod('hide_show_theme_header_color', true);
    $newscrunch_enable_theme_footer_color          =   get_theme_mod('hide_show_theme_footer_color', false);
    $newscrunch_enable_bottom_footer_color         =   get_theme_mod('hide_show_bottom_footer_color', true);
    $hide_show_header_border                       =   get_theme_mod('hide_show_header_border',false);
    $hide_show_header_border_radius                =   get_theme_mod('hide_show_header_border_radius',false);

    /* ====================
        * Date & Time 
    ==================== */
    if($newscrunch_enable_date_time_color == true) { ?>
        <style>
            [data-theme="spnc_light"] .header-sidebar .spnc-left .head-contact-info li.header-date .date {
                color: <?php echo esc_attr( get_theme_mod('date_color', '#') ); ?>;
            }
            [data-theme="spnc_light"] .header-sidebar .spnc-left .head-contact-info li.header-time .time {
                color: <?php echo esc_attr( get_theme_mod('time_color', '#') ); ?>;
            }

            /* css for dark */
            [data-theme="spnc_dark"] .header-sidebar .spnc-left .head-contact-info li.header-date .date {
                color: <?php echo esc_attr( get_theme_mod('date_dcolor', '#') ); ?>;
            }
            [data-theme="spnc_dark"] .newsblogger :is(.header-sidebar.header-1 .spnc-left .head-contact-info li.header-date .date, .header-sidebar.header-2 .spnc-left .head-contact-info li.header-date .date) {
                color: <?php echo esc_attr( get_theme_mod('date_dcolor', '#fff') ); ?>;
            }
            [data-theme="spnc_dark"] .header-sidebar .spnc-left .head-contact-info li.header-time .time {
                color: <?php echo esc_attr( get_theme_mod('time_dcolor', '#') ); ?>;
            }
            [data-theme="spnc_dark"] .newsblogger :is(.header-sidebar.header-1 .spnc-left .head-contact-info li.header-time .time, .header-sidebar.header-2 .spnc-left .head-contact-info li.header-time .time) {
                color: <?php echo esc_attr( get_theme_mod('time_dcolor', '#fff') ); ?>;
            }
        </style>
    <?php }


     /* ====================
        * Category Color 
    ==================== */ 
    if ('NewsBlogger' == wp_get_theme()) {
            $default = "#369ef6";
    }
    else {
        $default = "#669c9b";
    }        
    $newscrunch_query_args = get_terms( 'category', array('hide_empty' => false));
    foreach ( $newscrunch_query_args as $term ) {
        if(!empty($term->count))
            { 
                // old user
                if(!empty(get_theme_mod('newscrunch_category_'.$term->slug))) 
                { 
                    if (preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $term->slug)) { $spnc_cid=$term->term_id; }
                    else { $spnc_cid=$term->slug; }?>
                    <style type="text/css">
                        body.newscrunch #page .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($spnc_cid);?>
                            {
                                background: <?php echo esc_attr( get_theme_mod('newscrunch_category_'.$term->slug, $default) ); ?>;
                            }
                        body .spnc-category-page .spnc-blog-cat-wrapper .spnc-first-catpost .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($spnc_cid); ?>, .spnc-cat-first-post .spnc-post .spnc-post-content .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($spnc_cid); ?>
                                {
                                    color: <?php echo esc_attr( get_theme_mod('newscrunch_category_'.$term->slug, $default) ); ?>;
                                }
                    </style>
                <?php }
                // new user
                else { ?>
                    <style type="text/css">
                        body.newscrunch #page .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($term->term_id);?>
                            {
                                background: <?php echo esc_attr( get_theme_mod('newscrunch_category_'.$term->term_id, $default) ); ?>;
                            }
                        body .spnc-category-page .spnc-blog-cat-wrapper .spnc-first-catpost .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($term->term_id); ?>, .spnc-cat-first-post .spnc-post .spnc-post-content .spnc-cat-links a.newscrunch_category_<?php echo esc_attr($term->term_id); ?>
                                {
                                    color: <?php echo esc_attr( get_theme_mod('newscrunch_category_'.$term->term_id, $default) ); ?>;
                                }
                    </style>
                <?php }
        }
    }

    /* ====================
        * Social Icons
    ==================== */
    if($newscrunch_enable_social_icon_color == true) { ?>
        <style>
            body .header-sidebar .widget .custom-social-icons li a {
                color: <?php echo esc_attr( get_theme_mod('social_icon_color', '#') ); ?>;
                background-color: <?php echo esc_attr( get_theme_mod('social_icon_bg_color', '#') ); ?>;
            }
            body .header-sidebar .widget .custom-social-icons li > a:is(:hover,:focus) {
                color: <?php echo esc_attr( get_theme_mod('social_icon_hover_color', '#') ); ?>;
                background-color: <?php echo esc_attr( get_theme_mod('social_icon_bg_hover_color', '#') ); ?>;
            }
            body.newsblogger .header-sidebar.header-2 .widget .custom-social-icons li > a:is(:hover,:focus) {
                background-color: <?php echo esc_attr( get_theme_mod('social_icon_bg_hover_color', '#fff') ); ?>;
            }
            body.newscrunch-plus .header-sidebar .spnc-date-social.spnc-right .custom-date-social-icons li a {
                color: <?php echo esc_attr(get_theme_mod('social_icon_color','#') );?>;
            }
            body.newscrunch-plus #wrapper .header-sidebar .spnc-date-social.spnc-right .custom-date-social-icons li a:hover {
                color: <?php echo esc_attr(get_theme_mod('social_icon_hover_color','#'));?>;
            }
        </style>
    <?php }

    /* ====================
        * Header section (Site title, Tagline) 
    ==================== */
    if($newscrunch_enable_site_identity_color == true) { ?>
        <style>
            /* Site Title Color */
            body .custom-logo-link-url .site-title a,
            body .header-5 .custom-logo-link-url .site-title a,
            body .header-4 .custom-logo-link-url .site-title a {
                color: <?php echo esc_attr( get_theme_mod('site_title_color', '#') ); ?>;
            }

            /* Site Title Hover Color */
            [data-theme="spnc_light"]  body .custom-logo-link-url .site-title a:hover,
            body .header-5 .custom-logo-link-url .site-title a:hover,
            body .header-4 .custom-logo-link-url .site-title a:hover {
                color: <?php echo esc_attr( get_theme_mod('site_title_hover_color', '#') ); ?>;
            }

            /* Tagline Color */
            body .custom-logo-link-url .site-description,
            body .header-5 .custom-logo-link-url .site-description,
            body .header-4 .custom-logo-link-url .site-description {
                color: <?php echo esc_attr( get_theme_mod('site_tagline_color', '#') ); ?>;
            }

            /* Site Title Color For Dark Layout */
            [data-theme="spnc_dark"] .custom-logo-link-url .site-title a,
            [data-theme="spnc_dark"]  body .header-5 .custom-logo-link-url .site-title a,
            [data-theme="spnc_dark"]  body .header-4 .custom-logo-link-url .site-title a{
                color: <?php echo esc_attr( get_theme_mod('site_title_dcolor', '#') ); ?>;
            }

            /* Site Title Hover Color For Dark Layout */
            [data-theme="spnc_dark"] body .custom-logo-link-url .site-title a:hover,
            [data-theme="spnc_dark"] body .header-5 .custom-logo-link-url .site-title a:hover,
            [data-theme="spnc_dark"] body .header-4 .custom-logo-link-url .site-title a:hover  {
                color: <?php echo esc_attr( get_theme_mod('site_title_hover_dcolor', '#') ); ?>;
            }

            /* Tagline Color For Dark Layout */
            [data-theme="spnc_dark"] .custom-logo-link-url .site-description,
            [data-theme="spnc_dark"] body .header-5 .custom-logo-link-url .site-description,
            [data-theme="spnc_dark"] body .header-4 .custom-logo-link-url .site-description{
                color: <?php echo esc_attr( get_theme_mod('site_tagline_dcolor', '#') ); ?>;
            }
        </style>
    <?php }
    /* ====================
        * Menus and Submenus 
    ==================== */
    if($newscrunch_enable_menu_submenu_color == true) { ?>
        <style>
            body .header-4 .spnc-custom .spnc-nav li > a,body .header-5 .spnc-custom .spnc-nav li > a, body .header-6 .spnc-custom .spnc-nav li > a, body .header-7 .spnc-custom .spnc-nav li > a, body .header-8 .spnc-custom .spnc-nav li > a, body .header-12 .spnc-custom .spnc-nav li > a, [data-theme="spnc_dark"] body .header-12 .spnc-custom .spnc-nav li > a,
            body .spnc-nav > li.parent-menu a, body .spnc-custom .spnc-nav .dropdown.open > a, body .spnc-custom .spnc-nav li > a, [data-theme="spnc_dark"] body .spnc-nav > li.parent-menu a, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown.open > a, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav li > a {
                color: <?php echo esc_attr( get_theme_mod('menu_link_color', '#') ); ?>;
            }
            .header-6 .spnc-custom .spnc-nav li > a:before,[data-theme="spnc_dark"] .header-6 .spnc-custom .spnc-nav li > a:before{
               background-color: <?php echo esc_attr( get_theme_mod('menu_link_color', '#') ); ?>;
            }
            body .spnc-nav > li.parent-menu a:hover, body .spnc-custom .spnc-nav .open > a:hover, body .spnc-custom .spnc-nav .open.active > a:hover,[data-theme="spnc_dark"] body .spnc-nav > li.parent-menu a:hover, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .open > a:hover, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .open.active > a:hover, body.newsblogger .header-12 .spnc-custom .spnc-nav .open > a, [data-theme="spnc_dark"] body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav .open > a {
                    color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;
            }

            [data-theme="spnc_dark"] body .spnc-nav > li.parent-menu a:hover, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown.open > a:hover, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav li > a:hover, [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-collapse .spnc-nav li > a:hover{
                color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;
            }
            body.newscrunch .spnc-custom .spnc-nav > li > a:focus, body.newscrunch .spnc-custom .spnc-nav > li > a:hover, body.newscrunch .spnc-custom .spnc-nav .open > a, body.newscrunch .spnc-custom .spnc-nav .open > a:focus, body.newscrunch .spnc-custom .spnc-nav .open > a:hover,
            [data-theme="spnc_dark"] body.newscrunch .header-4 .spnc-custom .spnc-nav li > a:hover, [data-theme="spnc_dark"] body .header-4 .spnc-custom .spnc-nav .open > a,[data-theme="spnc_dark"] body .header-4 .spnc-custom .spnc-nav .dropdown.open > a:hover,[data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav .open > a,[data-theme="spnc_dark"] body .header-7 .spnc-nav > li.parent-menu a:hover, [data-theme="spnc_dark"] body .header-7 .spnc-custom .spnc-nav .dropdown.open > a:hover,body.newscrunch .spnc-custom .spnc-nav.nav > li > a:hover, body.newscrunch .spnc-custom .spnc-nav.nav > li > a:focus,[data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav.nav > li > a:focus,[data-theme="spnc_dark"] body .spnc-nav > li.parent-menu .dropdown-menu a:focus,.header-6 .spnc-custom .spnc-nav li > a:before, [data-theme="spnc_dark"] .header-12.plus-header .spnc-custom .spnc-nav .open > a, body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav > li > a:is(:hover, :focus) {
                color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;
            }
            .header-6 .spnc-custom .spnc-nav li.open > a:before, [data-theme="spnc_dark"] .header-6 .spnc-custom .spnc-nav li.open > a:before {
                   background-color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;
                   }
            body .spnc-custom .spnc-nav > .active > a, body .spnc-custom .spnc-nav .open .dropdown-menu > .active > a, .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover, .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:focus, .spnc-custom .spnc-nav > .active > a, .spnc-custom .spnc-nav > .active > a:hover, body .spnc-custom .spnc-nav > .active.open > a,body .spnc-custom .spnc-nav > .active > a:hover,[data-theme="spnc_dark"] body #wrapper .spnc-custom .spnc-nav .open .dropdown-menu > .active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .spnc-custom .spnc-nav .open .dropdown-menu > .active > a,[data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover, .newsblogger .spnc-custom .spnc-nav .open .dropdown-menu > .active > a, body.newsblogger .header-12.header-sidebar .spnc.spnc-custom .spnc-nav > .active.menu-item > a,     [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-2 .spnc.spnc-custom .spnc-collapse .spnc-nav li.active > a, body.newsblogger .header-2 .spnc.spnc-custom .spnc-nav > .active > a, .newsblogger .header-2 .spnc-custom .spnc-nav .open .dropdown-menu > .active > a  {
                color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
            }

           body.newscrunch .spnc-custom .spnc-nav > .active > a:hover,body.newscrunch .spnc-custom .spnc-nav > .active > a, body.newscrunch .spnc-custom .spnc-nav > .active > a:focus, body.newscrunch .spnc-custom .spnc-nav > li.parent-menu.active > a:hover, .spnc-custom .spnc-nav li.active > a:hover,body.newscrunch .spnc-custom .spnc-nav .dropdown-menu > .active > a:hover, body.newscrunch .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover,[data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown.open.active > a,[data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover,[data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown.open.active > a:hover,body .spnc-wrapper .header-2 .spnc-custom .spnc-nav > .active > a, body .spnc-wrapper .header-2 .spnc-custom .spnc-nav > .active > a:hover, body .spnc-wrapper .header-2 .spnc-custom .spnc-nav > .active > a:focus,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-nav > li.parent-menu .dropdown-menu li.active > a:hover,[data-theme="spnc_dark"] body #wrapper .header-6 .spnc-custom .spnc-nav > .active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-6 .spnc-custom .spnc-nav > .active.open > a:hover,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-collapse .spnc-nav li.active > a:hover,[data-theme="spnc_dark"] body.newscrunch .header-8 .spnc-custom .spnc-nav > .active > a, body .header-8.header-sidebar .spnc-custom .spnc-collapse .spnc-nav .dropdown-menu li.active > a:hover,body.newscrunch .header-3 .spnc-custom .spnc-nav > .active > a, body.newscrunch .header-3 .spnc-custom .spnc-nav > .active > a:hover, body.newscrunch .header-3 .spnc-custom .spnc-nav > .active > a:focus,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-3 .spnc-custom .spnc-nav > .active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-5 .spnc-custom .spnc-nav > .active > a,body.newscrunch #wrapper .header-5 .spnc-custom .spnc-nav > .active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-6 .spnc-custom .spnc-nav > .active > a,body.newscrunch #wrapper .header-6 .spnc-custom .spnc-nav > .active > a ,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-8 .spnc-custom .spnc-nav > .active > a,body.newscrunch #wrapper .header-8 .spnc-custom .spnc-nav > .active > a,body.newscrunch.newscrunch-plus .header-5 .spnc-nav > li.parent-menu .dropdown-menu .active > a, body .header-12 .spnc-custom .spnc-nav > .active > a, [data-theme="spnc_dark"] body #wrapper .header-12 .spnc-custom .spnc-nav > .active > a, [data-theme="spnc_dark"] body.newsblogger .header-12 .spnc-custom .spnc-nav > .active > a, [data-theme="spnc_dark"] body.newsblogger .spnc-custom .spnc-nav .open .dropdown-menu > .active > a, [data-theme="spnc_dark"] body.newsblogger .header-12 .spnc-custom .spnc-nav .dropdown.open.active > a, [data-theme="spnc_dark"] .newsblogger .spnc-nav > li.parent-menu.dropdown .dropdown-menu .open > a, body.newsblogger .header-12 .spnc-custom .spnc-nav .dropdown.open.active > a, body.newsblogger .spnc-nav > li.parent-menu.dropdown .dropdown-menu .open > a, [data-theme="spnc_dark"] .newsblogger.nchild .header-1 .spnc-nav > li.parent-menu.active > a, body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav > .active > a, [data-theme="spnc_dark"] body.newscrunch.newsblogger #wrapper .header-12.header-sidebar .spnc-custom .collapse.spnc-collapse .spnc-nav li.active > a, body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav .open .dropdown-menu > .active > a, body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:is(:hover, :focus), 

           [data-theme="spnc_dark"] body.newscrunch.newsblogger #wrapper .header-12.header-sidebar .spnc.spnc-custom .spnc-collapse .spnc-nav .dropdown-menu > li.active > a, 
           [data-theme="spnc_dark"] body.newscrunch.newsblogger #wrapper .header-12.header-sidebar .spnc.spnc-custom .spnc-collapse .spnc-nav li.active > a {
                color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
            }
             [data-theme="spnc_dark"] body #wrapper .header-6 .spnc-custom .spnc-nav > .active.open > a:before{background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;}
            .header-6 .spnc-custom .spnc-nav li > a:hover:before{
                background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
            }
            body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li.active > a:after,body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li.active > a:before,body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li.active > a:hover:after,body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li.active > a:hover:before, body.newscrunch .header-2 .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover:after,.header-3 .spnc-custom .spnc-nav li.active > a:after, .header-3 .spnc-custom .spnc-nav li.active > a:before,.header-3 .spnc-custom .spnc-nav li.active > a:hover:after, .header-3 .spnc-custom .spnc-nav li.active > a:hover:before, body.newscrunch .header-3 .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover:after,.header-5 .spnc-custom .spnc-nav li.active > a:before,.header-6 .spnc-custom .spnc-nav .dropdown-menu li.active > a:before,.header-6 .spnc-custom .spnc-nav li.active > a:before,.header-6 .spnc-custom .spnc-nav .dropdown-menu li.active > a:hover:before, .header-6 .spnc-custom .spnc-nav li.active a:hover:before{
               background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
           }
           body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li a:hover:after,body .spnc-wrapper .header-2 .spnc-custom .spnc-nav li a:hover:before,.header-3 .spnc-custom .spnc-nav li a:hover:after, .header-3 .spnc-custom .spnc-nav li a:hover:before, .header-5 .spnc-custom .spnc-nav li a:hover:before,body.newscrunch .header-6 .spnc-custom .spnc-nav li a:hover:before{
               background-color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;
           }
            body .spnc-custom .dropdown-menu, body .spnc-custom .open .dropdown-menu,.header-6 .spnc-custom .spnc-nav .dropdown-menu li > a:before {
                background-color: <?php echo esc_attr( get_theme_mod('submenu_bg_color', '#') ); ?>;
            }
            body .spnc-custom .dropdown-menu > li > a, body .spnc-custom .spnc-nav .open .dropdown-menu > a, body .spnc-custom .spnc-nav .dropdown-menu .open > a,[data-theme="spnc_dark"] body .spnc-custom .dropdown-menu > li > a, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .open .dropdown-menu > a, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown-menu .open > a,[data-theme="spnc_dark"] body .spnc-nav > li.parent-menu .dropdown-menu a,body .header-4 .spnc-custom .dropdown-menu > li > a,body .header-5 .spnc-custom .dropdown-menu > li > a,body .header-6 .spnc-custom .dropdown-menu > li > a , body .header-7 .spnc-custom .dropdown-menu > li > a, body .header-8 .spnc-custom .dropdown-menu > li > a,body.newscrunch .header-5 .spnc-nav > li.parent-menu .dropdown-menu a,
            body .header-12 .spnc-custom .spnc-nav .dropdown-menu li > a,[data-theme="spnc_dark"] .header-12 .spnc-custom .spnc-nav .dropdown-menu li > a{
                color: <?php echo esc_attr( get_theme_mod('submenu_link_color', '#') ); ?>;
                -webkit-text-fill-color: unset;
            }
            .header-6 .spnc-custom .spnc-nav .dropdown-menu li > a:before {
                background-color: <?php echo esc_attr( get_theme_mod('submenu_link_color', '#') ); ?>;
            }
            body .spnc-custom .spnc-nav .dropdown-menu > li > a:hover, body .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover,[data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown-menu > li > a:hover, [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover,[data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown-menu .dropdown.open > a:hover, .header-4 .spnc-custom .spnc-nav .dropdown-menu .open > a:hover,[data-theme="spnc_dark"] body.newscrunch .header-4 .spnc-custom .spnc-nav .dropdown-menu li> a:hover ,.header-5 .spnc-custom .spnc-nav .dropdown-menu .open > a:hover,body.newscrunch .spnc-custom .spnc-nav .open > .dropdown-menu  a:hover,body .header-5 .spnc-custom .spnc-nav .dropdown-menu li > a:hover, body .header-6 .spnc-custom .spnc-nav .dropdown-menu li > a:hover, body .header-7 .spnc-custom .spnc-nav .dropdown-menu li > a:hover,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-nav > li.parent-menu .dropdown-menu li > a:hover,body .header-8.header-sidebar .spnc-custom .spnc-collapse .spnc-nav .dropdown-menu li > a:hover,body.newscrunch .header-5 .spnc-custom .spnc-nav .dropdown-menu li > a:hover, body.newscrunch .header-6 .spnc-custom .spnc-nav .dropdown-menu li > a:hover,body.newscrunch .header-7 .spnc-custom .spnc-nav .dropdown-menu li > a:hover, body.newsblogger .header-12 .spnc-custom .spnc-nav .dropdown-menu > li > a:is(:hover,:focus), body.newsblogger .spnc-custom .spnc-nav .dropdown-menu > li > a:is(:hover,:focus), [data-theme="spnc_dark"] .newsblogger .header-12 .spnc-custom .spnc-nav .dropdown-menu li > a:hover, body.newsblogger .spnc-nav > li.parent-menu.dropdown .dropdown-menu .open > a, [data-theme="spnc_dark"] body.newsblogger .spnc-nav > li.parent-menu.dropdown .dropdown-menu .open > a, body.newsblogger.nchild .header-12 .spnc-custom .spnc-nav .dropdown-menu li > a:is(:hover, :focus)  {
                color: <?php echo esc_attr( get_theme_mod('submenu_link_hover_color', '#') ); ?>;
                -webkit-text-fill-color: unset;
            }
            body .spnc-wrapper .header-2 .spnc-custom .spnc-nav .dropdown-menu li a:hover:after, .header-3 .spnc-custom .spnc-nav .dropdown-menu li a:hover:after,.header-6 .spnc-custom .spnc-nav .dropdown-menu li > a:hover:before{
                background-color: <?php echo esc_attr( get_theme_mod('submenu_link_hover_color', '#') ); ?>;
            }
            @media (max-width: 1100px){
                body.newscrunch .spnc-custom .spnc-nav.nav > li.active > a,
                [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-collapse .spnc-nav li.active > a,
                [data-theme="spnc_dark"] body.newscrunch #wrapper #page .header-sidebar .spnc-custom .spnc-collapse .spnc-nav .dropdown-menu > .active > a,
                [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-2 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-6 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-7 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-8 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch-plus.newscrunch #wrapper .header-sidebar.header-2 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-3 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-4 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-5 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-6 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-7 .spnc-custom .spnc-collapse .spnc-nav li.active > a,[data-theme="spnc_dark"] body.newscrunch.newscrunch-plus #wrapper .header-sidebar.header-8 .spnc-custom .spnc-collapse .spnc-nav li.active > a, [data-theme="spnc_dark"] body.newscrunch.newsblogger #wrapper .header-sidebar.header-12 .spnc-custom .spnc-collapse .spnc-nav li.active > a, [data-theme="spnc_dark"] body.newscrunch #wrapper .header-12.header-sidebar .spnc-custom.bg-color .spnc-collapse .spnc-nav li.active > a {
                     color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
                }
                body.newscrunch .spnc-custom .spnc-nav.nav li > a, body .spnc-custom .spnc-nav.nav li > a,
                [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-collapse .spnc-nav li > a,.header-sidebar.header-8 .spnc-custom .spnc-collapse .spnc-nav li > a{ color: <?php echo esc_attr( get_theme_mod('menu_link_color', '#') ); ?>;
                }
                body.newscrunch .spnc-custom .dropdown-menu > li > a,  body.newscrunch .spnc-nav > li.parent-menu .dropdown-menu a,
                [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-nav > li.parent-menu .dropdown-menu a,.header-sidebar.header-8 .spnc-custom .spnc-collapse .spnc-nav li > .dropdown-menu a,body.newscrunch .header-5 .spnc-nav > li.parent-menu .dropdown-menu a, body.newscrunch .header-7 .spnc-custom .spnc-nav .dropdown-menu li > a, body.newsblogger .spnc-custom .dropdown-menu > li > a,  body.newsblogger .spnc-nav > li.parent-menu .dropdown-menu a, body.newscrunch .header-5 .spnc-custom .spnc-nav > li.parent-menu .dropdown-menu a:not(.dropdown-menu > li.active > a) {
                     color: <?php echo esc_attr( get_theme_mod('submenu_link_color', '#') ); ?>;
                }
                body .spnc-custom .dropdown-menu > li > a:hover,  body .spnc-nav > li.parent-menu .dropdown-menu a:hover{
                    color: <?php echo esc_attr( get_theme_mod('submenu_link_hover_color', '#') ); ?>;
                }
                body .spnc-custom .spnc-nav .open .dropdown-menu > .active > a{
                     color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
                }
                body #wrapper .spnc-custom .spnc-nav.nav .dropdown-menu > .active > a, body  #wrapper .spnc-custom .spnc-nav.nav .dropdown-menu > .active > a:hover, body #wrapper .spnc-custom .spnc-nav.nav .dropdown-menu > .active > a:focus{
                    color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;
                }
                body.newscrunch .spnc-custom .spnc-nav li > a.search-icon{
                   color: #bbb;

                }
            }
            @media (min-width: 1100px){
            body.newscrunch .header-3 .spnc-custom .spnc-nav > .active > a:before,body.newscrunch .header-3 .spnc-custom .spnc-nav > .active > a:after,body.newscrunch .header-3 .spnc-custom .spnc-nav .dropdown-menu > .active > a:before,body.newscrunch .header-3 .spnc-custom .spnc-nav .dropdown-menu > .active > a:after {background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;}
            body.newscrunch .header-3  .spnc-custom .spnc-nav > li > a:before, body.newscrunch .header-3 .spnc-custom .spnc-nav > li > a:after{ background-color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;}
            body.newscrunch .header-5 .spnc-custom .spnc-nav > .active > a:before,body.newscrunch .header-5 .spnc-custom .spnc-nav  > .active > a:hover:before{background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;}
            body.newscrunch .header-5  .spnc-custom .spnc-nav > li > a:hover:before{ background-color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;}
            body.newscrunch .header-6 .spnc-custom .spnc-nav > .active > a:before,body.newscrunch .header-6 .spnc-custom .spnc-nav  > .active > a:hover:before{background-color: <?php echo esc_attr( get_theme_mod('menu_active_link_color', '#') ); ?>;}
            body.newscrunch .header-6  .spnc-custom .spnc-nav > li > a:hover:before{ background-color: <?php echo esc_attr( get_theme_mod('menu_link_hover_color', '#') ); ?>;}
        }

            @media (max-width: 1100px) {
            [data-theme="spnc_light"]  body.newscrunch .header-2 .spnc-custom .spnc-nav li > a.search-icon{color: <?php echo esc_attr( get_theme_mod('search_icon_color', '#') ); ?>;}

            [data-theme="spnc_light"] body.newscrunch .header-4 .spnc-dark-icon{color: <?php echo esc_attr( get_theme_mod('switcher_icon_color', '#') ); ?>;}
            }
            [data-theme="spnc_light"] .spnc-custom .spnc-nav li > a.search-icon,
            [data-theme="spnc_light"] body .header-4 .spnc-custom .spnc-nav li > a.search-icon,
            [data-theme="spnc_light"] .header-7 .spnc-custom .spnc-nav li > a.search-icon i{
                color: <?php echo esc_attr( get_theme_mod('search_icon_color', '#') ); ?>;
            }
            @media (max-width: 1100px) {
                body.newscrunch :is(.header-1, .header-3, .header-5, .header-6, .header-8, .header-12) .spnc-custom .spnc-nav li > a.search-icon {
                    color: <?php echo esc_attr( get_theme_mod('search_icon_color', '#') ); ?>;
                }
            }
            [data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav li > a.search-icon,
            [data-theme="spnc_dark"] .header-7 .spnc-custom .spnc-nav li > a.search-icon i{
                color: <?php echo esc_attr( get_theme_mod('search_icon_dcolor', '#') ); ?>;
            }
            [data-theme="spnc_light"] .spnc-dark-icon,
            [data-theme="spnc_light"] body .header-5 .spnc-dark-icon{
                color: <?php echo esc_attr( get_theme_mod('switcher_icon_color', '#') ); ?>;
            } 
            [data-theme="spnc_dark"] .spnc-dark-icon,
            [data-theme="spnc_dark"] body .header-5 .spnc-dark-icon,
            [data-theme="spnc_dark"] .header-12.plus-header .spnc-dark-icon i{
                color: <?php echo esc_attr( get_theme_mod('switcher_icon_dcolor', '#') ); ?>;
            }
        </style>
    <?php }

    /* ====================
        * Footer 
    ==================== */
    if($newscrunch_enable_theme_footer_color == true) { ?>
        <style>
            body #wrapper .footer-sidebar .wp-block-search .wp-block-search__label, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h1, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h2, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h3, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h4, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h5, body #wrapper .footer-sidebar .f-w-c.widget.widget_block h6, body #wrapper .footer-sidebar .widget .widget-title {
                color: <?php echo esc_attr( get_theme_mod('fwidgets_title_color', '#ffffff') ); ?>;
            }

            /* text or content color */
            body .footer-sidebar p, body .footer-sidebar .wp-block-calendar table tbody,
            body .footer-sidebar address, body #wrapper .footer-sidebar .widget_block p,
            body #wrapper .widget.f-w-c.widget_text p,
            body #wrapper .widget.f-w-c.widget_newscrunch_grid_slider_two_column p.spnc-description,
            body #wrapper .widget.f-w-c.widget_newscrunch_list_view_slider p.spnc-description,
            body #wrapper .widget.f-w-c.widget_spncp_filter_list_view .spnc-wrapper-4-item .spnc-post .spnc-post-content .spnc-description,
            body #wrapper .widget.f-w-c.widget_spncp_filter_two_column .spnc-filter .spnc-post .spnc-description,
            body #wrapper .widget.f-w-c.widget_recent_comments .no-comments.wp-block-latest-comments,
            body #wrapper .widget.f-w-c.widget_rss .notice.notice-error,
            body #wrapper .footer-sidebar .widget.widget_calendar td,
            body #wrapper .widget.f-w-c.widget_block .wp-block-latest-posts .wp-block-latest-posts__post-excerpt, 
            body .footer-sidebar .widget_newscrunch_adv .adv-img-content h4, body .footer-sidebar .widget_newscrunch_adv .adv-img-content p
            {
                color: <?php echo esc_attr( get_theme_mod('fwidgets_text_color', '#858585') ); ?>;
            }
            #wrapper .widget.f-w-c.widget_text p.has-white-color{color: <?php echo esc_attr( get_theme_mod('fwidgets_text_color', '#858585') ); ?> !important;}

            /* post title color */
            body .footer-sidebar a:not(.widget_spncp_social_icons a, .widget_newscrunch_adv a),
            body #wrapper .footer-sidebar .widget.widget_nav_menu a,
             [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-latest-posts li a, [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-archives li a, [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-categories li a, [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-page-list li a, [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-rss li a, [data-theme="spnc_dark"] .footer-sidebar .widget_meta ul li a, [data-theme="spnc_dark"] .footer-sidebar .widget .wp-block-latest-comments li a, [data-theme="spnc_dark"] .footer-sidebar .widget .widget_nav_menu li a, body .site-footer .footer-sidebar .widget_newscrunch_post_grid_two_col .widget-recommended-post li a, body .site-footer .widget_newscrunch_plus_list_view .newscrunch_plus_list_view_ul .spnc-post-content a,
            body #wrapper .widget.f-w-c .spnc-entry-title a:not(.spnc-first-post .spnc-entry-title a, .widget_newscrunch_overlay_slider .spnc-entry-title a),
            body #wrapper .widget.f-w-c.widget_newscrunch_single_column .spnc-post-content a,
            body .footer-sidebar .widget .wp-block-tag-cloud a, body.newsblogger .footer-sidebar .widget .wp-block-tag-cloud a
            {
                color: <?php echo esc_attr( get_theme_mod('fwidgets_link_color', '#ffffff') ); ?>;
            }
            body.newscrunch .footer-sidebar .widget a:not(.wp-block-archives a, .wp-block-categories a, .widget_meta a, .footer-sidebar .widget_newscrunch_adv .adv-img-content a):hover, 
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget .wp-block-latest-posts li a:hover,
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget .wp-block-page-list li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget .wp-block-rss li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget_meta ul li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget .wp-block-latest-comments li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .footer-sidebar .widget .widget_nav_menu li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .site-footer .widget .wp-block-latest-posts li a:hover,
            [data-theme="spnc_dark"] body.newscrunch .site-footer .widget .wp-block-page-list li a:hover,  
            [data-theme="spnc_dark"] body.newscrunch .site-footer .widget .wp-block-rss li a:hover, 
            [data-theme="spnc_dark"] body.newscrunch .site-footer .widget_meta ul li a:hover,  
            [data-theme="spnc_dark"] body.newscrunch .site-footer .widget .wp-block-latest-comments li a:hover,
            body .site-footer .footer-sidebar .widget_newscrunch_post_grid_two_col .widget-recommended-post li a:hover
            {
                color: <?php echo esc_attr( get_theme_mod('fwidgets_link_hover_color', '#') ); ?>;
                -webkit-text-fill-color: unset;
                -webkit-background-clip: unset;
                background-image: unset; 
            }
            body #wrapper .site-footer .footer-sidebar .widget.f-w-c .spnc-entry-title a:not(.spnc-first-post .spnc-entry-title a):hover,
            body #wrapper .site-footer .footer-sidebar .widget.f-w-c.widget_newscrunch_single_column .spnc-post-content a:hover,
            body #wrapper .site-footer .footer-sidebar .widget.f-w-c.widget_recent_comments a.wp-block-latest-comments__comment-link:hover,
            body #wrapper .site-footer .footer-sidebar .widget.f-w-c.widget_recent_comments a.wp-block-latest-comments__comment-author:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-list-post .spnc-date a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-list-post .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_grid_slider_two_column .spnc-filter .spnc-post .spnc-entry-meta a:not(.spnc-filter .spnc-post .spnc-entry-meta .spnc-date a):hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_grid_slider_three_column .spnc-post-grid-slider-three-column .spnc-entry-meta .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_list_grid_view .spnc-date a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_list_view_slider .spnc-blog-wrapper .spnc-post .spnc-entry-meta a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_spncp_filter_three_column .spnc-entry-meta .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_spncp_filter_two_column .spnc-filter .spnc-post .spnc-entry-meta .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-first-post .spnc-entry-title a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-first-post .spnc-date a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-first-post .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_featured_post .spnc-first-post .spnc-comment-links a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_overlay_slider .spnc-blog1-carousel h4.spnc-entry-title a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_overlay_slider .spnc-date a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_overlay_slider .spnc-author a:hover,
            body #wrapper .site-footer .footer-sidebar .f-w-c.widget_newscrunch_overlay_slider .spnc-cat-links a:hover, 
            body .footer-sidebar .widget .wp-block-tag-cloud a:is(:hover, :focus), body .footer-sidebar .widget .wp-block-categories li a:is(:hover, :focus), body .footer-sidebar .widget .wp-block-archives li a:is(:hover, :focus), body .footer-sidebar .widget_meta ul li a:is(:hover, :focus){
                color: <?php echo esc_attr( get_theme_mod('fwidgets_link_hover_color', '#') ); ?>;
            }

            /*body .footer-sidebar .widget .wp-block-tag-cloud a:hover{
                color: #ffffff !important;
            }*/
        </style>
    <?php }

    /* ====================
        * Footer Bar
    ==================== */
    if($newscrunch_enable_bottom_footer_color == true) { ?>
        <style>
            body .site-info {
                background-color: <?php echo esc_attr( get_theme_mod('bfooter_back_color', '#000000') ); ?>;
            }
            body .site-footer .site-info .footer-nav li a, body .site-footer .site-info .footer-nav li a {
                color: <?php echo esc_attr( get_theme_mod('bfooter_menu_color', '#ffffff') ); ?>;
            }
            body .site-footer .site-info .footer-nav li a:hover, body .site-footer .site-info .footer-nav li a:hover,
            body.newsblogger .site-footer .site-info .footer-nav li a:is(:hover,:focus) {
                color: <?php echo esc_attr( get_theme_mod('bfooter_menu_hover_color', '#') ); ?>;
            }
            body .site-info p.copyright-section {
                color: <?php echo esc_attr( get_theme_mod('copyright_text_color', '#a7a7a7') ); ?>;
            }
            body.newsblogger .site-info p.copyright-section {
                color: <?php echo esc_attr( get_theme_mod('copyright_text_color', '#ffffff') ); ?>;
            }
            body .site-info p.copyright-section a {
                color: <?php echo esc_attr( get_theme_mod('copyright_link_color', '#ffffff') ); ?>;
            }
            body.newsblogger .site-info p.copyright-section a {
                color: <?php echo esc_attr( get_theme_mod('copyright_link_color', '#369ef6') ); ?>;
            }
            body .site-info p.copyright-section a:hover {
                color: <?php echo esc_attr( get_theme_mod('copyright_link_hover_color', '#') ); ?>;
            }
        </style>
    <?php }

    /* ====================
        * Header Preset
    ==================== */
    if( $hide_show_header_border == false ) { ?> 
        <style type="text/css">
         .header-sidebar.header-1 .spnc-custom .spnc-navbar { padding: 0; }
        </style>
        <?php } 
    if( $hide_show_header_border_radius == false) { ?>
        <style type="text/css">
            .header-sidebar.header-1 .spnc-navbar .spnc-container {border-radius: 0;}
            .header-sidebar.header-1 .spnc-custom .spnc-navbar{border-radius: 0;}
        </style> 
    <?php }  
    if( ($hide_show_header_border == true )  && ( $hide_show_header_border_radius == false)) { ?>
        <style type="text/css">
            .header-1 .spnc-custom .spnc-navbar {padding: 1px 1px;}
        </style>
    <?php } 

    /* ====================
        * Featured Video
    ==================== */
    if( get_theme_mod('featured_video_section_width','default')=='full') { ?>
        <style type="text/css">
            .spnc-page-section-space.spnc-video .spnc-container
            {
                max-width: 100%;
                padding: 0;
            }
        </style>    
    <?php }

}
add_action('wp_head', 'newscrunch_color_back_custom_css');