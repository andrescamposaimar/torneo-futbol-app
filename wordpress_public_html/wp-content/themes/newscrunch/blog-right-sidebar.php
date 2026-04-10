<?php 
/**
 * Template Name: Blog Right Sidebar
 */
get_header();
do_action( 'newscrunch_breadcrumbs_filter' );
newscrunch_highlight_views('inner');
?>
<section class="spnc-container spnc-blog-page spnc-right-sidebar spnc-blog-clr">
        <?php
        if(get_theme_mod('bredcrumb_position','page_header')=='content_area'):
            echo '<div class="spnc-col-1">';
            do_action('newscrunch_breadcrumbs_page_title_hook');
            echo '</div>';
        endif;
        ?>
        <div class="spnc-row">
        	<div class="spnc-col-7">    
            <?php 
            if(get_theme_mod('post_nav_style_setting','pagination') == 'pagination')
            {
    			if ( get_query_var( 'paged' ) ) { $paged = get_query_var( 'paged' ); }
    			elseif ( get_query_var( 'page' ) ) { $paged = get_query_var( 'page' ); }
    			else { $paged = 1; }
    			$args = array( 'post_type' => 'post','paged'=>$paged);
    			$newscrunch_posts = new WP_Query( $args);
    			if($newscrunch_posts->have_posts()): 
                    echo '<div class="spnc-blog-right-wrapper">';
                        while ($newscrunch_posts->have_posts()): $newscrunch_posts->the_post();        
                             get_template_part('template-parts/content-blog');    
                        endwhile;
                    echo'</div>';        
                else:
                    get_template_part('template-parts/content', 'none');
                endif;
                echo '<div class="clrfix"></div>';
                // pagination
               	$newscrunch_obj = new Newscrunch_Pagination();
    			$newscrunch_obj->newscrunch_page($newscrunch_posts);
            }
            else
            {
                echo do_shortcode('[ajax_posts]');
            }
            ?>      
        	</div>
            <div class="spnc-col-9 <?php echo newscrunch_blog_stickysidebar();?>">
                <div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-main-sidebar">
                    <div class="right-sidebar">
                       <?php dynamic_sidebar( 'sidebar-1' ); ?>
                    </div>
               </div>
            </div>    
        </div>
</section> 
<?php get_footer(); ?>