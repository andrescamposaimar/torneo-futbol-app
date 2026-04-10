<?php
/**
 * Theme Footer Panel Customizer
 *
 * @package Newscrunch
*/

function newscrunch_theme_footer_panel_customizer ( $wp_customize ) {

    $wp_customize->add_section('newscrunch_theme_footer',
        array(
            'title'         => esc_html__('Theme Footer', 'newscrunch' ),
            'priority'      => 31
        )
    );

    //theme footer tabs
    $wp_customize->add_setting( 'theme_footer_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'theme_footer_tab', 
        array(
            'section'   => 'newscrunch_theme_footer',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_footer_widgets',
                        'footer_widget_layout',
                        'footer_widget_back_image',
                        'footer_widget_reapeat',
                        'footer_widget_position',
                        'footer_widget_bg_size',
                        'footer_widget_bg_attachment',
                        'footer_overlay_enable',
                        'footer_image_overlay_color',
                        'footer_pro_feature'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_theme_footer_color',
                        'fwidgets_title_color',
                        'fwidgets_text_color',
                        'fwidgets_link_color',
                        'fwidgets_link_hover_color',
                        'fwidgets_post_meta_color',
                        'fwidgets_post_meta_hcolor',
                        'fwidgets_post_title_ocolor',
                        'fwidgets_post_meta_ocolor',
                        'fwidgets_post_content_ocolor'
                    ),
                ),
            ),
        )
    ));

    /* Theme Footer General Tab */
    // enable/disable footer widgets
    $wp_customize->add_setting('hide_show_footer_widgets',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_footer_widgets',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Footer Widgets', 'newscrunch'),
            'section'   =>  'newscrunch_theme_footer',
            'settings'   =>  'hide_show_footer_widgets',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // footer widgets layout
    $wp_customize->add_setting( 'footer_widget_layout',
        array(
            'default'           =>  3,
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'footer_widget_layout',
        array(
            'label'             =>  esc_html__( 'Widgets Layout', 'newscrunch'  ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'footer_widget_layout',
            'active_callback'   =>  'newscrunch_footer_widgets_callback',
            'priority'          =>  2,
            'choices'           => array(
                                    2 => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/2-col.png'),
                                    3 => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/3-col.png'),
                                    4 => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/4-col.png')   
                                )
        )
    ));

    // footer background image
    $wp_customize->add_setting( 'footer_widget_back_image', 
        array(
            'sanitize_callback' => 'esc_url_raw'
        ) 
    );      
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'footer_widget_back_image', 
        array(
            'label'             =>  esc_html__( 'Background Image', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'footer_widget_back_image',
            'active_callback'   =>  'newscrunch_footer_widgets_callback',
            'priority'          =>  3
        ) 
    ));

    // Enable / Disable footer overlay color
    $wp_customize->add_setting( 'footer_overlay_enable',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'footer_overlay_enable',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Image Overlay', 'newscrunch'  ),
            'settings'           =>  'footer_overlay_enable',
            'section'           =>  'newscrunch_theme_footer',
            'active_callback'   =>  'newscrunch_footer_widgets_callback',
            'type'              =>  'toggle',
            'priority'          =>  4
        )
    ));

    // Footer image overlay color
    $wp_customize->add_setting( 'footer_image_overlay_color', 
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'rgba(15, 11, 31, 0.9)'
        ) 
    );      
    $wp_customize->add_control(new Newscrunch_Customize_Alpha_Color_Control( $wp_customize,'footer_image_overlay_color', 
        array(
            'label'             =>  esc_html__('Widgets Image Overlay Color','newscrunch' ),
            'palette'           =>  true,
            'settings'           =>  'footer_image_overlay_color',
            'section'           =>  'newscrunch_theme_footer',
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_footer_widgets_callback($control) &&
                                            newscrunch_footer_overlay_callback($control)
                                        );
                                    },
            'priority'          =>  5
        )
    ));


    /* Theme Style Tab */
    // enable/disable the color
    $wp_customize->add_setting('hide_show_theme_footer_color',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_theme_footer_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'newscrunch_theme_footer',
            'settings'   =>  'hide_show_theme_footer_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // widget title color
    $wp_customize->add_setting('fwidgets_title_color', 
        array(
            'default'           => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'fwidgets_title_color', 
        array(
            'label'             =>  esc_html__('Widget Title Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'fwidgets_title_color',
            'active_callback'   =>  'newscrunch_theme_footer_color_callback',
            'priority'          =>  2
        )
    ));

    // widget text color
    $wp_customize->add_setting('fwidgets_text_color', 
        array(
            'default'           => '#858585',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'fwidgets_text_color', 
        array(
            'label'             =>  esc_html__('Text/Content Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'fwidgets_text_color',
            'active_callback'   =>  'newscrunch_theme_footer_color_callback',
            'priority'          =>  3
        )
    ));

    // widget link color
    $wp_customize->add_setting('fwidgets_link_color', 
        array(
            'default'           => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'fwidgets_link_color', 
        array(
            'label'             =>  esc_html__('Link/Post Title Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'fwidgets_link_color',
            'active_callback'   =>  'newscrunch_theme_footer_color_callback',
            'priority'          =>  4
        )
    ));

    // widget link hover color
    $wp_customize->add_setting('fwidgets_link_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'fwidgets_link_hover_color', 
        array(
            'label'             =>  esc_html__('Link/Post Title Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_theme_footer',
            'settings'           =>  'fwidgets_link_hover_color',
            'active_callback'   =>  'newscrunch_theme_footer_color_callback',
            'priority'          =>  5
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Footer_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/theme-footer/#theme-footer?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
        <?php }
    }
        
    $wp_customize->add_setting(
            'footer_pro_feature',
            array(
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control( new Newscrunch_Footer_Customize_Control( $wp_customize, 'footer_pro_feature', array(
                'section' => 'newscrunch_theme_footer',
                'setting' => 'footer_pro_feature',
                'priority' => 6
            )
    ));
    endif;

}
add_action( 'customize_register', 'newscrunch_theme_footer_panel_customizer' );