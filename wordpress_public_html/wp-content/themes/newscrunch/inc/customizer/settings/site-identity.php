<?php
/**
 * Site Identity Customizer
 *
 * @package Newscrunch
*/

function newscrunch_site_identity_panel_customizer ( $wp_customize ) {

    $wp_customize->get_setting( 'blogname' )->transport = 'postMessage';
    $wp_customize->get_setting( 'blogdescription' )->transport = 'postMessage';
    
    //contact info tabs
    $wp_customize->add_setting( 'site_identity_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
   $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'site_identity_tab', 
        array(
            'section'   => 'title_tagline',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'custom_logo',
                        'dark_header_logo',
                        'newscrunch_logo_view',
                        'newscrunch_logo_length',
                        'newscrunch_logo_tablet_length',
                        'newscrunch_logo_mobile_length',
                        'blogname',
                        'blogdescription',
                        'header_text',
                        'hide_show_site_title',
                        'hide_show_site_tagline',
                        'site_icon'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_site_identity_color',
                        'site_title_color',
                        'site_title_hover_color',
                        'site_tagline_color',
                        'site_title_dcolor',
                        'site_title_hover_dcolor',
                        'site_tagline_dcolor'
                    ),
                ),
            ),
        )
    ));


    //logo  layout
    $wp_customize->add_setting( 'newscrunch_logo_view',
        array(
            'default'           =>  'desktop',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'newscrunch_logo_view',
        array(
            'label'             =>  esc_html__( 'Logo Width', 'newscrunch'  ),
            'section'           =>  'title_tagline',
            'settings'          =>  'newscrunch_logo_view',
            'priority'          =>  9,
            'choices'           => array(
                                    'desktop' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/desktop.png'),
                                    'tablet' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/tablet.png'),
                                    'mobile' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/mobile.png')   
                                )
        )
    ));

    // logo width
    $wp_customize->add_setting( 'newscrunch_logo_length',
        array(
            'default'           =>  250,
            'transport'         =>  'postMessage',
            'sanitize_callback' =>  'absint'
        )
    );

    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_logo_length',
        array(
            'priority'        =>  9,
            'section'         =>  'title_tagline',
            'settings'        =>  'newscrunch_logo_length',
            'input_attrs'     => array(
                'min'   => 0,
                'max'   => 500,
                'step'  => 1,
            ),
        )
    ));

    // logo width tablet
    $wp_customize->add_setting( 'newscrunch_logo_tablet_length',
        array(
            'default'           =>  200,
            'sanitize_callback' =>  'absint'
        )
    );

    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_logo_tablet_length',
        array(
            'priority'      =>  9,
            'section'       =>  'title_tagline',
            'settings'       =>  'newscrunch_logo_tablet_length',
            'input_attrs'   => array(
                'min'   => 0,
                'max'   => 500,
                'step'  => 1,
            ),
        )
    ));

   // logo width mobile
    $wp_customize->add_setting( 'newscrunch_logo_mobile_length',
        array(
            'default'           =>  150,
            'sanitize_callback' =>  'absint'
        )
    );

    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_logo_mobile_length',
        array(
            'priority'        =>  9,
            'section'         =>  'title_tagline',
            'settings'        =>  'newscrunch_logo_mobile_length',
            'input_attrs'   => array(
                'min'   => 0,
                'max'   => 500,
                'step'  => 1,
            ),
        )
    ));

    $wp_customize->add_setting( 'dark_header_logo', 
        array(
            'sanitize_callback' => 'esc_url_raw'
        )
    );
            
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'dark_header_logo', 
        array(
            'label'    => __( 'Dark Layout Logo', 'newscrunch' ),
            'section'  => 'title_tagline',
            'priority'    => 8,
        ) 
    ));

    // enable/disable site title
    $wp_customize->add_setting('hide_show_site_title',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_site_title',
        array(
            'label'             =>  esc_html__( 'Display Site Title', 'newscrunch'),
            'section'           =>  'title_tagline',
            'settings'           =>  'hide_show_site_title',
            'active_callback'   =>  'newscrunch_header_text_choice_callback',
            'type'              =>  'toggle',
            'priority'          =>  10
        )
    ));

    // enable/disable tagline
    $wp_customize->add_setting('hide_show_site_tagline',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_site_tagline',
        array(
            'label'             =>  esc_html__( 'Display Site Tagline', 'newscrunch'),
            'section'           =>  'title_tagline',
            'settings'           =>  'hide_show_site_tagline',
            'active_callback'   =>  'newscrunch_header_text_choice_callback',
            'type'              =>  'toggle',
            'priority'          =>  11
        )
    ));

    /* Site Identity Style Tab */
    // enable/disable the site identity color
    $wp_customize->add_setting('hide_show_site_identity_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_site_identity_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'title_tagline',
            'settings'   =>  'hide_show_site_identity_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));
    // site title color
    $wp_customize->add_setting('site_title_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_title_color', 
        array(
            'label'             =>  esc_html__('Site Title Color', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_title_color',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  2
        )
    ));
    // site title hover color
    $wp_customize->add_setting('site_title_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_title_hover_color', 
        array(
            'label'             =>  esc_html__('Site Title Hover Color', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_title_hover_color',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  3
        )
    ));
    // site tagline hover color
    $wp_customize->add_setting('site_tagline_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_tagline_color', 
        array(
            'label'             =>  esc_html__('Site Tagline Color', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_tagline_color',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  4
        )
    ));

    // site title color for dark layout
    $wp_customize->add_setting('site_title_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_title_dcolor', 
        array(
            'label'             =>  esc_html__('Site Title Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_title_dcolor',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  5
        )
    ));

    // site title hover color for dark layout
    $wp_customize->add_setting('site_title_hover_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_title_hover_dcolor', 
        array(
            'label'             =>  esc_html__('Site Title Hover Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_title_hover_dcolor',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  6
        )
    ));
    
    // site tagline hover color for dark layout
    $wp_customize->add_setting('site_tagline_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'site_tagline_dcolor', 
        array(
            'label'             =>  esc_html__('Site Tagline Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'title_tagline',
            'settings'           =>  'site_tagline_dcolor',
            'active_callback'   =>  'newscrunch_header_text_color_callback',
            'priority'          =>  7
        )
    ));
}
add_action( 'customize_register', 'newscrunch_site_identity_panel_customizer' );