<?php
/**
 * Template Name: Gutenberg News Business
 *
*/

get_header();

if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
    $newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_banner', 'front_highlight', 'front_ad') );
}
else {
    $newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_highlight', 'front_banner', 'front_ad') );
}

if ( ! empty( $newscrunch_banner_sort ) && is_array( $newscrunch_banner_sort ) ) :
    foreach ( $newscrunch_banner_sort as $newscrunch_banner_sort_key => $newscrunch_banner_sort_val ) :

        if(get_theme_mod('newscrunch_enable_front_banner',true)==true):
            if ( 'front_banner' === $newscrunch_banner_sort_val ) :
                if( class_exists('Spice_Slider_Pro') && !empty(get_theme_mod('spnc_spsp_shortcode'))){
                    if(get_theme_mod('hide_show_banner',true) == true ): echo do_shortcode( get_theme_mod('spnc_spsp_shortcode') ); endif; 
                }
                else if( class_exists('Spice_Post_Slider') && !empty(get_theme_mod('spnc_spsp_shortcode'))){
                    if(get_theme_mod('hide_show_banner',true) == true ): echo do_shortcode( get_theme_mod('spnc_spsp_shortcode') ); endif;
                    }
                else{
                  get_template_part( 'partials/front/main-banner' ); 
                }
            endif;
        endif;  

        if(get_theme_mod('newscrunch_enable_front_highlight',true)==true):
            if ( 'front_highlight' === $newscrunch_banner_sort_val ) :

                if ( is_front_page() ) {
                    newscrunch_highlight_views('front');
                } else {
                     newscrunch_highlight_views('inner');
                }
                
            endif;
        endif;
    endforeach;
endif;

 if(get_theme_mod('hide_show_page_editor_section',true)==true) { ?>
    <section class="spnc-page-section-space spnc-block-content">
        <div class="spnc-container">
            <div class="spnc-block-gutenberg-content-wrapper">
                <?php the_content(); 
                newscrunch_edit_link_button();
                ?>
            </div>
        </div>
    </section>
<?php }

$newscrunch_sort=get_theme_mod( 'front_page_content_sort', array('front_content_1', 'video_content', 'front_content_2', 'mainblog_content', 'youtube_content','missed_content','wc_content') );

