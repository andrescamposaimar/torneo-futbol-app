<?php get_template_part('partials/breadcrumbs'); ?>
<div class="row">
    <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
        <?php
        if (get_post_meta(get_the_ID(), 'title', true) != 'hide') {
            the_title('<h1 class="page-title">', '</h1>');
        }
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <div class="entry-content">
                <?php
                the_content();
                wp_link_pages(array(
                    'before' => '<div class="page-links"><span class="page-links-title">' . esc_html__('Pages:', 'champion') . '</span>',
                    'after' => '</div>',
                    'link_before' => '<span>',
                    'link_after' => '</span>',
                ));
                ?>
            </div>
            <!-- .entry-content -->
            <?php
            if (comments_open() || get_comments_number()) {
                comments_template();
            }
            ?>
        </article><!-- #post-## -->
    </div>
    <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
        <?php get_sidebar(); ?>
    </div>
</div>
