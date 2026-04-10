<?php
/**
 * Front Sections
 *
 * @package Newscrunch
*/

function newscrunch_front_sections ( $wp_customize ) {

    /* ====== FRONT SECTION ====== */

    $wp_customize->add_section('front_page_order_section',
    array(
        'title' => esc_html__('Reorder sections', 'newscrunch'),
        'priority' => 27
    ));

    /************************* Blog Meta Rearrange*********************************/
    if('NewsBlogger' == wp_get_theme()) {
        if ( class_exists('Newscrunch_Plus') )
        {
            if ( class_exists( 'WooCommerce' ) ) 
            {
                $default = array( 'front_content_1', 'video_content', 'front_content_2', 'mainblog_content','youtube_content','missed_content','wc_content');
                $choices = array(
                    'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
                    'video_content'    => esc_html__('Featured Video', 'newscrunch'),
                    'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
                    'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
                    'youtube_content' => esc_html__( 'Youtube Playlist', 'newscrunch' ),
                    'missed_content'   => esc_html__('Missed Section', 'newscrunch'),
                    'wc_content'   => esc_html__('WooCommerce Product Section', 'newscrunch')
                );
            }
            else
            {
                $default = array( 'front_content_1', 'video_content', 'front_content_2', 'mainblog_content','youtube_content','missed_content');
                $choices = array(
                    'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
                    'video_content'    => esc_html__('Featured Video', 'newscrunch'),
                    'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
                    'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
                    'youtube_content' => esc_html__( 'Youtube Playlist', 'newscrunch' ),
                    'missed_content'   => esc_html__('Missed Section', 'newscrunch')
                );
            }
        }
        else
        {
            $default = array( 'front_content_1', 'front_content_2', 'mainblog_content','missed_content');
            $choices = array(
                'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
                'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
                'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
                'missed_content'   => esc_html__('Missed Section', 'newscrunch')
            );
        }
        
        
    }
    else {
        if ( class_exists('Newscrunch_Plus') )
        {
            if ( class_exists( 'WooCommerce' ) ) 
            {
                $default = array( 'front_content_1', 'video_content', 'front_content_2', 'mainblog_content','youtube_content','missed_content','wc_content');
                $choices = array(
                    'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
                    'video_content'    => esc_html__('Featured Video', 'newscrunch'),
                    'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
                    'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
                    'youtube_content' => esc_html__( 'Youtube Playlist', 'newscrunch' ),
                    'missed_content'   => esc_html__('Missed Section', 'newscrunch'),
                    'wc_content'   => esc_html__('WooCommerce Product Section', 'newscrunch')
                );
            }
            else
            {
                $default = array( 'front_content_1', 'video_content', 'front_content_2', 'mainblog_content','youtube_content','missed_content');
                $choices = array(
                    'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
                    'video_content'    => esc_html__('Featured Video', 'newscrunch'),
                    'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
                    'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
                    'youtube_content' => esc_html__( 'Youtube Playlist', 'newscrunch' ),
                    'missed_content'   => esc_html__('Missed Section', 'newscrunch')
                );
            }
        }
        else
        {
        $default = array( 'front_content_1', 'video_content', 'front_content_2', 'mainblog_content','missed_content');
        $choices = array(
            'front_content_1'  => esc_html__( 'Left Content Right Sidebar', 'newscrunch'),
            'video_content'    => esc_html__('Featured Video', 'newscrunch'),
            'front_content_2'  => esc_html__('Left Sidebar Right Content', 'newscrunch'),
            'mainblog_content' => esc_html__( 'Blog', 'newscrunch' ),
            'missed_content'   => esc_html__('Missed Section', 'newscrunch')
        );
        }
    }    

    $wp_customize->add_setting( 'front_page_content_sort',
    array(
        'capability'  => 'edit_theme_options',
        'sanitize_callback' => 'newscrunch_sanitize_array',
        'default'     => $default
    ) );

    $wp_customize->add_control( new Newscrunch_Control_Sortable( $wp_customize, 'front_page_content_sort',
    array(
        'label' => esc_html__( 'Drag And Drop to Rearrange', 'newscrunch' ),
        'section' => 'front_page_order_section',
        'settings' => 'front_page_content_sort',
        'type'=> 'sortable',
        'choices'     => $choices
    ) ) );


}
add_action( 'customize_register', 'newscrunch_front_sections' );