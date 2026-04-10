<?php
/**
 * Missed Section Customizer
 *
 * @package Newscrunch
*/

function newscrunch_missed_section_customizer ( $wp_customize ) {

    /* ====== Missed Section ====== */
    $wp_customize->add_section('newscrunch_missed_section', 
        array(
            'title'     => esc_html__('Missed Section' , 'newscrunch' ),
            'priority'  => 26
        )
    );

    //contact info tabs
    $wp_customize->add_setting( 'newscrunch_missed_tab', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'general'
    ));
    $wp_customize->add_control( new Newscrunch_Customize_Control_Tabs( $wp_customize, 'newscrunch_missed_tab', 
        array(
            'section'   => 'newscrunch_missed_section',
            'tabs'    => array(
                'general'    => array(
                    'nicename' => esc_html__( 'General', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_missed_section',
                        'misssed_section_width',
                        'missed_section_title',
                        'misssed_section_layouts',
                        'missed_section_dropdown_category',
                        'missed_section_num_posts',
                        'missed_animation_speed',
                        'missed_section_autoplay',
                        'missed_section_item',
                        'missed_section_nav',
                        'missed_section_bullets',
                        'missed_section_post_order',
                        'hide_show_missed_section_meta',
                        'missed_section_back_image',
                        'missed_section_overlay_enable',
                        'missed_section_overlay',
                        'missed_view_btn_title',
                        'missed_view_btn_url',
                        'missed_view_btn_redirect'
                    ),
                ),
                'style' => array(
                    'nicename' => esc_html__( 'Style', 'newscrunch' ),
                    'controls' => array(
                        'hide_show_missed_section_color',
                        'missed_title_color',
                        'missed_category_color',
                        'missed_title_dcolor',
                        'missed_category_dcolor'
                    ),
                ),
            ),
        )
    ));

    // enable/disable Missed Section
    $wp_customize->add_setting('hide_show_missed_section',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_missed_section',
        array(
            'label'     =>  esc_html__('Enable/Disable Missed Section', 'newscrunch'),
            'section'   =>  'newscrunch_missed_section',
            'settings'  =>  'hide_show_missed_section',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    
    // Missed Section Title
    $wp_customize->add_setting('missed_section_title', 
        array(
            'default'           =>  esc_html__('You May Have Missed', 'newscrunch'),
            'sanitize_callback' =>  'newscrunch_sanitize_text',
            'transport'         =>  'postMessage',
        )
    );
    $wp_customize->add_control( 'missed_section_title',
        array(
            'label'             =>  esc_html__('Heading', 'newscrunch'),
            'section'           =>  'newscrunch_missed_section',
            'settings'          =>  'missed_section_title',
            'type'              =>  'text',
            'active_callback'   =>  'newscrunch_missed_section_callback',
            'priority'          =>  2
        )
    );

    // select the banner center category
    $wp_customize->add_setting( 'missed_section_dropdown_category',
        array(
            'default'           =>  1,
            'sanitize_callback' =>  'newscrunch_select_text_sanitization',
        )
    );
    $wp_customize->add_control( new Newscrunch_Multiple_Category_Dropdown_Custom_Control( $wp_customize, 'missed_section_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_missed_section',
            'settings'          =>  'missed_section_dropdown_category',
            'active_callback'   =>  'newscrunch_missed_section_callback',
            'priority'          =>  5,
            'input_attrs'       =>  array(
                    'placeholder' => esc_html__('Select Category', 'newscrunch'),
                    'multiselect' => true,
            ),
        )
    ) );

    // select the banner center post order
    $wp_customize->add_setting('missed_section_post_order', 
        array(
            'default'           => 'DESC',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('missed_section_post_order', 
        array(      
            'label'             =>  esc_html__('Orderby', 'newscrunch' ),        
            'section'           =>  'newscrunch_missed_section',
            'active_callback'   =>  'newscrunch_missed_section_callback',
            'settings'          =>  'missed_section_post_order',
            'type'              =>  'select',
            'priority'          =>  7,
            'choices'           =>  array(
                    'DESC'  =>  esc_html__('Newest', 'newscrunch') . ' - ' . esc_html__('Oldest', 'newscrunch' ),
                    'ASC'   =>  esc_html__('Oldest', 'newscrunch') . ' - ' . esc_html__('Newest', 'newscrunch' )
            )
        )
    );

    // enable/disable center banner meta
    $wp_customize->add_setting('hide_show_missed_section_meta',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_missed_section_meta',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Meta', 'newscrunch'),
            'section'           =>  'newscrunch_missed_section',
            'settings'          =>  'hide_show_missed_section_meta',
            'active_callback'   =>  'newscrunch_missed_section_callback',     
            'type'              =>  'toggle',
            'priority'          =>  8
        )
    ));

}
add_action( 'customize_register', 'newscrunch_missed_section_customizer' );