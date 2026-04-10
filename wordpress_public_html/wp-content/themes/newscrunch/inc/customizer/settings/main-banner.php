<?php
/**
 * Banner Customizer
 *
 * @package Newscrunch
*/

function newscrunch_main_banner_panel_customizer ( $wp_customize ) {

    /* ====== BANNER SECTION ====== */
    $wp_customize->add_section('newscrunch_main_banner_section', 
        array(
            'title'     => esc_html__('Main Banner' , 'newscrunch' ),
            'priority'  => 22
        )
    );

    // enable/disable banner
    $wp_customize->add_setting('hide_show_banner',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_banner',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Banner', 'newscrunch'),
            'section'   =>  'newscrunch_main_banner_section',
            'settings'   =>  'hide_show_banner',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // Shortcode
    $wp_customize->add_setting('spnc_spsp_shortcode',
        array(
            'default'           => '',
            'sanitize_callback' => 'newscrunch_sanitize_text'
        )
    );
    $wp_customize->add_control( 'spnc_spsp_shortcode',
        array(
            'label'     =>  esc_html__( 'Enter spice slider shortcode here', 'newscrunch'),
            'description'=>  esc_html__( 'Enter either spice post slider shortcode or pro package shortcode here', 'newscrunch'),
            'section'   =>  'newscrunch_main_banner_section',
            'settings'   =>  'spnc_spsp_shortcode',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'type'      =>  'text',
            'priority'  =>  1
        )
    );

    if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
        $default = array( 'reorder_left', 'reorder_center', 'reorder_right');
    }
    else{
        $default = array( 'reorder_left', 'reorder_right', 'reorder_center');
    }
    
    $choices = array(
        'reorder_left' => __('Left Column','newscrunch'),
        'reorder_center' => __('Center Column (Slider)','newscrunch'),
        'reorder_right' => __('Right Column','newscrunch'),
    );
    $wp_customize->add_setting( 'banner_sort',
    array(
        'capability'  => 'edit_theme_options',
        'sanitize_callback' => 'newscrunch_sanitize_array',
        'default'     => $default
    ) );

    $wp_customize->add_control( new Newscrunch_Control_Sortable( $wp_customize, 'banner_sort',
    array(
        'label' => esc_html__('Column Reorder','newscrunch'),
        'section' => 'newscrunch_main_banner_section',
        'active_callback'   =>  'newscrunch_main_banner_callback',
        'settings' => 'banner_sort',
        'type'=> 'sortable',
        'priority'  =>  1,
        'choices'     => $choices
    ) ) );

    /* LEFT BANNER */
    // select the banner left category
    $wp_customize->add_setting( 'banner_left_dropdown_category',
        array(
            'default'           =>  0,
            'sanitize_callback' =>  'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Dropdown_Category_Control( $wp_customize, 'banner_left_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'           =>  'banner_left_dropdown_category',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'priority'          =>  2
        )
    ) );

    // select the banner left post order
    $wp_customize->add_setting('banner_left_post_order', 
        array(
            'default'           => 'DESC',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('banner_left_post_order', 
        array(      
            'label'             =>  esc_html__('Orderby', 'newscrunch' ),        
            'section'           =>  'newscrunch_main_banner_section',
            'settings'           =>  'banner_left_post_order',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'type'              =>  'select',
            'priority'          =>  3,
            'choices'           => array(
                    'DESC'   =>  esc_html__('Newest', 'newscrunch') . ' - ' . esc_html__('Oldest', 'newscrunch' ),
                    'ASC'    =>  esc_html__('Oldest', 'newscrunch') . ' - ' . esc_html__('Newest', 'newscrunch' )
            )
        )
    );

    // enable/disable left banner meta
    $wp_customize->add_setting('hide_show_banner_left_meta',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_banner_left_meta',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Meta', 'newscrunch'),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'           =>  'hide_show_banner_left_meta',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'type'              =>  'toggle',
            'priority'          =>  4
        )
    ));

    /* CENTER BANNER */
    // select the banner center category
    $wp_customize->add_setting( 'banner_center_dropdown_category',
        array(
            'default'           =>  1,
            'sanitize_callback' =>  'newscrunch_select_text_sanitization',
        )
    );
    $wp_customize->add_control( new Newscrunch_Multiple_Category_Dropdown_Custom_Control( $wp_customize, 'banner_center_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'          =>  'banner_center_dropdown_category',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'priority'          =>  5,
            'input_attrs'       =>  array(
                    'placeholder' => esc_html__('Select Category', 'newscrunch'),
                    'multiselect' => true,
            ),
        )
    ) );

    // banner center number of posts
    $wp_customize->add_setting( 'banner_center_num_posts', 
        array(
            'default'           =>  3,
            'sanitize_callback' =>  'absint',
        ) 
    );
    // Add control
    $wp_customize->add_control( new WP_Customize_Control($wp_customize,'banner_center_num_posts',
        array(
            'label'             =>  esc_html__('Number of posts to show', 'newscrunch'),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'          =>  'banner_center_num_posts',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'type'              =>  'number',
            'priority'          =>  6,
        )
    ));

    // select the banner center post order
    $wp_customize->add_setting('banner_center_post_order', 
        array(
            'default'           => 'DESC',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('banner_center_post_order', 
        array(      
            'label'             =>  esc_html__('Orderby', 'newscrunch' ),        
            'section'           =>  'newscrunch_main_banner_section',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'settings'          =>  'banner_center_post_order',
            'type'              =>  'select',
            'priority'          =>  7,
            'choices'           =>  array(
                    'DESC'   =>  esc_html__('Newest', 'newscrunch') . ' - ' . esc_html__('Oldest', 'newscrunch' ),
                    'ASC'    =>  esc_html__('Oldest', 'newscrunch') . ' - ' . esc_html__('Newest', 'newscrunch' )
            )
        )
    );

    // enable/disable center banner meta
    $wp_customize->add_setting('hide_show_banner_center_meta',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_banner_center_meta',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Meta', 'newscrunch'),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'          =>  'hide_show_banner_center_meta',
            'active_callback'   =>  'newscrunch_main_banner_callback',     
            'type'              =>  'toggle',
            'priority'          =>  8
        )
    ));

    // enable/disable center banner Read More button
    $wp_customize->add_setting('hide_show_banner_center_read_more',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_banner_center_read_more',
        array(
            'label'             =>  esc_html__( 'Hide/Show Read More', 'newscrunch'),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'          =>  'hide_show_banner_center_read_more',
            'active_callback'   =>  'newscrunch_main_banner_callback',     
            'type'              =>  'toggle',
            'priority'          =>  9
        )
    ));


    /* RIGHT BANNER */
    // select the banner right category
    $wp_customize->add_setting( 'banner_right_dropdown_category',
        array(
            'default'           =>  0,
            'sanitize_callback' =>  'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Dropdown_Category_Control( $wp_customize, 'banner_right_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_main_banner_section',
            'settings'          =>  'banner_right_dropdown_category',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'priority'          =>  10,
        )
    ) );

    // select the banner right post order
    $wp_customize->add_setting('banner_right_post_order', 
        array(
            'default'           => 'ASC',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('banner_right_post_order', 
        array(      
            'label'             =>  esc_html__('Orderby', 'newscrunch' ),        
            'section'           =>  'newscrunch_main_banner_section',
            'settings'           =>  'banner_right_post_order',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'type'              =>  'select',
            'priority'          =>  11,
            'choices'           =>  array(
                    'DESC'   =>  esc_html__('Newest', 'newscrunch') . ' - ' . esc_html__('Oldest', 'newscrunch' ),
                    'ASC'    =>  esc_html__('Oldest', 'newscrunch') . ' - ' . esc_html__('Newest', 'newscrunch' )
            )
        )
    );

    // enable/disable right banner meta
    $wp_customize->add_setting('hide_show_banner_right_meta',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_banner_right_meta',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Meta', 'newscrunch'),
            'section'           =>  'newscrunch_main_banner_section',
            'active_callback'   =>  'newscrunch_main_banner_callback',
            'settings'           =>  'hide_show_banner_right_meta',
            'type'              =>  'toggle',
            'priority'          =>  12
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Banner_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/front-sections/#main-banner?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
        <?php }
    }
        
    $wp_customize->add_setting(
            'banner_pro_feature',
            array(
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control( new Newscrunch_Banner_Customize_Control( $wp_customize, 'banner_pro_feature', array(
                'section' => 'newscrunch_main_banner_section',
                'setting' => 'banner_pro_feature',
                'priority' => 13
            )
    ));
    endif;
    
}
add_action( 'customize_register', 'newscrunch_main_banner_panel_customizer' );