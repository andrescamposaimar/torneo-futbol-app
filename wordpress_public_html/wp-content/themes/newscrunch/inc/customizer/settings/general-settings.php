<?php
/**
 * General Settings Customizer
 *
 * @package Newscrunch
*/

function newscrunch_general_settings_customizer ( $wp_customize )
{
    class Newscrunch_SEO_Optimize_Customize_Control extends WP_Customize_Control {
        public $type = 'new_menu';
        /**
        * Render the control's content.
        */
        public function render_content() {
        ?>
        <div class="newscrunch-seo-optimize-customizer">
            <?php if( ! function_exists('sobw_fs')){?>
            <ul class="newscrunch-seo-optimize-pro-features">
                <li>
                   <?php esc_html_e( 'To Unlock SEO Optimized Images feature you need to Install and activate. The SEO Optimized Images plugin lets you dynamically insert SEO-Friendly “alt” and “title” attributes to your website images. ','newscrunch' ); ?>
                </li>
                <li>
                    <?php
                    if ( class_exists('Newscrunch_Plus') ):
                        $newscrunch_seo_optimize_page=newscrunch_plus_about_page(); 
                        $newscrunch_seo = 'install_seo-optimized-images-pro';
                    elseif('NewsBlogger' == wp_get_theme()):
                        $newscrunch_seo_optimize_page=newsblogger_about_page(); 
                        $newscrunch_seo = 'install_seo-optimized-images';
                    else:
                        $newscrunch_seo_optimize_page=newscrunch_about_page(); 
                        $newscrunch_seo = 'install_seo-optimized-images';
                    endif;      
                    $newscrunch_actions = $newscrunch_seo_optimize_page->recommended_actions;
                    $newscrunch_actions_todo = get_option( 'recommended_actions', false );
                    if($newscrunch_actions): 
                        foreach ($newscrunch_actions as $key => $newscrunch_val):
                            if($newscrunch_val['id']== $newscrunch_seo):
                            /* translators: %s: theme name */
                                echo '<p>'.wp_kses_post($newscrunch_val['link']).'</p>';
                            endif;
                        endforeach;
                    endif;?>
                </li>
            </ul>
            <?php } else { ?>
            <p><?php esc_html_e( 'To customize the SEO Optimized Images settings, click on below link:','newscrunch' ); ?></p>
            <a target="_blank" href="<?php echo esc_url(home_url('/').'wp-admin/admin.php?page=soi_setting');?>" class=" button-primary"><?php esc_html_e( 'SEO Optimized Images','newscrunch' ); ?></a>
            <?php } ?>
        </div>
        <?php
        }
    }
     
    /* GLOBAL SETTINGS */        
    $wp_customize->add_panel('newscrunch_general_settings',
        array(
            'priority'      => 1,
            'capability'    => 'edit_theme_options',
            'title'         => esc_html__('Global','newscrunch')
        )
    );
    
    /* SEO Optimized Images SETTINGS */  
    $wp_customize->add_section('seo_optiomize_section',
        array(
            'title' => esc_html__('SEO Optimized Images', 'newscrunch'),
            'panel'     => 'newscrunch_general_settings',
            'priority' => 1
    ));
    $wp_customize->add_setting(
        'seo_optimize_feature',
        array(
            'capability'     => 'edit_theme_options',
            'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control( new Newscrunch_SEO_Optimize_Customize_Control( $wp_customize, 'seo_optimize_feature', array(
            'section' => 'seo_optiomize_section',
            'setting' => 'seo_optimize_feature',
        )
    ));  

    /* PERFORMANCE (GOOGLE FONTS) SETTINGS */  
    $wp_customize->add_section('local_google_font',
    array(
        'title' => esc_html__('Performance(Google Font)', 'newscrunch'),
        'panel'     => 'newscrunch_general_settings',
        'priority' => 1
    ));
    $wp_customize->add_setting('newscrunch_enable_local_google_font',
        array(
            'default' => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox',
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'newscrunch_enable_local_google_font',
        array(
            'label' => esc_html__('Load Google Fonts Locally?', 'newscrunch'),
            'type' => 'toggle',
            'section' => 'local_google_font',
            'priority' => 1,
        )
    ));

    /* ====================
    * Preloader 
    ==================== */
    $wp_customize->add_section('preloader_section',
        array(
            'title'     =>esc_html__('Preloader','newscrunch' ),
            'panel'     => 'newscrunch_general_settings',
            'priority'  => 2
        )
    );
    $wp_customize->add_setting('preloader_enable',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'preloader_enable',
        array(
            'label'    => esc_html__( 'Enable/Disable Preloader', 'newscrunch'),
            'section'  => 'preloader_section',
            'type'     => 'toggle',
            'priority' => 1
        )
    ));

    if ( ! class_exists('Newscrunch_Plus') ):
    class Newscrunch_Preloader_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/global-options/#preloader?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
            <?php }
        }
        
        $wp_customize->add_setting(
            'preloader_pro_feature',
            array(
                'capability'     => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control( new Newscrunch_Preloader_Customize_Control( $wp_customize, 'preloader_pro_feature', array(
                'section' => 'preloader_section',
                'setting' => 'preloader_pro_feature',
                'priority' => 2
            )
        ));

    endif;

    /* =============================================================
    *               Animation Customizer Sections
  ================================================================ */

    $wp_customize->add_section('animation_section',
            array(
                'title'     => esc_html__('Animation Effect','newscrunch' ),
                'panel'     => 'newscrunch_general_settings',
                 'priority' => 2,
            )
        );

    $wp_customize->add_setting('link_animate',
            array(
                'default'           =>  'a_effect1',
                'capability'        =>  'edit_theme_options',
                'sanitize_callback' =>  'newscrunch_sanitize_select',
        )
    );
    $wp_customize->add_control('link_animate',
        array(
            'label' => esc_html__('Post Title Link Hover Effect','newscrunch'),
            'section' => 'animation_section',
            'setting' => 'link_animate',
            'type'    =>  'select',
            'priority' => 2,
            'choices' =>
            array(
                'a_effect1'  => esc_html__('Effect 1','newscrunch' ),
                'a_effect2'  => esc_html__('Effect 2','newscrunch' )
            )
        )
    );
    $wp_customize->add_setting('img_animation',
            array(
                'default'           =>  'i_effect1',
                'capability'        =>  'edit_theme_options',
                'sanitize_callback' =>  'newscrunch_sanitize_select',
        )
    );
    $wp_customize->add_control('img_animation',
        array(
            'label' => esc_html__('Image Hover Effect','newscrunch'),
            'section' => 'animation_section',
            'setting' => 'img_animation',
            'type'    =>  'select',
            'priority' => 3,
            'choices' =>
            array(
                ''                 => esc_html__('None','newscrunch' ),
                'i_effect1'        => esc_html__('Effect 1 ( Circle )','newscrunch' ),
                'i_effect2'        => esc_html__('Effect 2','newscrunch' )
            )
        )
    );

    /* ====================
    * Breadcrumb setting  
    ==================== */   

        $wp_customize->add_section('bredcrumb_section',
            array(
                'title'     =>  esc_html__('Breadcrumb','newscrunch'),
                'panel'     =>  'newscrunch_general_settings',
                'priority'  =>  3   
            )
        );
        // Enable/Disable breadcrumbs section
        $wp_customize->add_setting('breadcrumb_banner_enable',
            array(
                'default'           => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'breadcrumb_banner_enable',
            array(
                'label'             =>  esc_html__( 'Enable/Disable Banner', 'newscrunch'),
                'section'           =>  'bredcrumb_section',
                'type'              =>  'toggle',
                'priority'          =>  1
            )
        ));
   
   
    $bredcrumb_section='bredcrumb_section';
   
    /* == Heading for the page title == */
    class Newscrunch_pagetitle_Customize_Control extends WP_Customize_Control {
        public function render_content() { ?>
            <h3><?php esc_html_e('Page Title', 'newscrunch' ); ?></h3>
        <?php }
    }
    $wp_customize->add_setting('bredcrumb_page_title',
        array(
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_text'
        )
    );
    $wp_customize->add_control(new Newscrunch_pagetitle_Customize_Control($wp_customize, 'bredcrumb_page_title', 
        array(
                'section'           =>  $bredcrumb_section,
                'setting'           =>  'bredcrumb_page_title',
                'active_callback'   => 'newscrunch_breadcrumb_section_callback',
                'priority'  => 3,
            )
    ));

    // Enable/Disable page title
    $wp_customize->add_setting('enable_page_title',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'enable_page_title',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Page Title', 'newscrunch'),
            'section'           =>  $bredcrumb_section,
            'type'              =>  'toggle',
            'active_callback'   => 'newscrunch_breadcrumb_section_callback',
            'priority'          =>  4
        )
    ));

    /* Position */

    $wp_customize->add_setting('bredcrumb_position', 
        array(
            'default'           => esc_html__('page_header','newscrunch' ),
            'sanitize_callback' => 'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control('bredcrumb_position', 
        array(      
            'label'     => esc_html__('Position', 'newscrunch' ),        
            'section'   => $bredcrumb_section,
            'type'      => 'radio',
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_page_title_callback($control)
                                        );
                                    },
            'priority'  => 5,
            'choices'   =>  
            array(
                'page_header'   => esc_html__('Page Header', 'newscrunch' ),
                'content_area'   => esc_html__('Content Area', 'newscrunch' )
            )
        )
    );

    /* Markup */

    $wp_customize->add_setting('bredcrumb_markup',
        array(
            'default'           =>  'h1',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );

    $wp_customize->add_control('bredcrumb_markup', 
        array(
            'label'     => esc_html__('Markup','newscrunch' ),
            'section'   => $bredcrumb_section,
            'setting'   => 'bredcrumb_markup',
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_page_title_callback($control)
                                        );
                                    },
            'priority'  => 6,
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
                'p'       =>  esc_html__('Paragraph', 'newscrunch' ),
                'div'     =>  esc_html__('Div', 'newscrunch' ),
            )
        )
    );



    // Enable/Disable Breadcrumb 
    $wp_customize->add_setting('enable_breadcrumb',
        array(
            'default'           => true,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'enable_breadcrumb',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Breadcrumb', 'newscrunch'),
            'section'           =>  $bredcrumb_section,
            'type'              =>  'toggle',
            'active_callback'   => 'newscrunch_breadcrumb_section_callback',
            'priority'          =>  7
        )
    ));

    //Breadcrumbs Type 
    $wp_customize->add_setting('newscrunch_breadcrumb_type',
            array(
                'default'           =>  'default',
                'capability'        =>  'edit_theme_options',
                'sanitize_callback' =>  'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control('newscrunch_breadcrumb_type',
        array(
            'label' => esc_html__('Breadcrumb Type','newscrunch'),
            'description' => esc_html__( 'If you use other than "default" one you will need to install and activate respective plugins Breadcrumb','newscrunch') . ' NavXT, Yoast SEO ' . __('and','newscrunch') . ' Rank Math SEO',
            'section' => $bredcrumb_section,
            'setting' => 'newscrunch_breadcrumb_type',
            'type'    =>  'select',
            'priority' => 8,
           'active_callback'   =>   function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_enable_breadcrumb_callback($control)
                                        );
                                    },
            'choices' =>
            array(
                'default'  => __('Default','newscrunch'),
                'yoast'  => 'Yoast SEO',
                'rankmath'  => 'Rank Math',
                'navxt'  => 'NavXT',
            )
        )
    );

    /* Breadcrumb Alignment */

    $wp_customize->add_setting( 'bredcrumb_alignment',
        array(
            'default'           => 'parallel',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_select'
        )
    );
    $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'bredcrumb_alignment',
        array(
            'label'     => esc_html__( 'Alignment', 'newscrunch'  ),
            'section'   => $bredcrumb_section,
            'active_callback'   => 'newscrunch_breadcrumb_section_callback',
            'priority'  => 9,
            'choices'   => 
            array(
                'parallel' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/breadcrumb-right.png'),
                'parallelr' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/breadcrumb-left.png'),
                'centered' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/breadcrumb-center.png'),
                'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/both-on-left.png'),
                'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/both-on-right.png')   
            )
        )
    ));

    // Enable/Disable breadcrumb Padding
    $wp_customize->add_setting('breadcrumb_section_padding',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'breadcrumb_section_padding',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Padding', 'newscrunch'),
            'section'           =>  $bredcrumb_section,
            'type'              =>  'toggle',
            'active_callback'   =>  'newscrunch_breadcrumb_section_callback',
            'priority'          =>  10
        )
    ));

    /* Breadcrumb top padding */
    $wp_customize->add_setting( 'breadcrumb_top_padding',
        array(
            'default'           => 260,
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_number_range'
        )
    );
    $wp_customize->add_control( 'breadcrumb_top_padding',
        array(
            'label'             => esc_html__( 'Top', 'newscrunch' ),
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_breadcrumb_padding_callback($control)
                                        );
                                    },
            'section'           => $bredcrumb_section,
            'type'              => 'number',
            'priority'          => 11,
            'input_attrs'       => 
                array( 
                    'min' => 0, 
                    'max' => 500, 
                    'step' => 1
                )
        )
    );
    /* Breadcrumb right padding */
    $wp_customize->add_setting( 'breadcrumb_right_padding',
        array(
            'default'           => 0,
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_number_range'
        )
    );
    $wp_customize->add_control( 'breadcrumb_right_padding',
        array(
            'label'             => esc_html__( 'Right', 'newscrunch' ),
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_breadcrumb_padding_callback($control)
                                        );
                                },
            'section'           => $bredcrumb_section,
            'type'              => 'number',
            'priority'          => 12,
            'input_attrs'       => 
                array( 
                    'min' => 0, 
                    'max' => 500, 
                    'step' => 1
                )
        )
    );
    /* Breadcrumb bottom padding */
    $wp_customize->add_setting( 'breadcrumb_bottom_padding',
        array(
            'default'           => 30,
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_number_range'
        )
    );
    $wp_customize->add_control( 'breadcrumb_bottom_padding',
        array(
            'label'             => esc_html__( 'Bottom', 'newscrunch' ),
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_breadcrumb_padding_callback($control)
                                        );
                                },
            'section'           => $bredcrumb_section,
            'type'              => 'number',
            'priority'          => 13,
            'input_attrs'       => 
                array( 
                    'min' => 0, 
                    'max' => 500, 
                    'step' => 1
                )
        )
    );
    /* Breadcrumb left padding */
    $wp_customize->add_setting( 'breadcrumb_left_padding',
        array(
            'default'           => 0,
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'newscrunch_sanitize_number_range'
        )
    );
    $wp_customize->add_control( 'breadcrumb_left_padding',
        array(
            'label'             => esc_html__( 'Left', 'newscrunch' ),
            'active_callback'   => function($control) {
                                        return (
                                            newscrunch_breadcrumb_section_callback($control) &&
                                            newscrunch_breadcrumb_padding_callback($control)
                                        );
                                },
            'section'           => $bredcrumb_section,
            'type'              => 'number',
            'priority'          => 14,
            'input_attrs'       => 
                array( 
                    'min' => 0, 
                    'max' => 500, 
                    'step' => 1
                )
        )
    );
    // Enable/Disable breadcrumbs overlay
    $wp_customize->add_setting('breadcrumb_overlay_enable',
        array(
            'default'           => false,
            'sanitize_callback' => 'newscrunch_sanitize_checkbox'
        )
    );
    $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'breadcrumb_overlay_enable',
        array(
            'label'             =>  esc_html__( 'Enable/Disable Banner Image Overlay', 'newscrunch'),
            'active_callback'   =>  'newscrunch_breadcrumb_section_callback',
            'section'           =>  $bredcrumb_section,
            'type'              =>  'toggle',
            'priority'          =>  15
        )
    ));


    /* =============================================================
    *                       Side Bar Layout Sections
    ================================================================ */

        $wp_customize->add_section('sidebar_layout_setting_section',
            array(
                'title'     => esc_html__('Sidebar Layout','newscrunch' ),
                'panel'     => 'newscrunch_general_settings',
                'priority'  => 5
            )
        );

        /* ====== Sidebar Layout ====== */
        
        /* Blog/Archives */
        $wp_customize->add_setting( 'blog_sidebar_layout',
            array(
                'default'           => 'right',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'blog_sidebar_layout',
            array(
                'label'     => esc_html__( 'Blog/Archives', 'newscrunch' ),
                'section'   => 'sidebar_layout_setting_section',
                'priority'  => 1,
                'choices'   => 
                array(
                    'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg'),
                    'both' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/both-sidebar.jpg'),
                    'full' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/full.jpg'),
                    'stretched' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/stretched.jpg')   
                )
            )
        ));

        /* Blog/Archives Sticky Sidebar*/
        $wp_customize->add_setting('blog_sidebar_sticky',
            array(
                'default'           => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'blog_sidebar_sticky',
            array(
                'label'    => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'  => 'sidebar_layout_setting_section',
                'type'     => 'toggle',
                'priority' => 2
            )
        ));

        /* Single Post */
        $wp_customize->add_setting( 'single_blog_sidebar_layout',
            array(
                'default'           => 'full',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'single_blog_sidebar_layout',
            array(
                'label'     => esc_html__( 'Single Post', 'newscrunch'  ),
                'section'   => 'sidebar_layout_setting_section',
                'priority'  => 3,
                'choices'   => 
                array(
                    'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg'),
                    'both' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/both-sidebar.jpg'),
                    'full' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/full.jpg')  ,
                    'stretched' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/stretched.jpg')   
                )
            )
        ));

        /* Single Post Sticky Sidebar */
        $wp_customize->add_setting('single_sidebar_sticky',
            array(
                'default'           => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'single_sidebar_sticky',
            array(
                'label'    => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'  => 'sidebar_layout_setting_section',
                'type'     => 'toggle',
                'priority' => 4
            )
        ));

        /* Page Layout */
        $wp_customize->add_setting( 'page_sidebar_layout',
            array(
                'default'           => 'right',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'page_sidebar_layout',
            array(
                'label'     => esc_html__( 'Page', 'newscrunch'  ),
                'section'   => 'sidebar_layout_setting_section',
                'priority'  => 5,
                'choices'   => 
                array(
                    'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg'),
                    'both' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/both-sidebar.jpg'),
                    'full' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/full.jpg')  ,
                    'stretched' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/stretched.jpg')   
                )
            )
        ));
        /* Page Sticky Sidebar */
        $wp_customize->add_setting('page_sidebar_sticky',
            array(
                'default'           => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'page_sidebar_sticky',
            array(
                'label'    => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'  => 'sidebar_layout_setting_section',
                'type'     => 'toggle',
                'priority' => 6
            )
        ));

        /*  Front Left Content  */
        $wp_customize->add_setting( 'page_widget1_sidebar_layout',
            array(
                'default'           => 'right',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'page_widget1_sidebar_layout',
            array(
                'label'     => esc_html__( 'Front Left Content', 'newscrunch'  ),
                'section'   => 'sidebar_layout_setting_section',
                'priority'  => 7,
                'choices'   => 
                array(
                    'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg'),
                    'full' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/full.jpg')  ,
                    'stretched' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/stretched.jpg')   
                )
            )
        ));

        /* Front Left Sticky Sidebar */
        $wp_customize->add_setting('page_widget1_sidebar_sticky',
            array(
                'default'           => false,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'page_widget1_sidebar_sticky',
            array(
                'label'    => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'  => 'sidebar_layout_setting_section',
                'type'     => 'toggle',
                'priority' => 8
            )
        ));

        /* Front Right Content */
        $wp_customize->add_setting( 'page_widget2_sidebar_layout',
            array(
                'default'           => 'left',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'page_widget2_sidebar_layout',
            array(
                'label'     => esc_html__( 'Front Right Content', 'newscrunch'  ),
                'section'   => 'sidebar_layout_setting_section',
                'priority'  => 9,
                'choices'   => 
                array(
                    'right' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg'),
                    'full' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/full.jpg')  ,
                    'stretched' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/stretched.jpg')   
                )
            )
        ));

        /* Front Right Sticky Sidebar */
        $wp_customize->add_setting('page_widget2_sidebar_sticky',
            array(
                'default'           => false,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'page_widget2_sidebar_sticky',
            array(
                'label'    => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'  => 'sidebar_layout_setting_section',
                'type'     => 'toggle',
                'priority' => 10
            )
        ));

        /* Woocommerce Content */
        $wp_customize->add_setting( 'wc_sidebar_layout',
            array(
                'default'           => 'right',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'wc_sidebar_layout',
            array(
                'label'             => esc_html__( 'WooCommerce', 'newscrunch'  ),
                'section'           => 'sidebar_layout_setting_section',
                'priority'          => 10,
                'choices'           => 
                array(
                    'right'         => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/right.jpg'),
                    'left'          => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/left.jpg')  
                )
            )
        ));

        /* Woocommerce Sticky Sidebar*/
        $wp_customize->add_setting('wc_sidebar_sticky',
            array(
                'default'           => true,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox'
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize, 'wc_sidebar_sticky',
            array(
                'label'         => esc_html__( 'Make sidebar sticky', 'newscrunch'),
                'section'       => 'sidebar_layout_setting_section',
                'type'          => 'toggle',
                'priority'      => 11
            )
        ));


         /* ====== Website Layout ====== */

        $wp_customize->add_section('theme_layout_setting_section',
            array(
                'title'         => esc_html__('Theme Layout','newscrunch' ),
                'panel'         => 'newscrunch_general_settings',
                'priority'      => 6
            )
        );

        $wp_customize->add_setting( 'theme_layout',
            array(
                'default'          => 'wide',
                'capability'       => 'edit_theme_options',
                'sanitize_callback'=> 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'theme_layout',
            array(
                'label'         => esc_html__( 'Theme Layout', 'newscrunch' ),
                'section'       => 'theme_layout_setting_section',
                'priority'      => 1,
                'choices'       => 
                array(
                    'boxed'     => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/boxed.png'),
                    'wide'      => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/wide.png')
                    
                )
            )
        ));


        /* ====== Section/Widget Heading Layout ====== */

        $wp_customize->add_section('heading_layout_setting_section',
            array(
                'title'     => esc_html__('Section/Widget Heading','newscrunch' ),
                'panel'     => 'newscrunch_general_settings',
                'priority'  => 7
            )
        );

        $wp_customize->add_setting( 'heading_layout',
            array(
                'default'           => '1',
                'capability'        => 'edit_theme_options',
                'sanitize_callback' => 'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control( new Newscrunch_Image_Radio_Button_Custom_Control( $wp_customize, 'heading_layout',
            array(
                'label'     => esc_html__( 'Heading Layout', 'newscrunch' ),
                'section'   => 'heading_layout_setting_section',
                'priority'  => 1,
                'choices'   => 
                array(
                    '1' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/title-icon-1.jpg'),
                    '2' => array('image' => trailingslashit( get_template_directory_uri() ) . '/inc/customizer/assets/img/title-icon-2.jpg')
                    
                )
            )
        ));


        /* ====== Date Formatting ====== */

        $wp_customize->add_section('date_formatting_section',
            array(
                'title'     => esc_html__('Date Formatting','newscrunch' ),
                'panel'     => 'newscrunch_general_settings',
                'priority'  => 8
            )
        );

        $wp_customize->add_setting('select_display_date',
        array(
            'default'           =>  'publish',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control('select_display_date', 
            array(
                'label'     => esc_html__('How to display date','newscrunch' ),
                'section'   => 'date_formatting_section',
                'setting'   => 'select_display_date',
                'priority'  => 1,
                'type'      => 'select',
                'choices'   =>  
                array(
                    'publish'      =>  esc_html__('As Published Date', 'newscrunch' ),
                    'modify'      =>  esc_html__('As Modified Date', 'newscrunch' )
                )
            )
        );

        //Date Format
        $wp_customize->add_setting('select_date_format',
        array(
            'default'           =>  'date_format_by_wp',
            'capability'        =>  'edit_theme_options',
            'sanitize_callback' =>  'newscrunch_sanitize_select'
            )
        );
        $wp_customize->add_control('select_date_format', 
            array(
                'label'     => esc_html__('Date Format','newscrunch' ),
                'section'   => 'date_formatting_section',
                'setting'   => 'select_date_format',
                'priority'  => 2,
                'type'      => 'select',
                'choices'   =>  
                array(
                    'date_format_by_theme'      =>  esc_html__('According To Theme', 'newscrunch' ),
                    'date_format_by_wp'      =>  esc_html__('According To Wordpress', 'newscrunch' )
                )
            )
        );

       
        /* CATEGORY COLOR SETTINGS */  
        $wp_customize->add_section('newscrunch_category_color_section',
        array(
            'title' => esc_html__('Category Color', 'newscrunch'),
            'priority' => 2
        ));
        if ('NewsBlogger' == wp_get_theme()) {
            $default = "#369ef6";
        }
        else {
            $default = "#669c9b";
        }
        $newscrunch_query_args = get_terms( 'category', array('hide_empty' => false));
        foreach ( $newscrunch_query_args as $term ) {
            if(!empty($term->count))
                {
                    // old user
                    if(!empty(get_theme_mod('newscrunch_category_'.$term->slug)))
                    {
                        $wp_customize->add_setting('newscrunch_category_'.$term->slug, 
                            array(
                                'default'           => $default,
                                'sanitize_callback' => 'sanitize_hex_color',
                            )
                        );
                        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'newscrunch_category_'.$term->slug, 
                            array(
                                'label'             =>  $term->name,
                                'section'           =>  'newscrunch_category_color_section',
                                'settings'           =>  'newscrunch_category_'.$term->slug,
                            )
                        ));
                    }
                    // new user
                    else
                    {
                        $wp_customize->add_setting('newscrunch_category_'.$term->term_id, 
                            array(
                                'default'           => $default,
                                'sanitize_callback' => 'sanitize_hex_color',
                            )
                        );
                        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'newscrunch_category_'.$term->term_id, 
                            array(
                                'label'             =>  $term->name,
                                'section'           =>  'newscrunch_category_color_section',
                                'settings'           =>  'newscrunch_category_'.$term->term_id,
                            )
                        ));
                    }
                    
                }
        }


        /* Theme Color settings */
        $wp_customize->add_section( 'theme_color' , 
            array(
                'title'      => esc_html__('Theme Color', 'newscrunch' ),
                'priority'   => 2,
            )
        );

        /* ====================
        * Enable/disable custom color settings  
        ==================== */
        $wp_customize->add_setting('custom_color_enable', 
            array(
                'capability'        => 'edit_theme_options',
                'default'           => false,
                'sanitize_callback' => 'newscrunch_sanitize_checkbox',
            )
        );
        $wp_customize->add_control(new Newscrunch_Toggle_Control( $wp_customize,'custom_color_enable',
            array(
                'type'      =>  'toggle',
                'label'     =>  esc_html__('Enable custom color skin','newscrunch' ),
                'section'   =>  'theme_color',
                'priority'  =>  1
            )
        ));

        
        /* ====================
        * Link color settings
        ==================== */
        $wp_customize->add_setting('link_color', 
            array(
                'capability'        => 'edit_theme_options',
                'default'           => '#669c9b',
                'sanitize_callback' => 'sanitize_hex_color'
            )
        );
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize,'link_color', 
            array(
                'label'             => esc_html__( 'Skin Color', 'newscrunch' ),
                'active_callback'   => 'newscrunch_custom_color_callback',
                'section'           => 'theme_color',
                'setting'           => 'link_color',
                'priority'          =>  2,
            ) 
        )); 

        if ( ! class_exists('Newscrunch_Plus') ):
        class Newscrunch_ThemeColor_Customize_Control extends WP_Customize_Control {
            public function render_content() { ?>
                <div class="newscrunch-premium">
                    <h3><?php esc_html_e('Unlock more features available in Pro version.','newscrunch'); ?></h3>
                    <a target="_blank" href="<?php echo esc_url('https://helpdoc.spicethemes.com/newscrunch/colors-typography/#theme-color?ref=customizer'); ?>" class=" button-primary"><?php esc_html_e('Learn More','newscrunch'); ?></a>
                </div>
            <?php }
        }
        
        $wp_customize->add_setting(
            'themecolor_pro_feature',
            array(
                'capability'     => 'edit_theme_options',
                'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control( new Newscrunch_ThemeColor_Customize_Control( $wp_customize, 'themecolor_pro_feature', array(
                'section' => 'theme_color',
                'setting' => 'themecolor_pro_feature',
                'priority' => 3
            )
        ));

        endif;

}
add_action( 'customize_register', 'newscrunch_general_settings_customizer' );