<?php get_header(); ?>
<?php get_template_part('partials/breadcrumbs'); ?>
    <div id="primary" class="content-area">
        <?php
        while (have_posts()) {
            the_post();
            get_template_part('partials/project', get_post_format());
        }
        ?>
    </div>

<?php get_footer(); ?>