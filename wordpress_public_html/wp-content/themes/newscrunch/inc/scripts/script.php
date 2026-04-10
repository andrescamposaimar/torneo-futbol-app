<?php
/**
 * Enqueue scripts and styles.
 */
function newscrunch_scripts() {

    /* Enqueue the CSS scripts */
    $suffix = ( defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ) ? '' : '.min';

    wp_enqueue_style('animate', NEWSCRUNCH_TEMPLATE_DIR_URI. '/assets/css/animate' . $suffix . '.css');
    wp_enqueue_style('newscrunch-menu-css', NEWSCRUNCH_TEMPLATE_DIR_URI. '/assets/css/theme-menu.css');
    wp_style_add_data('newscrunch-menu-css', 'rtl', 'replace');
    wp_enqueue_style('owl-carousel', NEWSCRUNCH_TEMPLATE_DIR_URI. '/assets/css/owl.carousel' . $suffix . '.css');
    if(get_theme_mod('hide_show_featured_video',false) == true):
    wp_enqueue_style('magnific-popup', NEWSCRUNCH_TEMPLATE_DIR_URI. '/assets/css/magnific-popup.css');
    endif;
    
    wp_enqueue_style('newscrunch-style', get_stylesheet_uri() );
    wp_style_add_data('newscrunch-style', 'rtl', 'replace');

    wp_enqueue_style('font-awesome-min', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/css/font-awesome/css/all' . $suffix . '.css', array(), '');
    wp_enqueue_style('newscrunch-dark', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/css/dark.css');
    if ( ! function_exists( 'spncp_activate' ) ):
        if (get_theme_mod('custom_color_enable') == true) {
            add_action('wp_footer','newscrunch_custom_color_css');
        }else{
            wp_enqueue_style('newscrunch-default', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/css/default.css');
        }
    endif;

    /* Enqueue the JS scripts */
    wp_enqueue_script('owl-carousel', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/owl.carousel' . $suffix . '.js', array('jquery'), '',true);
    wp_enqueue_script('newscrunch-custom', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/custom.js', array('jquery'), '',true);   
    wp_enqueue_script('newscrunch-menu', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/menu/menu.js', array('jquery'), '',true);
    wp_enqueue_script('newscrunch-main', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/main.js', array('jquery'), '',true);
    wp_enqueue_script('magnific-popup', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/magnific-popup' . $suffix . '.js', array('jquery'), '',true);
    wp_enqueue_script('newscrunch-sticky-sidebar', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/sticky-sidebar' . $suffix . '.js', array('jquery'), '',true);

    if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
        wp_enqueue_script( 'comment-reply' );
    }
}
add_action( 'wp_enqueue_scripts', 'newscrunch_scripts' );


// Missed section script
if ( ! function_exists( 'spncp_activate' ) ):
    if(get_theme_mod('hide_show_missed_section',true) == true){
        function spnc_missed_scripts() {
            $spnc_missed_category       = get_theme_mod('missed_section_dropdown_category',1);
            $spnc_missed_post_order     = get_theme_mod('missed_section_post_order','DESC');
            global $post;
            $query_args2 = array( 'cat'  => $spnc_missed_category, 'order' => $spnc_missed_post_order,'ignore_sticky_posts' => 1);
            $the_query2 = new WP_Query($query_args2);
            if ( $the_query2->have_posts() ) { if($the_query2->found_posts>4) { $loop = true; } else { $loop = false; }} else { $loop = false; }
            $settings = array( 'loop' => $loop);
            wp_register_script('newscrunch-missed', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/missed.js', array('jquery'), '',true);
            wp_localize_script('newscrunch-missed', 'newscrunch_missed_settings', $settings);
            wp_enqueue_script('newscrunch-missed');
        }
        add_action('wp_enqueue_scripts', 'spnc_missed_scripts');
    }
endif;

/**
 * Admin Enqueue scripts and styles.
 */
function newscrunch_notice_style() { 
    wp_enqueue_style('newscrunch-admin', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/css/admin.css');
}
add_action('admin_enqueue_scripts','newscrunch_notice_style');