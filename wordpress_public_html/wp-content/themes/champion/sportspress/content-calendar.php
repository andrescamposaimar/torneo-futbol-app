<?php get_template_part('partials/breadcrumbs'); ?>
<?php the_title('<h1 class="page-title">', '</h1>'); ?>
<div id="primary" class="content-area">
    <div class="row">
        <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
                <?php the_tags('<div class="tags_block"><h3>' . esc_html__('Tags', 'champion') . '</h3><span class="tag-links">', '', '</span></div>'); ?>

            </article>
            <!-- #post-## -->
        </div>
        <?php get_sidebar('sport'); ?>
    </div>
</div>