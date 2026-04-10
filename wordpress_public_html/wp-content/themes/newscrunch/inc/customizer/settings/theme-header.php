<?php
/**
 * Theme Header Panel Customizer
 *
 * @package Newscrunch
*/

function newscrunch_theme_header_panel_customizer ( $wp_customize ) {

    $wp_customize->add_section('newscrunch_theme_header',
        array(
            'title'         => esc_html__('Theme Header', 'newscrunch' ),
            'priority'      => 20
        )
    );

    //contact info tabs
    $wp_customize->add_setting( 'theme_header_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'theme_header_tab', 
        array(
            'section'   => 'newscrunch_theme_header',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'header_layout',
                        'hide_show_header_border',
                        'hide_show_header_border_radius',
                        'header_menu_bg_clr',
                        'hide_show_search_icon',
                        'hide_show_sticky_header',
                        'hide_show_dark_light_icon',
                        'hide_show_toggle_icon',
                        'headerpreset_setting_enable',
                        'header_seven_background_img',
                        'header_seven_img_reapeat',
                        'header_seven_img_position',
                        'header_seven_img_size',
                        'header_seven_img_overlay',
                        'header_seven_overlay_color',
                        'subscribe_btn_enable',
                        'subscribe_label',
                        'subscribe_url',
                        'subscribe_redirect',
                        'banner_ads_enable',
                        'banner_ads_img',
                        'advertisement_url',
                        'advertisement_redirect',
                        'themeheader_pro_feature'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_theme_header_color',
                        'menu_link_color',
                        'menu_link_hover_color',
                        'menu_active_link_color',
                        'submenu_bg_color',
                        'submenu_link_color',
                        'submenu_link_hover_color',
                        'search_icon_color',
                        'switcher_icon_color',
                        'search_icon_dcolor',
                        'switcher_icon_dcolor'
                    ),
                ),
            ),
        )
    ));

    // header layouts
    $wp_customize->add_setting( 'header_layout',
        array(
            'default'           => '2',
            'sanitize_callback' => 'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'header_layout',
        array(
            'label'         =>  esc_html__( 'Header Layout', 'newscrunch'  ),
            'priority'      =>  1,
            'section'       =>  'newscrunch_theme_header',
            'choices'       =>  array(
                'default'   => array( 
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/default.jpg',
                ),
                'full'  => array(
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/standard.jpg',
                ),
                'center'    => array(
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/center.jpg',
                ),
                '2'    => array(
                    'image' => trailingslashit( get_template_directory_uri() ). '/inc/customizer/assets/img/header2.jpg',
                )
            )
        )
    ) );

    // enable/disable header border
    $wp_customize->add_setting('hide_show_header_border',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_header_border',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Border', 'newscrunch'),
            'description'=>  esc_html__( 'Note: This settings will work with header 1, 2, 3', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'   =>  'hide_show_header_border',
            'type'      =>  'toggle',
            'priority'  =>  2
        )
    ));

     // enable/disable header border
    $wp_customize->add_setting('hide_show_header_border_radius',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_header_border_radius',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Border Radius', 'newscrunch'),
            'description'=>  esc_html__( 'Note: This settings will work with header 1, 2, 3', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'   =>  'hide_show_header_border_radius',
            'type'      =>  'toggle',
            'priority'  =>  3
        )
    ));

    // enable/disable site title
    $wp_customize->add_setting('hide_show_search_icon',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_search_icon',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Search Icon', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'   =>  'hide_show_search_icon',
            'type'      =>  'toggle',
            'priority'  =>  4
        )
    ));

    // enable/disable header sticky
    $wp_customize->add_setting('hide_show_sticky_header',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_sticky_header',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Sticky Header', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'   =>  'hide_show_sticky_header',
            'type'      =>  'toggle',
            'priority'  =>  5
        )
    ));


    /* Theme Header Style Tab */
    // enable/disable the color
    $wp_customize->add_setting('hide_show_theme_header_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_theme_header_color',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'hide_show_theme_header_color',
            'type'              =>  'toggle',
            'priority'          =>  1
        )
    ));
    // menu link color
    $wp_customize->add_setting('menu_link_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'menu_link_color', 
        array(
            'label'             =>  esc_html__('Link Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'menu_link_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  2
        )
    ));
    // menu link hover color
    $wp_customize->add_setting('menu_link_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'menu_link_hover_color', 
        array(
            'label'             =>  esc_html__('Link Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'menu_link_hover_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  3
        )
    ));
    // menu active link color
    $wp_customize->add_setting('menu_active_link_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'menu_active_link_color', 
        array(
            'label'             =>  esc_html__('Active Link Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'menu_active_link_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  4
        )
    ));

    // submenu background color
    $wp_customize->add_setting('submenu_bg_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'submenu_bg_color', 
        array(
            'label'             =>  esc_html__('Background Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'submenu_bg_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  5
        )
    ));
    // submenu link color
    $wp_customize->add_setting('submenu_link_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'submenu_link_color', 
        array(
            'label'             =>  esc_html__('Link Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'submenu_link_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  6
        )
    ));
    // submenu link hover color
    $wp_customize->add_setting('submenu_link_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'submenu_link_hover_color', 
        array(
            'label'             =>  esc_html__('Link Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'submenu_link_hover_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  7
        )
    ));

    // search icon color 
    $wp_customize->add_setting('search_icon_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'search_icon_color', 
        array(
            'label'             =>  esc_html__('Search Icon Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'          =>  'search_icon_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  7
        )
    ));
    // switcher icon color 
    $wp_customize->add_setting('switcher_icon_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'switcher_icon_color', 
        array(
            'label'             =>  esc_html__('Switcher Icon Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'switcher_icon_color',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  7
        )
    ));
    // search icon color for dark layout
    $wp_customize->add_setting('search_icon_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'search_icon_dcolor', 
        array(
            'label'             =>  esc_html__('Search Icon Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'search_icon_dcolor',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  7
        )
    ));
    // search icon color for dark layout
    $wp_customize->add_setting('switcher_icon_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'switcher_icon_dcolor', 
        array(
            'label'             =>  esc_html__('Switcher Icon Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_header',
            'settings'           =>  'switcher_icon_dcolor',
            'active_callback'   =>  'newscrunch_theme_header_color_callback',
            'priority'          =>  7
        )
    ));

    // enable/disable dark/light icon
    $wp_customize->add_setting('hide_show_dark_light_icon',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_dark_light_icon',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Dark/Light Icon', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'  =>  'hide_show_dark_light_icon',
            'type'      =>  'toggle',
            'priority'  =>  8
        )
    ));

    // enable/disable toggle icon
    $wp_customize->add_setting('hide_show_toggle_icon',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_toggle_icon',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Toggle Icon', 'newscrunch'),
            'section'   =>  'newscrunch_theme_header',
            'settings'  =>  'hide_show_toggle_icon',
            'type'      =>  'toggle',
            'priority'  =>  9
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):

    class Newscrunch_ThemeHeader_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/theme-header/#main-header?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
            <?php }
    }
        
    $wp_customize->add_setting(
        'themeheader_pro_feature',
        array(
            'capability'     => 'edit_theme_options',
            'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control( new Newscrunch_ThemeHeader_Customize_Control( $wp_customize, 'themeheader_pro_feature', array(
            'section' => 'newscrunch_theme_header',
            'setting' => 'themeheader_pro_feature',
            'priority' => 10
        )
    ));

    endif;

}
add_action( 'customize_register', 'newscrunch_theme_header_panel_customizer' );