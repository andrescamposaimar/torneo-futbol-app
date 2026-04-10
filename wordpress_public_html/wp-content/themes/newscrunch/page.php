<?php
/**
 * The template for displaying all pages
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site may use a
 * different template.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newscrunch
 */

get_header();

do_action( 'newscrunch_breadcrumbs_filter' );
if ( is_front_page() ) {
    newscrunch_highlight_views('front');
} else {
     newscrunch_highlight_views('inner');
}
if((get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_stretched') || (get_theme_mod('page_sidebar_layout','right')=='stretched')) {
        $newscrunch_page_class='stretched';   
}
else {
    $newscrunch_page_class='';
}

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


if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) =='')
{
    if(get_theme_mod('page_sidebar_layout','right')=='right' || get_theme_mod('page_sidebar_layout','right')=='left')  
    {   
       $page_column='<div class="spnc-col-7 '.newscrunch_single_page_stickycontent().'">';
    }   
    elseif(get_theme_mod('page_sidebar_layout','right')=='both')
    {
        $page_column='<div class="spnc-col-8 '.newscrunch_single_page_stickycontent().'">';
    }
    else
    {
        $page_column='<div class="spnc-col-1">';
    }
}
else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_left')
{  
    $page_column='<div class="spnc-col-7 '.newscrunch_single_page_stickycontent().'">';
}
else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_right')
{
    $page_column='<div class="spnc-col-7 '.newscrunch_single_page_stickycontent().'">';
}
else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_both')
{
    $page_column='<div class="spnc-col-8 '.newscrunch_single_page_stickycontent().'">';
}
else if(get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_without_sidebar')
{
    $page_column='<div class="spnc-col-1">';
}
else
{
    $page_column='<div class="spnc-col-1">';
} ?>
<section class="page-section-space blog bg-default spnc-default-page spnc-main-page <?php do_action( 'spncp_check_bread_hook' ); echo esc_attr($newscrunch_page_class);?>" id="content">
    <div class="spnc-container">
        <?php
        if(get_theme_mod('bredcrumb_position','page_header')=='content_area'):
            echo '<div class="spnc-row"><div class="spnc-col-1">';
            do_action('newscrunch_breadcrumbs_page_title_hook');
            echo '</div></div>';
        endif;
        ?>
        <div class="spnc-row"> 
            <?php         
            //sidebar
            if ( class_exists( 'WooCommerce' ) ) {
                if( is_account_page() || is_cart() || is_checkout() ) {
                    if(get_theme_mod('wc_sidebar_layout','right')=='left'): get_sidebar('woocommerce'); endif; 
                    $page_wrap = '<div class="spnc-col-' . ( !is_active_sidebar( 'woocommerce' ) ? '1' : '7' ) . '">';
                }
                else
                {
                    if(((get_theme_mod('page_sidebar_layout','right')=='left') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )== '') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_left') 
                    {
                        echo '<div class="spnc-col-9 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                        dynamic_sidebar($newscrunch_page_sidebar);
                        echo '</div></div></div>';
                    }
                    elseif (((get_theme_mod('page_sidebar_layout','right')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )== '') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')
                    {
                        echo '<div class="spnc-col-10 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                        if ((!is_active_sidebar('left-sidebar')) && (get_theme_mod('page_sidebar_layout','right')=='both') || (!is_active_sidebar('left-sidebar')) && (get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')) 
                        {
                            newscrunch_sleft_widget_area( 'left-sidebar' );
                        }
                        dynamic_sidebar($newscrunch_left_sidebar); 
                        echo '</div></div></div>';
                    }
                    $page_wrap = $page_column;
                }
            }         
            else
            {          
                if(((get_theme_mod('page_sidebar_layout','right')=='left') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )== '') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_left') {
                    echo '<div class="spnc-col-9 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                        dynamic_sidebar($newscrunch_page_sidebar);
                    echo '</div></div></div>';
                }
                elseif(((get_theme_mod('page_sidebar_layout','right')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )== '') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')  {
                    echo '<div class="spnc-col-10 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="left-sidebar">';
                    if ((!is_active_sidebar('left-sidebar')) && (get_theme_mod('page_sidebar_layout','right')=='both') || (!is_active_sidebar('left-sidebar')) && (get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')) {
                            newscrunch_sleft_widget_area( 'left-sidebar' );
                        }

                    dynamic_sidebar($newscrunch_left_sidebar); 
                    echo '</div></div></div>';
                }
                $page_wrap = $page_column;
            }
           
           //main content
            echo  $page_wrap;
            echo '<div class="spnc-contact-wrapper">';
            while (have_posts()) : the_post();
                get_template_part('template-parts/content', 'page');
                if (comments_open() || get_comments_number()) :
                    comments_template();
                endif;
            endwhile;
            echo '</div>';
            ?>
        </div>  
        <?php 
        //right sidebar
        if ( class_exists( 'WooCommerce' ) ) {
            if( is_account_page() || is_cart() || is_checkout() )
            {
                if(get_theme_mod('wc_sidebar_layout','right')=='right'): get_sidebar('woocommerce'); endif;
            }
            else
            {
                if(((get_theme_mod('page_sidebar_layout','right')=='right') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_right')
                {
                    echo '<div class="spnc-col-9 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar);
                    echo '</div></div></div>';
                }
                elseif(((get_theme_mod('page_sidebar_layout','right')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')
                {
                    echo '<div class="spnc-col-10 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar);
                    echo '</div></div></div>';
                }
            }
        }
        else
        {
            if(((get_theme_mod('page_sidebar_layout','right')=='right') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_right')
            {
                echo '<div class="spnc-col-9 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar); 
                echo '</div></div></div>';
            }
            elseif(((get_theme_mod('page_sidebar_layout','right')=='both') && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both')
            {
                echo '<div class="spnc-col-10 '.newscrunch_single_page_stickysidebar().'"><div class="spnc-sidebar spnc-main-sidebar"><div class="right-sidebar">';
                    dynamic_sidebar($newscrunch_page_sidebar);
                echo '</div></div></div>';
            }
        }
         ?>
    </div>
</section>
<?php
get_footer();