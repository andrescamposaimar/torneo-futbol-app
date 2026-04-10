<?php get_header(); ?>
<?php get_template_part( 'partials/breadcrumbs' ); ?>
<div id="primary" class="content-area">
    <?php
        while ( have_posts() ) {
            the_post();
            get_template_part( 'sportspress/content', 'event' );

        }
    ?>
</div>

<?php get_footer(); ?>
