<?php
/**
 * Banner Customizer
 *
 * @package Newscrunch
*/

function newscrunch_news_highlight ( $wp_customize ) {

    /* ====== NEWS HIGHLIGHT SECTION ====== */
    $wp_customize->add_section('newscrunch_news_highlight_section', 
        array(
            'title'     => esc_html__('News Highlight' , 'newscrunch' ),
            'priority'  => 23
        )
    );

    // Show Highlight Section on
    $wp_customize->add_setting('newscrunch_highlight_view',
        array(
            'default'           =>  'front',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control('newscrunch_highlight_view', 
        array(
            'label'             => esc_html__('Appearance ','newscrunch' ),
            'section'           => 'newscrunch_news_highlight_section',
            'setting'           => 'newscrunch_highlight_view',
            'type'              => 'select',
            'choices'           =>  
                                    array(
                                        'all'  =>  esc_html__('Display in all pages', 'newscrunch' ),
                                        'front'     =>  esc_html__('Front page only', 'newscrunch' ),
                                        'inner'     =>  esc_html__('Display only in inner pages', 'newscrunch' ),
                                        'none'     =>  esc_html__('Hide from all pages', 'newscrunch' ),
                                    ),
            'priority'          =>  1
        )
    );

     // highlight layouts
    $wp_customize->add_setting( 'highlight_layout',
        array(
            'default'           => '1',
            'sanitize_callback' => 'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'highlight_layout',
        array(
            'label'         =>  esc_html__( 'Layout', 'newscrunch'  ),
            'priority'      =>  1,
            'section'       =>  'newscrunch_news_highlight_section',
            'choices'       =>  array(
                '1'   => array( 
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/news-highlight-1.jpg',
                ),
                '2'  => array(
                    'image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/news-highlight-2.jpg',
                )
            )
        )
    ) );

    // scroll to top icon font
    $wp_customize->add_setting('news_highlight_title',
        array(
            'default'           => esc_html__('Highlight', 'newscrunch'),
            'capability'        => 'edit_theme_options',
            'transport'        => 'postMessage',
            'sanitize_callback' => 'newscrunch_sanitize_text'
        )
    );
    $wp_customize->add_control('news_highlight_title',
        array(
            'label'             => esc_html__('Title', 'newscrunch'),
            'section'           => 'newscrunch_news_highlight_section',
            'setting'           => 'news_highlight_title',
            'priority'          => 2,
            'type'              => 'text'
        )
    );

    // news highlight search options
    $wp_customize->add_setting('newscrunch_highlight_search_option',
        array(
            'default'           =>  'category',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control('newscrunch_highlight_search_option', 
        array(
            'label'             => esc_html__('Search By','newscrunch' ),
            'section'           => 'newscrunch_news_highlight_section',
            'setting'           => 'newscrunch_highlight_search_option',
            'type'              => 'select',
            'choices'           =>  
                                    array(
                                        'category'  =>  esc_html__('Category', 'newscrunch' ),
                                        'title'     =>  esc_html__('Title', 'newscrunch' )
                                    ),
            'priority'          =>  3
        )
    );
   
    // select the news highlight category
    $wp_customize->add_setting( 'news_highlight_dropdown_category',
        array(
            'default'           =>  0,
            'sanitize_callback' =>  'absint'
        )
    );
    $wp_customize->add_control( new Newscrunch_Dropdown_Category_Control( $wp_customize, 'news_highlight_dropdown_category',
        array(
            'label'             =>  esc_html__( 'Select Category', 'newscrunch' ),
            'section'           =>  'newscrunch_news_highlight_section',
            'settings'          =>  'news_highlight_dropdown_category',
            'priority'          =>  4
        )
    ) );

    // select the news highlight post title
    $args = array('post_type' => 'post', 'posts_per_page' => 50);
    $newscrunch_posts = get_posts( $args ); 
    if( count( $newscrunch_posts ) ) {     
        foreach( $newscrunch_posts as $newscrunch_post ) {
        $choices[ $newscrunch_post->ID ] = $newscrunch_post->post_title;
        }     
    }

    $wp_customize->add_setting( 'news_highlight_dropdown_post_title',
        array(
             'default'           => array(),
             'capability'        => 'edit_theme_options',
             'sanitize_callback' =>  'newscrunch_sanitize_dropdown_post_title_field'       
        )
    );                              
    $wp_customize->add_control(new Newscrunch_Post_Title_Multiple_Select($wp_customize,'news_highlight_dropdown_post_title',
        array(
            'type'              =>  'multiple-select',
            'label'             =>  esc_html__( 'Select Post Title', 'newscrunch' ),
            'section'           =>  'newscrunch_news_highlight_section',
            'settings'          =>  'news_highlight_dropdown_post_title',
            'priority'          =>  5,
            'choices'           =>  $choices     
        )
    ));

    // news highlight date or category option
    $wp_customize->add_setting('newscrunch_highlight_date_cat_option',
        array(
            'default'           =>  'post_date',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control('newscrunch_highlight_date_cat_option', 
        array(
            'label'             => esc_html__('Filter Post Arguments By','newscrunch' ),
            'section'           => 'newscrunch_news_highlight_section',
            'setting'           => 'newscrunch_highlight_date_cat_option',
            'type'              => 'select',
            'choices'           =>  
                                    array(
                                        'post_date' =>  esc_html__('Post Date', 'newscrunch'),
                                        'post_cat'  =>  esc_html__('Post Category', 'newscrunch')
                                    ),
            'priority'          =>  6
        )
    );

    // banner center number of posts
    $wp_customize->add_setting( 'news_highlight_num_posts', 
        array(
            'default'           =>  8,
            'sanitize_callback' =>  'absint',
        ) 
    );
    // Add control
    $wp_customize->add_control( new WP_Customize_Control($wp_customize,'news_highlight_num_posts',
        array(
            'label'             =>  esc_html__('Number of posts to show', 'newscrunch'),
            'section'           =>  'newscrunch_news_highlight_section',
            'settings'          =>  'news_highlight_num_posts',
            'type'              =>  'number',
            'priority'          =>  7,
        )
    ));

    /* ====== Editor Section ====== */
    $wp_customize->add_section('newscrunch_page_editor_section', 
        array(
            'title'     => esc_html__('Gutenberg Template Editor' , 'newscrunch' ),
            'priority'  => 23
        )
    );

    // enable/disable center banner meta
    $wp_customize->add_setting('hide_show_page_editor_section',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'hide_show_page_editor_section',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Page Editor Content', 'newscrunch'),
            'description'       => esc_html__('Note: This setting only works on the Gutenberg news business template.', 'newscrunch') . ' '. '<b><a href="' . esc_url('https://helpdoc.spicethemes.com/newscrunch/page-templates/#gutenberg-template-editor') . '" target="_blank">' . esc_html__('For more details read the help docs.','newscrunch') . '</b></a>',
            'section'           =>  'newscrunch_page_editor_section',
            'settings'          =>  'hide_show_page_editor_section',
            'type'              =>  'toggle',
            'priority'          =>  1
        )
    ));

}
add_action( 'customize_register', 'newscrunch_news_highlight' );