<?php
/**
 * Left Sidebar-Right Content
 *
 * @package Newscrunch
*/

function newscrunch_left_sidebar_right_content ( $wp_customize ) {

    /* ====== Panel ====== */
    $wp_customize->add_panel('left_sidebar_right_content_panel', 
        array(
            'title'         => esc_html__('Left Sidebar Right Content' , 'newscrunch' ),
            'capability'    => 'edit_theme_options',
            'priority'      => 25
        )
    );

    $sidebar_widgets_front_page_2 = $wp_customize->get_section( 'sidebar-widgets-front-page-2' );
    if ( ! empty( $sidebar_widgets_front_page_2 ) ) {
        $sidebar_widgets_front_page_2->panel = 'left_sidebar_right_content_panel';
    }

    $sidebar_widgets_front_page_side_2 = $wp_customize->get_section( 'sidebar-widgets-front-page-side-2' );
    if ( ! empty( $sidebar_widgets_front_page_side_2 ) ) {
        $sidebar_widgets_front_page_side_2->panel = 'left_sidebar_right_content_panel';
    }


}
add_action( 'customize_register', 'newscrunch_left_sidebar_right_content' );