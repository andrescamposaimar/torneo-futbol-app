<?php
/**
 * Advertisement Customizer Panel/Section
 *
 * @package Newscrunch
*/

function newscrunch_advertisement_options_customizer ( $wp_customize ) {
    /* ====================
    * Advertisement section  
    ==================== */
    $wp_customize->add_section( 'advertisement_section' , 
        array(
            'title'      => esc_html__('Advertisement', 'newscrunch' ),
            'priority'   => 1,
        )
    );

    /* ===============================
    * Advertisement Repeater Control 
    =============================== */
    if ( class_exists( 'Newscrunch_Repeater_Control' ) ) 
    {
        $wp_customize->add_setting( 'advertisement_items', array(
            'sanitize_callback' => 'sanitize_text_field'
        ) );

        $wp_customize->add_control( new Newscrunch_Repeater_Control( $wp_customize, 'advertisement_items', 
            array(
                'section'                                               =>  'advertisement_section',
                'priority'                                              =>  2,
                'item_name'                                             =>  esc_html__( 'Advertisement Items', 'newscrunch' ),
                'customizer_repeater_image_control'                     =>  true,
                'customizer_repeater_link_control'                      =>  true,
                'customizer_repeater_checkbox_control'                  =>  true,
                'customizer_repeater_ad_location_control'               =>  true,
                'customizer_repeater_before_header_control'             =>  true,
                'customizer_repeater_after_header_control'              =>  true,
                'customizer_repeater_inside_header_control'             =>  true,
                'customizer_repeater_before_post_archive_control'       =>  true,
                'customizer_repeater_before_post_content_control'       =>  true,
                'customizer_repeater_after_content_control'             =>  true,
                'customizer_repeater_before_footer_control'             =>  true,
                'customizer_repeater_after_footer_control'              =>  true,
                'customizer_repeater_ad_visibility_control'             =>  true
            ) 
        ) );

    }

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Ads_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/advertisement/?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
        <?php }
    }
        
    $wp_customize->add_setting(
            'ads_pro_feature',
            array(
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control( new Newscrunch_Ads_Customize_Control( $wp_customize, 'ads_pro_feature', array(
                'section' => 'advertisement_section',
                'priority' => 3
            )
    ));
    endif;
}
add_action( 'customize_register', 'newscrunch_advertisement_options_customizer' );