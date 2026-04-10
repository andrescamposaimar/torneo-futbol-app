<?php
/**
 * Template Name: Full Width Page
 *
*/
get_header();
do_action( 'newscrunch_breadcrumbs_filter' );
if ( is_front_page() ) {
    newscrunch_highlight_views('front');
} else {
     newscrunch_highlight_views('inner');
}
?>
<section class="page-section-full bg-default" id="content">
    <?php 
    if(get_theme_mod('bredcrumb_position','page_header')=='content_area'):
        echo '<div class="spnc-col-1">';
        do_action('newscrunch_breadcrumbs_page_title_hook');
        echo '</div>';
    endif; 
    the_post();
    if(has_post_thumbnail()) { ?>
        <figure class="spnc-post-thumbnail">
            <a href="<?php the_permalink(); ?>" >
                <?php the_post_thumbnail('full', array('class'=>'img-fluid', 'loading' => false )); ?>
            </a>                
        </figure>
    <?php 
    }
    ?>
    <div class="spnc-entry-content">
        <?php the_content(); ?>
    </div>
</section>
<?php
get_footer();