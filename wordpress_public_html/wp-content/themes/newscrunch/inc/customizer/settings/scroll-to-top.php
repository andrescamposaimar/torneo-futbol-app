<?php
/**
 * Scroll to top Customizer
 *
 * @package Newscrunch
*/

function newscrunch_scroll_to_top_customizer ( $wp_customize ) {

    $wp_customize->add_section('newscrunch_scroll_to_top',
        array(
            'title'         => esc_html__('Scroll To Top', 'newscrunch' ),
            'panel'     => 'newscrunch_general_settings',
            'priority'      => 4
        )
    );

    //bottom footer tabs
    $wp_customize->add_setting( 'scroll_to_top_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'scroll_to_top_tab', 
        array(
            'section'   => 'newscrunch_scroll_to_top',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_scroll_to_top',
                        'scroll_to_top_display',
                        'scroll_to_top_position',
                        'scroll_to_top_margin_left',
                        'scroll_to_top_margin_right',
                        'scroll_to_top_margin_bottom',
                        'scroll_to_top_icon_class',
                        'scroll_to_top_button_radious',
                        'scroll_to_top_icon_size'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_scroll_to_top_color',
                        'scroll_to_top_back_color',
                        'scroll_to_top_icon_color',
                        'scroll_to_top_back_hover_color',
                        'scroll_to_top_icon_hover_color'
                    ),
                ),
            ),
        )
    ));

    /* Theme Footer General Tab */
    // enable/disable scrol to top
    $wp_customize->add_setting('hide_show_scroll_to_top',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_scroll_to_top',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Scroll To Top', 'newscrunch'),
            'section'   =>  'newscrunch_scroll_to_top',
            'settings'   =>  'hide_show_scroll_to_top',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    $wp_customize->add_setting('scroll_to_top_position',
        array(
            'default'           =>  'right',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control('scroll_to_top_position', 
        array(
            'label'             =>  esc_html__('Choose Position','newscrunch' ),
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'           =>  'scroll_to_top_position',
            'active_callback'   =>  'newscrunch_scroll_to_top_callback',
            'priority'          =>  2,
            'type'              =>  'radio',
            'choices'           =>  array(
                'left'      =>  esc_html__('Left', 'newscrunch' ),
                'right'     =>  esc_html__('Right', 'newscrunch' )
            )
        )
    );

    // select scroll to top icon font
    $wp_customize->add_setting('scroll_to_top_icon_class', 
        array(
            'default'           => 'fa fa-arrow-up',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('scroll_to_top_icon_class', 
        array(      
            'label'             =>  esc_html__('Select Icon', 'newscrunch' ),        
            'section'           =>  'newscrunch_scroll_to_top',
            'settings'           =>  'scroll_to_top_icon_class',
            'active_callback'   =>  'newscrunch_scroll_to_top_callback',
            'type'              =>  'select',
            'priority'          =>  3,
            'choices'           =>  array(
                    'fa fa-arrow-up'     =>  esc_html__('Arrow Up', 'newscrunch' ),
                    'fa-solid fa-angles-up'   =>  esc_html__('Double Arrow Up', 'newscrunch' ),
                    'fa-solid fa-angle-up'  =>  esc_html__('Arrow Single', 'newscrunch' ),
                    'fa-solid fa-arrow-up-long'   =>  esc_html__('Arrow Up Long', 'newscrunch' )
            )
        )
    );


    // scroll to top button radious
    $wp_customize->add_setting( 'scroll_to_top_button_radious',
        array(
            'default'           => 3,
            'transport'         => 'postMessage',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'scroll_to_top_button_radious',
        array(
            'label'             =>  esc_html__('Border Radius', 'newscrunch'),
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'           =>  'scroll_to_top_button_radious',
            'active_callback'   =>  'newscrunch_scroll_to_top_callback',
            'priority'          =>  4,
            'input_attrs'   => 
                array(
                    'min'   =>  0,
                    'max'   =>  30,
                    'step'  =>  1
                )
        )
    ));

    /* Bottom Footer Style Tab */
    // enable/disable the color
    $wp_customize->add_setting('hide_show_scroll_to_top_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_scroll_to_top_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'newscrunch_scroll_to_top',
            'settings'   =>  'hide_show_scroll_to_top_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // Background color for the scroll to top
    $wp_customize->add_setting('scroll_to_top_back_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scroll_to_top_back_color', 
        array(
            'label'             =>  esc_html__('Background', 'newscrunch' ),
            'active_callback'   =>  'newscrunch_scroll_to_top_color_callback',
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'           =>  'scroll_to_top_back_color',
            'priority'          =>  6
        )
    ));

    // Icon color for the scroll to top
    $wp_customize->add_setting('scroll_to_top_icon_color', 
        array(
            'default'           => '#fff',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scroll_to_top_icon_color', 
        array(
            'label'             =>  esc_html__('Icon', 'newscrunch' ),
            'active_callback'   =>  'newscrunch_scroll_to_top_color_callback',
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'          =>  'scroll_to_top_icon_color',
            'priority'          =>  7
        )
    ));

    // Background hover color for the scroll to top
    $wp_customize->add_setting('scroll_to_top_back_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scroll_to_top_back_hover_color', 
        array(
            'label'             =>  esc_html__('Background Hover', 'newscrunch' ),
            'active_callback'   => 'newscrunch_scroll_to_top_color_callback',
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'          =>  'scroll_to_top_back_hover_color',
            'priority'          =>  8
        )
    ));

    // Icon hover color for the scroll to top
    $wp_customize->add_setting('scroll_to_top_icon_hover_color', 
        array(
            'default'           => '#fff',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scroll_to_top_icon_hover_color', 
        array(
            'label'             =>  esc_html__('Icon Hover', 'newscrunch' ),
            'active_callback'   =>  'newscrunch_scroll_to_top_color_callback',
            'section'           =>  'newscrunch_scroll_to_top',
            'setting'           =>  'scroll_to_top_icon_hover_color',
            'priority'          =>  9
        )
    ));

}
add_action( 'customize_register', 'newscrunch_scroll_to_top_customizer' );