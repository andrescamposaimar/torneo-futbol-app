<?php
/**
 * Video Customizer
 *
 * @package Newscrunch
*/

function newscrunch_featured_video_panel_customizer ( $wp_customize ) {

	/* ====== FEATURED VIDEO SECTION ====== */
	$wp_customize->add_section('newscrunch_featured_video_section', 
		array(
			'title' 	=> esc_html__('Featured Video' , 'newscrunch' ),
			'priority' 	=> 24
		)
	);

    // enable/disable featured video
    $wp_customize->add_setting('hide_show_featured_video',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_featured_video',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Featured Video', 'newscrunch'),
            'section'   =>  'newscrunch_featured_video_section',
            'settings'  =>  'hide_show_featured_video',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

    // featured video section title
    $wp_customize->add_setting('featured_video_title',
        array(
            'default'           =>  esc_html__('Featured Video', 'newscrunch'),
            'transport'         => 'postMessage',
            'sanitize_callback' =>  'newscrunch_sanitize_text'
        )
    );
    $wp_customize->add_control('featured_video_title',
        array(
            'label'             =>  esc_html__('Title', 'newscrunch'),
            'section'           =>  'newscrunch_featured_video_section',
            'setting'           =>  'featured_video_title',
            'active_callback'   =>  'newscrunch_featured_video_callback',
            'priority'          =>  2,
            'type'              =>  'text'
        )
    );

    // select the featured video category
    $wp_customize->add_setting( 'featured_video_dropdown_category',
        array(
            'default'           =>  0,
            'sanitize_callback' =>  'absint'
        )
    );
    $wp_customize->add_control( new newscrunch_Dropdown_Category_Control( $wp_customize, 'featured_video_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_featured_video_section',
            'settings'          =>  'featured_video_dropdown_category',
            'active_callback'   =>  'newscrunch_featured_video_callback',
            'priority'          =>  3
        )
    ) );

    // select the featured video container width
    $wp_customize->add_setting('featured_video_section_width', 
        array(
            'default'           => 'default',
            'sanitize_callback' => 'newscrunch_select_text_sanitization'
        )
    );
    $wp_customize->add_control('featured_video_section_width', 
        array(      
            'label'             =>  esc_html__('Section', 'newscrunch' ),        
            'section'           =>  'newscrunch_featured_video_section',
            'settings'           =>  'featured_video_section_width',
            'active_callback'   =>  'newscrunch_featured_video_callback',
            'type'              =>  'select',
            'priority'          =>  3,
            'choices'           => array(
                    'default'   =>  esc_html__('Container', 'newscrunch'),
                    'full'    =>  esc_html__('Full Width', 'newscrunch')
            )
        )
    );

    // enable/disable featured video meta
    $wp_customize->add_setting('hide_show_featured_video_meta',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_featured_video_meta',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Meta', 'newscrunch'),
            'section'           =>  'newscrunch_featured_video_section',
            'settings'          =>  'hide_show_featured_video_meta',
            'active_callback'   =>  'newscrunch_featured_video_callback',
            'type'              =>  'toggle',
            'priority'          =>  4
        )
    ));


    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Video_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/front-sections/#featured-video?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
        <?php }
    }
        
    $wp_customize->add_setting(
            'video_pro_feature',
            array(
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control( new Newscrunch_Video_Customize_Control( $wp_customize, 'video_pro_feature', array(
                'section' => 'newscrunch_featured_video_section',
                'setting' => 'video_pro_feature',
                'priority' => 6
            )
    ));
    endif;



     /* ====== BLOG SECTION SETTING ====== */
    
    $wp_customize->add_section('newscrunch_blog_post_section', 
        array(
            'title'     => esc_html__('Blog Posts' , 'newscrunch' ),
            'priority'  => 24
        )
    );

    // enable/disable blog section
    $wp_customize->add_setting('hide_show_blog_post',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_blog_post',
        array(
            'label'     =>  esc_html__( 'Enable/Disable Blog Section', 'newscrunch'),
            'description' =>  esc_html__( 'Enable/Disable blog section on front page', 'newscrunch'),
            'section'   =>  'newscrunch_blog_post_section',
            'settings'   =>  'hide_show_blog_post',
            'type'      =>  'toggle',
            'priority'  =>  1
        )
    ));

}
add_action( 'customize_register', 'newscrunch_featured_video_panel_customizer' );