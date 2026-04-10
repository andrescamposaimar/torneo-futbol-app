<?php
/**
 * Bottom Footer Panel Customizer
 *
 * @package Newscrunch
*/

function newscrunch_bottom_footer_panel_customizer ( $wp_customize ) {

    $wp_customize->add_section('newscrunch_bottom_footer',
        array(
            'title'         => esc_html__('Bottom Footer', 'newscrunch' ),
            'priority'      => 32
        )
    );

    //bottom footer tabs
    $wp_customize->add_setting( 'bottom_footer_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'bottom_footer_tab', 
        array(
            'section'   => 'newscrunch_bottom_footer',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_footer_copyright',
                        'footer_copyright',
                        'hide_show_footer_menus'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_bottom_footer_color',
                        'bfooter_back_color',
                        'bfooter_menu_color',
                        'bfooter_menu_hover_color',
                        'copyright_text_color',
                        'copyright_link_color',
                        'copyright_link_hover_color'
                    ),
                ),
            ),
        )
    ));

    /* Theme Footer General Tab */
    // enable/disable footer widgets
    $wp_customize->add_setting('hide_show_footer_copyright',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_footer_copyright',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Copyright Text', 'newscrunch'),
            'section'   =>  'newscrunch_bottom_footer',
            'settings'   =>  'hide_show_footer_copyright',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // copyright text
    if ('NewsBlogger' == wp_get_theme()) {
        $newscrunch_tname = wp_get_theme();
    }
    else {
        $newscrunch_tname = wp_get_theme();
    }
    $wp_customize->add_setting('footer_copyright', 
        array(
            'default'           =>  $newscrunch_tname . ' - ' . __('Magazine & Blog', 'newscrunch') . ' '. '<a href="https://wordpress.org">' . 'WordPress' . '</a>' . ' ' . __("Theme","newscrunch") . ' ' . '%year%',
            'sanitize_callback' => 'newscrunch_sanitize_text'
        )
    );
    $wp_customize->add_control('footer_copyright', 
        array(
            'label'             =>  esc_html__('Copyright Text','newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'footer_copyright',
            'type'              =>  'textarea',
            'active_callback'   =>  'newscrunch_footer_copyright_callback',
            'priority'          =>  2
        )
    );

    // enable/disable footer menus
    $wp_customize->add_setting('hide_show_footer_menus',
        array(
            'default'           =>  true,
            'sanitize_callback' =>  'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_footer_menus',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Footer Menu', 'newscrunch'),
            'section'   =>  'newscrunch_bottom_footer',
            'settings'   =>  'hide_show_footer_menus',
            'type'      =>  'toggle',
            'priority'  =>  3
        )
    ));


    /* Bottom Footer Style Tab */
    // enable/disable the color
    $wp_customize->add_setting('hide_show_bottom_footer_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_bottom_footer_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'newscrunch_bottom_footer',
            'settings'   =>  'hide_show_bottom_footer_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // background color
    $wp_customize->add_setting('bfooter_back_color', 
        array(
            'default'           => '#000000',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'bfooter_back_color', 
        array(
            'label'             =>  esc_html__('Background Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'bfooter_back_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  2
        )
    ));

    // menu color
    $wp_customize->add_setting('bfooter_menu_color', 
        array(
            'default'           => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'bfooter_menu_color', 
        array(
            'label'             =>  esc_html__('Menu Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'bfooter_menu_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  3
        )
    ));

    // menu hover color
    $wp_customize->add_setting('bfooter_menu_hover_color', 
        array(
            'default'           => '#669c9b',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'bfooter_menu_hover_color', 
        array(
            'label'             =>  esc_html__('Menu Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'          =>  'bfooter_menu_hover_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  4
        )
    ));

    // copyright text color
    $wp_customize->add_setting('copyright_text_color', 
        array(
            'default'           => '#6C6C6F',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'copyright_text_color', 
        array(
            'label'             =>  esc_html__('Text Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'copyright_text_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  5
        )
    ));

    // copyright link color
    $wp_customize->add_setting('copyright_link_color', 
        array(
            'default'           => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'copyright_link_color', 
        array(
            'label'             =>  esc_html__('Link Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'copyright_link_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  6
        )
    ));

    // copyright link hover color
    $wp_customize->add_setting('copyright_link_hover_color', 
        array(
            'default'           => '#669c9b',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'copyright_link_hover_color', 
        array(
            'label'             =>  esc_html__('Link Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_bottom_footer',
            'settings'           =>  'copyright_link_hover_color',
            'active_callback'   =>  'newscrunch_bottom_footer_color_callback',
            'priority'          =>  7
        )
    ));

}
add_action( 'customize_register', 'newscrunch_bottom_footer_panel_customizer' );