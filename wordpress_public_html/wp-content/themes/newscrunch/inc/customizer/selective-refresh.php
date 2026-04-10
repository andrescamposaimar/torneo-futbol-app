<?php
/**
 * Includes functions for selective refresh
 * 
 * @package Newscrunch
 */
function newscrunch_customize_selective_refresh( $wp_customize ) {
    if ( ! isset( $wp_customize->selective_refresh ) ) return;
    // site title
    $wp_customize->selective_refresh->add_partial('blogname',
        array(
            'selector'        => '.site-title a',
            'render_callback' => 'newscrunch_customize_partial_blogname'
        )
    );
    // site tagline
    $wp_customize->selective_refresh->add_partial('blogdescription',
        array(
            'selector'        => '.site-description',
            'render_callback' => 'newscrunch_customize_partial_blogdescription'
        )
    );
    if(! function_exists( 'spncp_activate' )):
    // date
    $wp_customize->selective_refresh->add_partial('hide_show_date',
        array(
            'selector'        => '.head-contact-info .header-date',
            'render_callback' => 'hide_show_date'
        )
    );
    // time
    $wp_customize->selective_refresh->add_partial('hide_show_time',
        array(
            'selector'        => '.head-contact-info .header-time',
            'render_callback' => 'hide_show_time'
        )
    );
    // social icons
    $wp_customize->selective_refresh->add_partial('social_icons',
        array(
            'selector'        => '.custom-social-icons',
            'render_callback' => 'social_icons'
        )
    );
    endif;
    // copyright
    $wp_customize->selective_refresh->add_partial('footer_copyright',
        array(
            'selector'        => '.site-info .copyright-section',
            'render_callback' => 'newscrunch_footer_copyright'
        )
    );
    // news highlight
   $wp_customize->selective_refresh->add_partial('news_highlight_title',
        array(
            'selector'        => '.spnc-highlights-1 .spnc-highlights-title h3',
            'render_callback' => 'newscrunch_news_highlight_title'
        )
    );

    // featured video
    $wp_customize->selective_refresh->add_partial('featured_video_title',
        array(
            'selector'        => '.spnc-video .spnc-blog-1-heading h4',
            'render_callback' => 'newscrunch_featured_video_title'
        )
    );

    // Main banner
    $wp_customize->selective_refresh->add_partial('hide_show_banner',
        array(
            'selector'        => '.front-banner .spnc_column_container',
            'render_callback' => 'hide_show_banner'
        )
    );

    // Main Banner Read More Button
   $wp_customize->selective_refresh->add_partial('spncp_banner_center_read_btn',
        array(
            'selector'        => '.spnc-info-link',
            'render_callback' => 'newscrunch_spncp_banner_center_read_btn'
        )
    );

    // Releted Post Title
    $wp_customize->selective_refresh->add_partial('newscrunch_related_post_title',
        array(
            'selector'        => '.spnc-related-posts .widget-title',
            'render_callback' => 'newscrunch_related_post_title_callback'
        )
    );

    // Missed Secdtion Title
    if ( class_exists('Newscrunch_Plus') ){
    $plugin_dir = WP_PLUGIN_DIR . '/newscrunch-plus/newscrunch-plus.php';
    $plugin_data = get_plugin_data($plugin_dir);
    if($plugin_data['Version']>=2.5){
    $wp_customize->selective_refresh->add_partial('missed_section_title',
        array(
            'selector'        => '.spncmc-selective .spncmc-head',
            'render_callback' => 'newscrunch_missed_section_title_callback'
        )
    );
    }
    }
    else{
    $wp_customize->selective_refresh->add_partial('missed_section_title',
        array(
            'selector'        => '.spncmc-selective .spncmc-head',
            'render_callback' => 'newscrunch_missed_section_title_callback'
        )
    );    
    }

    if(get_theme_mod('newscrunch_wc_product_sale_badge','percentage') === 'text')
    {
        // Register Selective Refresh for Sale Badge Text
        $wp_customize->selective_refresh->add_partial('newscrunch_wc_product_sale_badge_text',
            array(
                'selector'        => '.onsale',
                'render_callback' => 'newscrunch_wc_product_sale_badge_text_callback',
                'fallback_refresh'    => false, 
            )
        );
    }
}
add_action( 'customize_register', 'newscrunch_customize_selective_refresh' );


/**
 * Render the site title for the selective refresh partial.
 *
 * @return void
 */
function newscrunch_customize_partial_blogname() {
    bloginfo( 'name' );
}

/**
 * Render the site tagline for the selective refresh partial.
 *
 * @return void
 */
function newscrunch_customize_partial_blogdescription() {
    bloginfo( 'description' );
}
function newscrunch_news_highlight_title() {
    return get_theme_mod( 'news_highlight_title' );
}
function newscrunch_spncp_banner_center_read_btn() {
    return get_theme_mod( 'spncp_banner_center_read_btn' );
}

function newscrunch_footer_copyright(){
    return get_theme_mod('footer_copyright');
}

function newscrunch_featured_video_title(){
    return get_theme_mod('featured_video_title');
}
function newscrunch_related_post_title_callback(){
    return get_theme_mod('newscrunch_related_post_title');
}
function newscrunch_missed_section_title_callback(){
    return get_theme_mod('missed_section_title');
}
function newscrunch_wc_product_sale_badge_text_callback() {
    $badge_text = get_theme_mod('newscrunch_wc_product_sale_badge_text', 'Sale'); 
    return esc_html($badge_text); 
}