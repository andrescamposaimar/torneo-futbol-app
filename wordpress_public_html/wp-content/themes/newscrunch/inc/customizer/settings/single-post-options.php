<?php
/**
 * Single Blog Options Customizer Customizer
 *
 * @package Newscrunch Theme
*/

function newscrunch_single_blog_customizer($wp_customize) {

    $wp_customize->add_section('newscrunch_single_blog_section',
        array(
            'title'     => esc_html__('Single Post', 'newscrunch' ),
            'priority'  => 29
        )
    );


    // single post layout
    $wp_customize->add_setting('newscrunch_single_post_layout',
        array(
            'default'           =>  '1',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'newscrunch_single_post_layout',
        array(
            'label'         =>  esc_html__( 'Single Post Layouts', 'newscrunch'  ),
            'priority'      =>  1,
            'section'       =>  'newscrunch_single_blog_section',
            'choices'       =>  array(
                '1'   => array( 
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog-single-post-1.png',
                ),
                '2'  => array(
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/blog-single-post-2.png',
                )   
            )
        )
    ) );

    /* Markup */
    if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
        $default = 'h2';
    }
    else{
        $default = 'h1';
    }

    $wp_customize->add_setting('single_post_title_markup',
        array(
            'default'           =>  $default,
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control('single_post_title_markup', 
        array(
            'label'     => esc_html__('Title Markup','newscrunch' ),
            'section'   => 'newscrunch_single_blog_section',
            'setting'   => 'bredcrumb_markup',
            'priority'  => 2,
            'type'      => 'select',
            'choices'   =>  
            array(
                'h1'      =>  esc_html__('Heading 1', 'newscrunch' ),
                'h2'      =>  esc_html__('Heading 2', 'newscrunch' ),
                'h3'      =>  esc_html__('Heading 3', 'newscrunch' ),
                'h4'      =>  esc_html__('Heading 4', 'newscrunch' ),
                'h5'      =>  esc_html__('Heading 5', 'newscrunch' ),
                'h6'      =>  esc_html__('Heading 6', 'newscrunch' ),
                'span'    =>  esc_html__('Span', 'newscrunch' ),
                'div'     =>  esc_html__('Div', 'newscrunch' )
            )
        )
    );


    if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
        $default = array( 'reorder_sp_img', 'reorder_sp_meta', 'reorder_sp_title', 'reorder_sp_content', 'reorder_sp_tag');
    }
    else{
        $default = array( 'reorder_sp_meta', 'reorder_sp_title', 'reorder_sp_img', 'reorder_sp_content', 'reorder_sp_tag');
    }
    
    $choices = array(
        'reorder_sp_img'        => __('Featured Image','newscrunch'),
        'reorder_sp_meta'       => __('Post Meta','newscrunch'),
        'reorder_sp_title'      => __('Post Title','newscrunch'),
        'reorder_sp_content'    => __('Post Content','newscrunch'),
        'reorder_sp_tag'        => __('Tags','newscrunch')
    );
    $wp_customize->add_setting( 'single_post_sort',
    array(
        'capability'  => 'edit_theme_options',
        'sanitize_callback' => 'newscrunch_sanitize_array',
        'default'     => $default
    ) );

    $wp_customize->add_control( new Newscrunch_Control_Sortable( $wp_customize, 'single_post_sort',
    array(
        'label' => esc_html__('Drag And Drop to Rearrange','newscrunch' ),
        'section' => 'newscrunch_single_blog_section',
        'settings' => 'single_post_sort',
        'type'=> 'sortable',
        'active_callback'   => 'newscrunch_callback_single_post_layout',
        'priority'  =>  2,
        'choices'     => $choices
    ) ) );


    $wp_customize->add_setting('newscrunch_enable_single_post_categories',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_categories',
        array(
            'label'     => esc_html__('Hide/Show Categories', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 2
        )
    ));

    $wp_customize->add_setting('newscrunch_enable_single_post_tag',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_tag',
        array(
            'label'     => esc_html__('Hide/Show Tag', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 3
        )
    ));

    $wp_customize->add_setting('newscrunch_enable_single_post_author',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_author',
        array(
            'label'     => esc_html__('Hide/Show Author', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 4
        )
    ));

   $wp_customize->add_setting('newscrunch_enable_single_post_date',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_date',
        array(
            'label'     => esc_html__('Hide/Show Date', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 5
        )
    ));

    $wp_customize->add_setting('newscrunch_enable_single_post_comment',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_comment',
        array(
            'label'     => esc_html__('Hide/Show Comments', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 5
        )
    ));

    $wp_customize->add_setting('newscrunch_enable_single_post_admin_details',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_single_post_admin_details',
        array(
            'label'     => esc_html__('Hide/Show Author Details', 'newscrunch' ),
            'type'      => 'toggle',
            'section'   => 'newscrunch_single_blog_section',
            'priority'  => 8
        )
    ));

    $wp_customize->add_setting('newscrunch_enable_related_post',
            array(
                'default' => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox',
            )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_enable_related_post',
        array(
            'label' => esc_html__('Enable/Disable Related Post', 'newscrunch'),
            'type' => 'toggle',
            'section' => 'newscrunch_single_blog_section',
            'priority' => 10,
        )
    ));

    //missed section on single post
    $wp_customize->add_setting('newscrunch_single_post_missed',
            array(
                'default' => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox',
            )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control($wp_customize, 'newscrunch_single_post_missed',
        array(
            'label' => esc_html__('Enable/Disable Missed Section', 'newscrunch'),
            'type' => 'toggle',
            'section' => 'newscrunch_single_blog_section',
            'priority' => 10,
        )
    ));

    // releted post title
    $wp_customize->add_setting('newscrunch_related_post_title',
        array(
            'default'           =>  esc_html__('Related Posts', 'newscrunch'),
            'transport'         => 'postMessage',
            'sanitize_callback' =>  'newscrunch_sanitize_text'
        )
    );
    
    if ( ! class_exists('Newscrunch_Plus') ):
    $wp_customize->add_control('newscrunch_related_post_title',
        array(
            'label'             =>  esc_html__('Related Post Title', 'newscrunch'),
            'section'           =>  'newscrunch_single_blog_section',
            'setting'           =>  'newscrunch_related_post_title',
            'active_callback'   =>  'newscrunch_releted_post_callback',
            'priority'          =>  12,
            'type'              =>  'text'
        )
    );
    else:
    $wp_customize->add_control('newscrunch_related_post_title',
        array(
            'label'             =>  esc_html__('Related Post Title', 'newscrunch'),
            'section'           =>  'newscrunch_single_blog_section',
            'setting'           =>  'newscrunch_related_post_title',
            'active_callback'   =>  function($control) {
                                        return (
                                            newscrunch_releted_post_callback($control) ||
                                            spncp_releted_post_popup_callback($control)
                                        );
                                    },
            'priority'          =>  12,
            'type'              =>  'text'
        )
    );
    endif;

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Single_Post_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/blog-archive-single/#single-post?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
        <?php }
    }
        
    $wp_customize->add_setting(
            'single_post_pro_feature',
            array(
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control( new Newscrunch_Single_Post_Customize_Control( $wp_customize, 'single_post_pro_feature', array(
                'section' => 'newscrunch_single_blog_section',
                'setting' => 'single_post_pro_feature',
                'priority' => 13
            )
    ));
    endif;
   
}

add_action('customize_register', 'newscrunch_single_blog_customizer');