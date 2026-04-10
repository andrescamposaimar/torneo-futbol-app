<?php
/**
 * Left Content Right Sidebar
 *
 * @package Newscrunch
*/

function newscrunch_left_content_right_sidebar ( $wp_customize ) {

    /* ====== Panel ====== */
    $wp_customize->add_panel('left_content_right_sidebar_panel', 
        array(
            'title'         => esc_html__('Left Content Right Sidebar' , 'newscrunch' ),
            'capability'    => 'edit_theme_options',
            'priority'      => 24
        )
    );

    $sidebar_widgets_front_page_1 = $wp_customize->get_section( 'sidebar-widgets-front-page-1' );
    if ( ! empty( $sidebar_widgets_front_page_1 ) ) {
        $sidebar_widgets_front_page_1->panel = 'left_content_right_sidebar_panel';
    }

    $sidebar_widgets_front_page_side_1 = $wp_customize->get_section( 'sidebar-widgets-front-page-side-1' );
    if ( ! empty( $sidebar_widgets_front_page_side_1 ) ) {
        $sidebar_widgets_front_page_side_1->panel = 'left_content_right_sidebar_panel';
    }


}
add_action( 'customize_register', 'newscrunch_left_content_right_sidebar' );