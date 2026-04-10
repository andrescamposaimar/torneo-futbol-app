<?php
/**
 * Woocommerce Panel/Section
 *
 * @package Newscrunch
*/

function newscrunch_wc_options_customizer ( $wp_customize ) {
    /* =======================================
    * Woocommerce Product Catalog
    ======================================= */
    $wp_customize->add_setting('newscrunch_wc_product_hover',
        array(
            'default'           =>  'none',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control('newscrunch_wc_product_hover', 
        array(
            'label'             => esc_html__('Product Image Hover','newscrunch' ),
            'section'           => 'woocommerce_product_catalog',
            'setting'           => 'newscrunch_wc_product_hover',
            'type'              => 'select',
            'choices'           =>  
            array(
                'none'          =>  esc_html__('No Effect', 'newscrunch' ),
                'image-swap'    =>  esc_html__('Image Swap', 'newscrunch' )
            )
        )
    );

    // Add Product Sale Badge Setting
    $wp_customize->add_setting('newscrunch_wc_product_sale_badge', 
        array(
            'default'           => 'percentage',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_select',
        )
    );

    // Add Product Sale Badge Control
    $wp_customize->add_control('newscrunch_wc_product_sale_badge',
        array(
            'label'             => esc_html__('Product Sale Badge', 'newscrunch'),
            'section'           => 'woocommerce_product_catalog',
            'setting'           => 'newscrunch_wc_product_sale_badge',
            'type'              => 'select',
            'choices'           => array(
                'hide'          => esc_html__('Hide Badge', 'newscrunch'),
                'percentage'    => esc_html__('Show Percentage', 'newscrunch'),
                'text'          => esc_html__('Show Text', 'newscrunch'),
            ),
        )
    );

    // Add Sale Badge Text Setting
    $wp_customize->add_setting('newscrunch_wc_product_sale_badge_text', 
        array(
            'default'           => esc_html__('Sale', 'newscrunch'),
            'sanitize_callback' => 'newscrunch_sanitize_text',
            'transport'         => 'postMessage'
        )
    );

    // Add Sale Badge Text Control
    $wp_customize->add_control('newscrunch_wc_product_sale_badge_text',
        array(
            'label'             => esc_html__('Sale Badge Text', 'newscrunch'),
            'section'           => 'woocommerce_product_catalog',
            'settings'          => 'newscrunch_wc_product_sale_badge_text',
            'type'              => 'text',
            'active_callback'   => 'newscrunch_show_text_active_callback'
        )
    );

    $default = array( 'newscrunch_wc_title_reorder', 'newscrunch_wc_price_reorder', 'newscrunch_wc_rating_reorder' );
    
    $choices = array(
        'newscrunch_wc_title_reorder' => esc_html__('Title','newscrunch'),
        'newscrunch_wc_price_reorder' => esc_html__('Price','newscrunch'),
        'newscrunch_wc_rating_reorder'=> esc_html__('Ratings','newscrunch')
    );
    
    $wp_customize->add_setting( 'newscrunch_wc_sort',
    array(
        'capability'            => 'edit_theme_options',
        'sanitize_callback'     => 'newscrunch_sanitize_array',
        'transport'             => 'refresh',
        'default'               => $default
    ) );

    $wp_customize->add_control( new Newscrunch_Control_Sortable( $wp_customize, 'newscrunch_wc_sort',
    array(
        'label'                 => esc_html__('Product details','newscrunch'),
        'section'               => 'woocommerce_product_catalog',
        'settings'              => 'newscrunch_wc_sort',
        'type'                  => 'sortable',
        'choices'               => $choices
    ) ) );


    /* =======================================
    * Woocommerce Single Product Section  
    ======================================= */
    $wp_customize->add_section( 'newscrunch_wc_single_product_section' , 
        array(
            'title'             => esc_html__('Single Product', 'newscrunch' ),
            'panel'             => 'woocommerce'
        )
    );

    // Enable/Disable Gallery Zoom
    $wp_customize->add_setting('newscrunch_wc_gallery_zoom',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_gallery_zoom',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Gallery Zoom', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_gallery_zoom',
            'type'              =>  'toggle',
            'priority'          =>  1
        )
    ));

    // Enable/Disable Gallery Lightbox
    $wp_customize->add_setting('newscrunch_wc_gallery_lightbox',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_gallery_lightbox',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Gallery Lightbox', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_gallery_lightbox',
            'type'              =>  'toggle',
            'priority'          =>  2
        )
    ));

    // Enable/Disable Slider Arrows
    $wp_customize->add_setting('newscrunch_wc_gallery_slide_arrow',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_gallery_slide_arrow',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Slider Arrows', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_gallery_slide_arrow',
            'type'              =>  'toggle',
            'priority'          =>  3
        )
    ));

    // enable/disable Related Products
    $wp_customize->add_setting('newscrunch_wc_related_product',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_related_product',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Related Products', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_related_product',
            'type'              =>  'toggle',
            'priority'          =>  4
        )
    ));

    // Related Products Columns
    $wp_customize->add_setting( 'newscrunch_wc_related_product_col',
        array(
            'default'           => 4,
            'transport'         => 'refresh',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_wc_related_product_col',
        array(
            'label'             =>  esc_html__('Related Products Columns', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'setting'           =>  'newscrunch_wc_related_product_col',
            'active_callback'   => 'newscrunch_related_product_section_callback',
            'priority'          =>  5,
            'input_attrs'       => 
                array(
                    'min'       =>  1,
                    'max'       =>  6,
                    'step'      =>  1
                )
        )
    ));

    // Related Products Row
    $wp_customize->add_setting( 'newscrunch_wc_related_product_row',
        array(
            'default'           => 4,
            'transport'         => 'refresh',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_wc_related_product_row',
        array(
            'label'             =>  esc_html__('Related Products Rows', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'setting'           =>  'newscrunch_wc_related_product_row',
            'active_callback'   => 'newscrunch_related_product_section_callback',
            'priority'          =>  5,
            'input_attrs'       => 
                array(
                    'min'       =>  1,
                    'max'       =>  5,
                    'step'      =>  1
                )
        )
    ));

    // enable/disable Up-Sell Products
    $wp_customize->add_setting('newscrunch_wc_upsell_product',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_upsell_product',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Up-Sell Products', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_upsell_product',
            'type'              =>  'toggle',
            'priority'          =>  6
        )
    ));

    // Up-Sell Products Columns
    $wp_customize->add_setting( 'newscrunch_wc_upsell_col',
        array(
            'default'           => 4,
            'transport'         => 'refresh',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_wc_upsell_col',
        array(
            'label'             =>  esc_html__('Up-Sell Products Columns', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'setting'           =>  'newscrunch_wc_upsell_col',
            'active_callback'   =>  'newscrunch_upsell_product_section_callback',
            'priority'          =>  7,
            'input_attrs'       => 
                array(
                    'min'       =>  1,
                    'max'       =>  6,
                    'step'      =>  1
                )
        )
    ));

    // Up-Sell Products Rows
    $wp_customize->add_setting( 'newscrunch_wc_upsell_row',
        array(
            'default'           => 4,
            'transport'         => 'refresh',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_wc_upsell_row',
        array(
            'label'             =>  esc_html__('Up-Sell Products Rows', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'setting'           =>  'newscrunch_wc_upsell_row',
            'active_callback'   =>  'newscrunch_upsell_product_section_callback',
            'priority'          =>  8,
            'input_attrs'       => 
                array(
                    'min'       =>  1,
                    'max'       =>  6,
                    'step'      =>  1
                )
        )
    ));

    // enable/disable Cross-Sell Products
    $wp_customize->add_setting('newscrunch_wc_cross_sell_product',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_wc_cross_sell_product',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Cross-Sell Products', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'settings'          =>  'newscrunch_wc_cross_sell_product',
            'type'              =>  'toggle',
            'priority'          =>  9
        )
    ));

    // Cross-Sell Products Rows
    $wp_customize->add_setting( 'newscrunch_wc_cross_sell_row',
        array(
            'default'           => 4,
            'transport'         => 'refresh',
            'sanitize_callback' => 'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Slider_Custom_Control( $wp_customize, 'newscrunch_wc_cross_sell_row',
        array(
            'label'             =>  esc_html__('Cross-Sell Products Rows', 'newscrunch'),
            'section'           =>  'newscrunch_wc_single_product_section',
            'setting'           =>  'newscrunch_wc_cross_sell_row',
            'active_callback'   =>  'newscrunch_sell_product_section_callback',
            'priority'          =>  10,
            'input_attrs'       => 
                array(
                    'min'       =>  1,
                    'max'       =>  6,
                    'step'      =>  1
                )
        )
    ));

}
add_action( 'customize_register', 'newscrunch_wc_options_customizer' );