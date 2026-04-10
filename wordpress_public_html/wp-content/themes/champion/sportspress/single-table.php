<?php get_header(); ?>
<?php get_template_part( 'partials/breadcrumbs' ); ?>
<div id="primary" class="content-area">
    <div class="row">
        <?php
        while ( have_posts() ) {
            the_post();
            get_template_part( 'sportspress/content', 'player' );

        }
        ?>
        <?php get_sidebar('sport'); ?>
    </div>
</div>

<?php get_footer(); ?>
