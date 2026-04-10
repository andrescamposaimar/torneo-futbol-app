<?php
/**
 * Top Header Panel Customizer
 *
 * @package Newscrunch
*/

function newscrunch_top_header_panel_customizer ( $wp_customize ) {

    $wp_customize->add_panel('newscrunch_top_header_panel',
        array(
            'title'         => esc_html__('Top Header', 'newscrunch' ),
            'priority'      => 4
        )
    );

    /* ====== DATA TIME SECTION ====== */
    $wp_customize->add_section('newscrunch_date_time_section', 
        array(
            'title'     => esc_html__('Top Left' , 'newscrunch' ),
            'panel'     => 'newscrunch_top_header_panel',
            'priority'  => 1
        )
    );

    //date time tabs
    $wp_customize->add_setting( 'contatc_info_section_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'contatc_info_section_tab', 
        array(
            'section'   => 'newscrunch_date_time_section',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_date',
                        'hide_show_time',
                        'top_left_variation',
                        'spncp_left_social',
                        'hide_show_left_trending',
                        'spnc_left_trending',
                        'hide_show_left_social_icons',
                        'topbar_left_social_icons',
                        'headertopleft_pro_feature'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_date_time_color',
                        'date_color',
                        'time_color',
                        'hide_show_left_social_icon_color',
                        'left_social_icon_color',
                        'left_social_icon_hover_color',
                        'left_social_icon_bg_color',
                        'left_social_icon_bg_hover_color',
                        'hide_show_left_trend',
                        'hide_show_left_trend_title_color',
                        'hide_show_left_trend_post_color',
                        'date_dcolor',
                        'time_dcolor'
                    ),
                ),
            ),
        )
    ));

    /* Date Time General Tab */
    // enable/disable date
    $wp_customize->add_setting('hide_show_date',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_date',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Date', 'newscrunch'),
            'section'   =>  'newscrunch_date_time_section',
            'settings'  =>  'hide_show_date',
            'type'      =>  'toggle',
            'priority'  =>  2
        )
    ));
    // enable/disable time
    $wp_customize->add_setting('hide_show_time',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_time',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Time', 'newscrunch'),
            'section'   =>  'newscrunch_date_time_section',
            'settings'   =>  'hide_show_time',
            'type'      =>  'toggle',
            'priority'  =>  2
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):

    class Newscrunch_HeaderTopLeft_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/theme-header/#top-header?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
            <?php }
        }
        
        $wp_customize->add_setting(
            'headertopleft_pro_feature',
            array(
                'capability'     => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control( new Newscrunch_HeaderTopLeft_Customize_Control( $wp_customize, 'headertopleft_pro_feature', array(
                'section' => 'newscrunch_date_time_section',
                'setting' => 'headertopleft_pro_feature',
                'priority' => 3
            )
        ));

    endif;

    /* Date & Time Style Tab */
    // enable/disable the date/time color
    $wp_customize->add_setting('hide_show_date_time_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_date_time_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'newscrunch_date_time_section',
            'settings'  =>  'hide_show_date_time_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));
    // Date color
    $wp_customize->add_setting('date_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'date_color', 
        array(
            'label'             =>  esc_html__('Date Color', 'newscrunch' ),
            'section'           =>  'newscrunch_date_time_section',
            'settings'           =>  'date_color',
            'priority'          =>  2,
            'active_callback'   =>  'newscrunch_date_time_color_callback'
        )
    ));
    // Time color
    $wp_customize->add_setting('time_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'time_color', 
        array(
            'label'             =>  esc_html__('Time Color', 'newscrunch' ),
            'section'           =>  'newscrunch_date_time_section',
            'settings'           =>  'time_color',
            'priority'          =>  3,
            'active_callback'   =>  'newscrunch_date_time_color_callback'
        )
    ));

    // Date color for dark layout
    $wp_customize->add_setting('date_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color'
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'date_dcolor', 
        array(
            'label'             =>  esc_html__('Date Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'newscrunch_date_time_section',
            'settings'           =>  'date_dcolor',
            'priority'          =>  4,
            'active_callback'   =>  'newscrunch_date_time_color_callback'
        )
    ));
    // Time color for dark layout
    $wp_customize->add_setting('time_dcolor', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'time_dcolor', 
        array(
            'label'             =>  esc_html__('Time Color (Dark Layout)', 'newscrunch' ),
            'section'           =>  'newscrunch_date_time_section',
            'settings'           =>  'time_dcolor',
            'priority'          =>  5,
            'active_callback'   =>  'newscrunch_date_time_color_callback'
        )
    ));



    /* ====== SOCIAL ICON SECTION ====== */
    $wp_customize->add_section('newscrunch_social_icon_section', 
        array(
            'title'     => esc_html__('Top Right' , 'newscrunch' ),
            'panel'     => 'newscrunch_top_header_panel',
            'priority'  => 2
        )
    );
    //contact info tabs
    $wp_customize->add_setting( 'social_icon_section_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'social_icon_section_tab', 
        array(
            'section'   => 'newscrunch_social_icon_section',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_social_icons',
                        'social_icons',
                        'top_right_variation',
                        'hide_show_right_date',
                        'hide_show_right_time',
                        'hide_show_right_trending',
                        'spnc_right_trending',
                        'headertopright_pro_feature'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_social_icon_color',
                        'social_icon_color',
                        'social_icon_hover_color',
                        'social_icon_bg_color',
                        'social_icon_bg_hover_color',
                        'hide_show_right_date_time_color',
                        'right_date_color',
                        'right_time_color',
                        'hide_show_right_trend',
                        'hide_show_right_trend_title_color',
                        'hide_show_right_trend_post_color'
                    ),
                ),
            ),
        )
    ));

    /* Social Icon General Tab */
    // enable/disable social icons
    $wp_customize->add_setting('hide_show_social_icons',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_social_icons',
        array(
            'label'    => esc_html__( 'Enable/Disable Social Icons', 'newscrunch'),
            'section'  => 'newscrunch_social_icon_section',
            'type'     => 'toggle',
            'priority' => 6
        )
    ));
    // social icons
    if ( class_exists( 'Newscrunch_Repeater_Control' ) ) 
    {
        $wp_customize->add_setting( 'social_icons', array(
            'sanitize_callback' => 'sanitize_text_field'
        ) );

        $wp_customize->add_control( new Newscrunch_Repeater_Control( $wp_customize, 'social_icons', 
            array(
                'label'                                 =>  esc_html__( 'Social Icon Details', 'newscrunch' ),
                'section'                               =>  'newscrunch_social_icon_section',
                'priority'                              =>  6,
                'add_field_label'                       =>  esc_html__( 'Add Icon', 'newscrunch' ),
                'item_name'                             =>  esc_html__( 'Social icons', 'newscrunch' ),
                'customizer_repeater_icon_control'      =>  true,
                'customizer_repeater_link_control'      =>  true,
                'customizer_repeater_checkbox_control'  =>  true,
                'active_callback'                       =>  'newscrunch_social_icons_callback'
            ) 
        ) );
    }

    /* Social Icon Style Tab */
    // enable/disable the contact color
    $wp_customize->add_setting('hide_show_social_icon_color',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_social_icon_color',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Color', 'newscrunch'),
            'section'   =>  'newscrunch_social_icon_section',
            'settings'   =>  'hide_show_social_icon_color',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));
    // social icon color
    $wp_customize->add_setting('social_icon_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'social_icon_color', 
        array(
            'label'             =>  esc_html__('Icon Color', 'newscrunch' ),
            'section'           =>  'newscrunch_social_icon_section',
            'settings'           =>  'social_icon_color',
            'active_callback'   =>  'newscrunch_social_icons_color_callback',
            'priority'          =>  2
        )
    ));
    // social icon color
    $wp_customize->add_setting('social_icon_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'social_icon_hover_color', 
        array(
            'label'             =>  esc_html__('Icon Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_social_icon_section',
            'settings'           =>  'social_icon_hover_color',
            'active_callback'   =>  'newscrunch_social_icons_color_callback',
            'priority'          =>  3
        )
    ));
    // social icon background color
    $wp_customize->add_setting('social_icon_bg_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'social_icon_bg_color', 
        array(
            'label'             =>  esc_html__('Icon Background Color', 'newscrunch' ),
            'section'           =>  'newscrunch_social_icon_section',
            'settings'           =>  'social_icon_bg_color',
            'active_callback'   =>  'newscrunch_social_icons_color_callback',
            'priority'          =>  4
        )
    ));
    // social icon background hover color
    $wp_customize->add_setting('social_icon_bg_hover_color', 
        array(
            'default'           => '#',
            'sanitize_callback' => 'sanitize_hex_color',
        )
    );
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'social_icon_bg_hover_color', 
        array(
            'label'             =>  esc_html__('Icon Background Hover Color', 'newscrunch' ),
            'section'           =>  'newscrunch_social_icon_section',
            'settings'           =>  'social_icon_bg_hover_color',
            'active_callback'   =>  'newscrunch_social_icons_color_callback',
            'priority'          =>  5
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_HeaderTopRight_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/theme-header/#top-header?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
            <?php }
    }
        
    $wp_customize->add_setting(
        'headertopright_pro_feature',
        array(
            'capability'     => 'edit_theme_options',
            'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control( new Newscrunch_HeaderTopRight_Customize_Control( $wp_customize, 'headertopright_pro_feature', array(
            'section' => 'newscrunch_social_icon_section',
            'setting' => 'headertopright_pro_feature',
            'priority' => 7
        )
    ));
    endif;
    
}
add_action( 'customize_register', 'newscrunch_top_header_panel_customizer' );