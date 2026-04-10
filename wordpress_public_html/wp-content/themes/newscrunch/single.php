<?php
/**
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package Newscrunch
 */

get_header();

if(get_theme_mod('newscrunch_single_post_layout','1') == '8'){ 
    do_action( 'newscrunch_single_post_layout_eight' );
 }else{
    do_action( 'newscrunch_breadcrumbs_filter' ); 
 }

newscrunch_highlight_views('inner');
if((get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_stretched') || (get_theme_mod('single_blog_sidebar_layout','full')=='stretched'))  { $newscrunch_page_class='stretched';   }
else { $newscrunch_page_class=''; }

//Sidebar Selection
if ( class_exists('Newscrunch_Plus') )
{   
    // Meta Right Sidebar
    $newscrunch_page_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_sidebar', true );
    if($newscrunch_page_sidebar == '') { $newscrunch_page_sidebar = get_theme_mod('newscrunch_single_right_sidebar','sidebar-1');}
    else { $newscrunch_page_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_sidebar', true ); }

    // Meta Left Sidebar
    $newscrunch_left_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_left_sidebar', true );
    if($newscrunch_left_sidebar == '') { $newscrunch_left_sidebar = get_theme_mod('newscrunch_single_left_sidebar','left-sidebar');}
    else { $newscrunch_left_sidebar = get_post_meta(get_the_ID(),'newscrunch_page_left_sidebar', true ); }
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
?>
<section class="spnc-container spnc-single-post <?php do_action( 'spncp_single_post_layout_class_hook' );  do_action( 'spncp_check_bread_hook' ); echo esc_attr($newscrunch_page_class);?>" id="content">
    <?php 
    if(get_theme_mod('bredcrumb_position','page_header')=='content_area'):
        echo '<div class="spnc-row"><div class="spnc-col-1">';
        do_action('newscrunch_breadcrumbs_page_title_hook');
        echo '</div></div>';
    endif;
    do_action('newscrunch_single_layout');
    ?>
    <div class="spnc-row"> 
        <?php
        // trigger when both sidebar anywhere
        if(((get_theme_mod('single_blog_sidebar_layout','full')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') ||  get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both'){
                echo '<div class="spnc-col-10 '.newscrunch_single_post_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                if ( class_exists('Newscrunch_Plus') )
                {
                    if((((get_theme_mod('single_blog_sidebar_layout','full')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') && (!is_active_sidebar('left-sidebar')) && get_theme_mod('newscrunch_single_left_sidebar','left-sidebar')=='left-sidebar') ||  (get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both') && (!is_active_sidebar('left-sidebar'))) {
                        newscrunch_sleft_widget_area( 'left-sidebar' );
                    }
                }
                else
                {
                    if ((!is_active_sidebar('left-sidebar')) && (get_theme_mod('single_blog_sidebar_layout','full')=='both'))
                    {
                        newscrunch_sleft_widget_area( 'left-sidebar' );
                    }
                }
                dynamic_sidebar($newscrunch_left_sidebar); 
                echo '</div></div></div>';
        }

        // layout is from customizer in mebtabox
        if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == '' )
        {
            //left-sidebar
            if(get_theme_mod('single_blog_sidebar_layout','full')=='left') {
                echo '<div class="spnc-col-9 '.newscrunch_single_post_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                      dynamic_sidebar($newscrunch_page_sidebar); 
                echo '</div></div></div>';
            }

            //main content
            if(get_theme_mod('single_blog_sidebar_layout','full')=='right'|| get_theme_mod('single_blog_sidebar_layout','right')=='left') 
            {
                echo '<div class="spnc-col-7 '.newscrunch_single_post_stickysidebar().'">';
            }
            else if(get_theme_mod('single_blog_sidebar_layout','full')=='both')
            {
                echo '<div class="spnc-col-8 '.newscrunch_single_post_stickysidebar().'">';
            }
            else
            {
                echo '<div class="spnc-col-1">';
            }
        }


        // layout is left sidebar in mebtabox
        else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_left')
        {
            echo '<div class="spnc-col-9 '.newscrunch_single_post_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar); 
            echo '</div></div></div>';
            echo '<div class="spnc-col-7 '.newscrunch_single_post_stickysidebar().'">';
        } 

        // layout is right sidebar in mebtabox
        else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_right')
        {
            echo '<div class="spnc-col-7 '.newscrunch_single_post_stickysidebar().'">';
        }

        // layout is right sidebar in mebtabox
        else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_both')
        {
            echo '<div class="spnc-col-8 '.newscrunch_single_post_stickysidebar().'">';
        }

        // layout is container in mebtabox
        else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_without_sidebar')
        {
            echo '<div class="spnc-col-1">'; 
        }
        
        // layout is streched in mebtabox
        else
        {
            echo '<div class="spnc-col-1">'; 
        }
        echo '<div class="spnc-blog-wrapper">';
        while (have_posts()): the_post();
            newscrunch_view(get_the_ID());
           if(get_theme_mod('newscrunch_single_post_layout','1') == '1' || get_theme_mod('newscrunch_single_post_layout','1') == '2'){
            get_template_part('template-parts/content', 'single');
            } else{
                do_action('spncp_single_post_layout_hook');
            }
        endwhile;

        // Author Details
        if ( class_exists('Newscrunch_Plus') ):
            do_action('spncp_single_post_auth_detail');
        else:
            do_action('newscrunch_single_post_auth_detail');
        endif; 

        if ( class_exists('Newscrunch_Plus') ):
            do_action('spncp_single_post_hook');
        else:
            do_action('newscrunch_single_post_hook');
        endif; 

        if (comments_open() || get_comments_number()) : comments_template();
        endif;
        ?>
        </div>
        </div>
        <!-- Right Sidebar --> 
        <?php if(((get_theme_mod('single_blog_sidebar_layout','full')=='right') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') ||  get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_right'){
                echo '<div class="spnc-col-9 '.newscrunch_single_post_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar);
                echo '</div></div></div>';
         }
         else if(((get_theme_mod('single_blog_sidebar_layout','full')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') ||  get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both'){
                echo '<div class="spnc-col-10 '.newscrunch_single_post_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar);
                echo '</div></div></div>';
        }
        ?>
    </div>
</section>
<?php
//Missed section on single post
if(get_theme_mod('hide_show_missed_section',true)==true):
    if(get_theme_mod('newscrunch_single_post_missed',true)==true):
        if ( class_exists('Newscrunch_Plus') )
        {
            do_action( 'spncp_missed_layout' );
        }
        else
        {
            get_template_part( 'partials/front/missed-1');
        }
    endif;  
endif; 

get_footer();