<?php
/**
 * Top Header Panel Customizer
 *
 * @package Newscrunch
*/

function newscrunch_archives_options_customizer ( $wp_customize ) {

    /* ====== Blog options ====== */
	$wp_customize->add_section('newscrunch_blog_section', 
		array(
			'title' 	=> esc_html__('Blog/Archives', 'newscrunch' ),
			'priority' 	=> 28
		)
	);

    if('NewsBlogger' == wp_get_theme()) { $blog='list'; } else {$blog='grid';}

    // index blog layouts
    $wp_customize->add_setting( 'archive_blog_variation',
        array(
            'default'           => $blog,
            'sanitize_callback' => 'newscrunch_sanitize_select'
        )
    );

    if ( class_exists('Newscrunch_Plus') ):

        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'archive_blog_variation',
            array(
                'label'         =>  esc_html__( 'Blog Variation', 'newscrunch'  ),
                'priority'      =>  1,
                'section'       =>  'newscrunch_blog_section',
                'choices'       =>  array(
                    'grid'    => array(
                        'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog/grid.png'
                    ),
                    'list'    => array(
                        'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog/list.png',
                    ),
                    'overlay'    => array(
                        'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog/overlay.jpg',
                    )
                )
            )
        ) );

    else: 

        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'archive_blog_variation',
            array(
                'label'         =>  esc_html__( 'Blog Variation', 'newscrunch'  ),
                'priority'      =>  1,
                'section'       =>  'newscrunch_blog_section',
                'choices'       =>  array(
                    'grid'    => array(
                        'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog/grid.png'
                    ),
                    'list'    => array(
                        'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog/list.png',
                    )
                )
            )
        ) );

    endif;
   
    // select number of posts
    $wp_customize->add_setting( 'newscrunch_blog_posts_per_page', 
        array(
            'default'           =>  10,
            'sanitize_callback' =>  'absint',
        ) 
    );
    $wp_customize->add_control( new WP_Customize_Control($wp_customize,'newscrunch_blog_posts_per_page',
        array(
            'label'             =>  esc_html__('Number of posts to show', 'newscrunch'),
            'section'           =>  'newscrunch_blog_section',
            'settings'          =>  'newscrunch_blog_posts_per_page',
            'type'              =>  'number',
            'priority'          =>  1,
        )
    ));


    // enable/disable author
    $wp_customize->add_setting('newscrunch_enable_post_author',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_author',
        array(
            'label'     => esc_html__('Hide/Show Author', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 2
        )
    ));

    // enable/disable tag
    $wp_customize->add_setting('newscrunch_enable_post_tag',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_tag',
        array(
            'label'     => esc_html__('Hide/Show Tag', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 3
        )
    ));

    // enable/disable category
    $wp_customize->add_setting('newscrunch_enable_post_category',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_category',
        array(
            'label'     => esc_html__('Hide/Show Categories', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 4
        )
    ));

    // enable/disable comment
    $wp_customize->add_setting('newscrunch_enable_post_comment',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_comment',
        array(
            'label'     => esc_html__('Hide/Show Comments', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 5
        )
    ));

    // enable/disable title
    $wp_customize->add_setting('newscrunch_enable_post_title',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_title',
        array(
            'label'     => esc_html__('Hide/Show Title', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 6
        )
    ));

    // enable/disable description
    $wp_customize->add_setting('newscrunch_enable_post_description',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_description',
        array(
            'label'     => esc_html__('Hide/Show Description', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 7
        )
    ));

    // enable/disable date
    $wp_customize->add_setting('newscrunch_enable_post_date',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_date',
        array(
            'label'     => esc_html__('Hide/Show Date', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 8
        )
    ));

    // enable/disable read more
    $wp_customize->add_setting('newscrunch_enable_post_read_more',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_post_read_more',
        array(
            'label'     => esc_html__('Hide/Show Read More', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_blog_section',
            'priority'  => 9
        )
    ));

}
add_action( 'customize_register', 'newscrunch_archives_options_customizer' );