if ( ! empty( $newscrunch_sort ) && is_array( $newscrunch_sort ) ) :
    foreach ( $newscrunch_sort as $newscrunch_sort_key => $newscrunch_sort_val ) :

        if(get_theme_mod('newscrunch_enable_front_content_1',true)==true):
            if ( 'front_content_1' === $newscrunch_sort_val ) :
                if ( is_active_sidebar( 'front-page-1' ) || is_active_sidebar( 'front-page-side-1' ) ):
                get_template_part( 'partials/front/news-home-1');
                endif;
        endif; endif;

        if ( class_exists('Newscrunch_Plus') )
        {
            if(get_theme_mod('newscrunch_enable_video_content',true)==true):
                if ( 'video_content' === $newscrunch_sort_val ) :
                    if ( class_exists('Newscrunch_Plus') ):
                        do_action( 'spncp_featured_video_variation' );
                    else:    
                        get_template_part( 'partials/front/news-video');
                    endif;    
                endif; 
            endif;
        }
        else
        {
            if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) :
                if(get_theme_mod('newscrunch_enable_video_content',true)==true):
                    if ( 'video_content' === $newscrunch_sort_val ) :
                        if ( class_exists('Newscrunch_Plus') ):
                            do_action( 'spncp_featured_video_variation' );
                        else:    
                            get_template_part( 'partials/front/news-video');
                        endif;    
                    endif; 
                endif;
            endif;    
        }
        

        if(get_theme_mod('newscrunch_enable_youtube_content',true)==true):
            if ( 'youtube_content' === $newscrunch_sort_val ) :
                if(get_theme_mod('hide_show_youtube',false)==true):
                    do_action( 'spncp_yt_video' );
        endif; endif; endif;

        if(get_theme_mod('newscrunch_enable_missed_content',true)==true):
            if ( 'missed_content' === $newscrunch_sort_val ) :
                if(get_theme_mod('hide_show_missed_section',true)==true):
                    if ( class_exists('Newscrunch_Plus') )
                    {
                        do_action( 'spncp_missed_layout' );
                    }
                    else
                    {
                        get_template_part( 'partials/front/missed-1');
                    }

        endif; endif; endif;

        if(get_theme_mod('newscrunch_enable_front_content_2',true)==true):
            if ( 'front_content_2' === $newscrunch_sort_val ) :
                if ( is_active_sidebar( 'front-page-2' ) || is_active_sidebar( 'front-page-side-2' ) ):
                get_template_part( 'partials/front/news-home-2');
                endif;
        endif; endif;

        if(get_theme_mod('newscrunch_enable_mainblog_content',true)==true):
            if ( 'mainblog_content' === $newscrunch_sort_val ) :

                //Sidebar Selection
                if ( class_exists('Newscrunch_Plus') )
                {   
                    // Meta Right Sidebar
                    $newscrunch_page_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_sidebar', true );
                    if($newscrunch_page_sidebar == '') { $newscrunch_page_sidebar = get_theme_mod('newscrunch_blog_archive_right_sidebar','sidebar-1');}
                    else { $newscrunch_page_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_sidebar', true ); }

                    // Meta Left Sidebar
                    $newscrunch_left_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_left_sidebar', true );
                    if($newscrunch_left_sidebar == '') { $newscrunch_left_sidebar = get_theme_mod('newscrunch_blog_archive_left_sidebar','left-sidebar');}
                    else { $newscrunch_left_sidebar = get_post_meta(get_the_ID(),'newscrunch_left_sidebar', true ); }
                }
                else
                {
                    // Meta Right Sidebar
                    $newscrunch_page_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_sidebar', true );
                    if($newscrunch_page_sidebar =='') { $newscrunch_page_sidebar ='sidebar-1';} 

                    // Meta Left Sidebar
                    $newscrunch_left_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_left_sidebar', true );
                    if($newscrunch_left_sidebar =='') { $newscrunch_left_sidebar ='left-sidebar';} 
                }
                $newscrunch_hide_show_blog_post = get_theme_mod('hide_show_blog_post',true) ;
                if($newscrunch_hide_show_blog_post == true) { 
                ?>
                <section class="spnc-page-section-space page-section-space blog spnc-category-page spnc-index-blog spnc-blog-clr" id="content">
                    <div class="spnc-container">
                        <div class="spnc-row">
                            <?php
                            //sidebar 
                            if(get_theme_mod('blog_sidebar_layout','right')=='left' || get_theme_mod('blog_sidebar_layout','right')=='both'){ 
                                echo '<div class="'.((get_theme_mod('blog_sidebar_layout','right')=='left')?'spnc-col-9 '.newscrunch_blog_stickysidebar().'':'spnc-col-10 '.newscrunch_blog_stickysidebar().'').' "><div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">'; 

                                    if ((!is_active_sidebar('left-sidebar')) && (get_theme_mod('blog_sidebar_layout','right')=='both')) {
                                        newscrunch_sleft_widget_area( 'left-sidebar' );
                                    }
                                    
                                    dynamic_sidebar(((get_theme_mod('blog_sidebar_layout','right')=='left')?'"'.$newscrunch_page_sidebar.'"':'"'.$newscrunch_left_sidebar.'"'));
                                    echo '</div></div></div>';
                            }
                           
                            //Main content
                            if(get_theme_mod('blog_sidebar_layout','right')=='right' || get_theme_mod('blog_sidebar_layout','right')=='left')
                            {
                                echo '<div class="spnc-col-7 '.newscrunch_blog_stickycontent().'">';
                            }
                            else if(get_theme_mod('blog_sidebar_layout','right')=='both')
                            {
                                echo '<div class="spnc-col-8 '.newscrunch_blog_stickycontent().'">';
                            }
                            else
                            {
                                echo '<div class="spnc-col-1">';
                            }

                            if ( class_exists('Newscrunch_Plus') ):
                                if((get_theme_mod('post_nav_style_setting','pagination')=='load_more') || (get_theme_mod('post_nav_style_setting','pagination')=='infinite')) :
                                    echo do_shortcode('[ajax_posts]');
                                endif;
                            endif;
                            if((get_theme_mod('post_nav_style_setting','pagination') == 'pagination')  || ( class_exists('Newscrunch_Plus') && get_theme_mod('post_nav_style_setting','pagination') == 'pagination' )):


                                if ( get_query_var( 'paged' ) ) { $paged = get_query_var( 'paged' ); }
                                elseif ( get_query_var( 'page' ) ) { $paged = get_query_var( 'page' ); }
                                else { $paged = 1; }

                                $args = array( 'post_type' => 'post','paged'=>$paged);
                                $newscrunch_posts = new WP_Query( $args);


                                 if ($newscrunch_posts->have_posts()):  

                                    if(class_exists('Newscrunch_Plus'))
                                    {
                                        if('NewsBlogger' == wp_get_theme())
                                        {
                                            if(get_theme_mod('archive_blog_variation','list')=='list')
                                            {
                                                $i=1;
                                                echo '<div class="spnc-blog-section"><div class="spnc-post-list-view-section">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                get_template_part( 'template-parts/content-list');
                                                $i++;
                                                endwhile;
                                                echo'</div></div>';
                                            }
                                            else
                                            {
                                                $i=1; 
                                                echo '<div class="spnc-blog-cat-wrapper">';
                                                    while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                        if($i==1){
                                                            get_template_part( 'template-parts/content-first');
                                                        }else{
                                                             get_template_part( 'template-parts/content');
                                                        }
                                                    $i++;
                                                    endwhile;
                                                echo'</div>';
                                            }
                                        }
                                        else
                                        {
                                          if(get_theme_mod('archive_blog_variation','grid')=='grid')
                                          {
                                            $i=1; 
                                            echo '<div class="spnc-blog-cat-wrapper">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                    if($i==1){
                                                        get_template_part( 'template-parts/content-first');
                                                    }else{
                                                         get_template_part( 'template-parts/content');
                                                    }
                                                $i++;
                                                endwhile;
                                            echo'</div>';
                                          } 
                                          else
                                          {
                                                $i=1;
                                                echo '<div class="spnc-blog-section"><div class="spnc-post-list-view-section">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                get_template_part( 'template-parts/content-list');
                                                $i++;
                                                endwhile;
                                                echo'</div></div>';
                                          } 
                                        }
                                    }
                                    else
                                    {
                                        if ('NewsBlogger' == wp_get_theme()) {
                                            if(get_theme_mod('archive_blog_variation','list')=='list')
                                            {
                                                $i=1;
                                                echo '<div class="spnc-blog-section"><div class="spnc-post-list-view-section">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                get_template_part( 'template-parts/content-list');
                                                $i++;
                                                endwhile;
                                                echo'</div></div>';
                                            }
                                            else
                                            {
                                                $i=1; 
                                                echo '<div class="spnc-blog-cat-wrapper">';
                                                    while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                        if($i==1){
                                                            get_template_part( 'template-parts/content-first');
                                                        }else{
                                                             get_template_part( 'template-parts/content');
                                                        }
                                                    $i++;
                                                    endwhile;
                                                echo'</div>';
                                            }
                                        }
                                        else{
                                            if(get_theme_mod('archive_blog_variation','grid')=='grid')
                                            {
                                                $i=1; 
                                                echo '<div class="spnc-blog-cat-wrapper">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                    if($i==1){
                                                        get_template_part( 'template-parts/content-first');
                                                    }else{
                                                         get_template_part( 'template-parts/content');
                                                    }
                                                $i++;
                                                endwhile;
                                                echo'</div>';
                                            } 
                                            else
                                            {
                                                $i=1;
                                                echo '<div class="spnc-blog-section"><div class="spnc-post-list-view-section">';
                                                while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();
                                                    get_template_part( 'template-parts/content-list');
                                                $i++;
                                                endwhile;
                                                echo'</div></div>';
                                            }
                                        }
                                    }
                                    else:
                                        get_template_part('template-parts/content', 'none');
                                    endif;

                                    echo '<div class="clrfix"></div>';
                                    // pagination
                                    $newscrunch_obj = new Newscrunch_Pagination();
                                    $newscrunch_obj->newscrunch_page($newscrunch_posts);
                                endif;          
                             ?>      
                            </div>  
                            <?php if(get_theme_mod('blog_sidebar_layout','right')=='right' || get_theme_mod('blog_sidebar_layout','right')=='both'):
                                echo '<div class="'.((get_theme_mod('blog_sidebar_layout','right')=='right')?'spnc-col-9 '.newscrunch_blog_stickysidebar().'':'spnc-col-10 '.newscrunch_blog_stickysidebar().'').' "><div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                                    dynamic_sidebar($newscrunch_page_sidebar);
                                echo '</div></div></div>';
                            endif;?>
                        </div>
                    </div>
                </section> 
                <?php
                }
        endif; endif;  

        if(get_theme_mod('newscrunch_enable_wc_content',true)==true):
            if ( 'wc_content' === $newscrunch_sort_val ) :
                if(( class_exists( 'WooCommerce' ) ) && get_theme_mod('hide_show_spncp_wc_product',true)==true):
                    do_action( 'spncp_wc_product' );
        endif; endif; endif;
        
    endforeach;
endif;
get_footer();