<?php get_header(); ?>
<?php get_template_part('partials/breadcrumbs'); ?>
    <div class="container">
        <h1 class="page-title"><?php printf(esc_html__('Search Results for: %s', 'champion'), get_search_query()); ?></h1>

        <?php if (have_posts()) { ?>

            <div class="posts_list">

                <?php
                while (have_posts()) {
                    the_post();
                    get_template_part('partials/loop', get_post_format());
                }
                ?>

            </div>

            <?php local_pagination(); ?>

        <?php } else { ?>

            <?php get_template_part('partials/content', 'none'); ?>

        <?php } ?>

    </div>
<?php get_footer(); ?>