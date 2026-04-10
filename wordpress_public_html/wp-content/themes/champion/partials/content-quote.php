<div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

        <?php the_title('<h1 class="page-title">', '</h1>'); ?>
        <?php if (has_post_thumbnail()) { ?>
            <div class="post-thumbnail">
                <?php the_post_thumbnail('full'); ?>
            </div>
        <?php } ?>
        <div class="entry-meta clearfix">
            <div class="entry-author"><?php esc_html_e('By', 'champion'); ?> <span><?php the_author(); ?></span> &nbsp;&nbsp;|&nbsp;&nbsp; <?php the_date(); ?>
            </div>
            <div class="comments-link">
                <?php comments_popup_link(__('<i class="fa fa-comment"></i> Leave a comment', 'champion'), __('<i class="fa fa-comment"></i> 1 Comment', 'champion'), __('<i class="fa fa-comment"></i> % Comments', 'champion')); ?>
            </div>
        </div>
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
        <?php if (get_post_meta(get_the_ID(), 'about_author', true) != 'hide' && get_the_author_meta('description') != '') { ?>
            <h3><?php esc_html_e('ABOUT THE AUTHOR', 'champion') ?>: <span
                        class="author_name"><?php the_author(); ?></span></h3>

            <div class="about_author clearfix">
                <div class="author_image">
                    <?php echo get_avatar(get_the_author_meta('email'), 80); ?>
                </div>
                <div class="author_content"><?php echo get_the_author_meta('description') ?></div>
            </div>
        <?php } ?>

        <?php
        if (comments_open() || get_comments_number()) {
            comments_template();
        }
        ?>

        <?php the_tags('<div class="tags_block"><h3>' . esc_html__('Tags', 'champion') . '</h3><span class="tag-links">', '', '</span></div>'); ?>

        <script type="text/javascript" src="//s7.addthis.com/js/300/addthis_widget.js"></script>
        <div class="addthis_toolbox">
            <a class="addthis_button_compact btn btn-danger btn-lg"><span><?php esc_html_e('Share Now', 'champion'); ?> <i
                            class="fa fa-share-alt"></i></span></a>
        </div>

    </article>
    <!-- #post-## -->
</div>