<?php get_template_part('partials/breadcrumbs'); ?>
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
