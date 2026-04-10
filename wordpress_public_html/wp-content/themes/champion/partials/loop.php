<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <div class="post-thumbnail">
        <a href="<?php the_permalink(); ?>">
            <?php
            if (has_post_thumbnail()) {
                the_post_thumbnail('blog_list');
            } else {
                if (is_sticky()) {
                    echo '<img src="' . get_template_directory_uri() . '/assets/images/no-image-red.png" alt="' . esc_attr(get_the_title()) . '">';
                } else {
                    echo '<img src="' . get_template_directory_uri() . '/assets/images/no-image.png" alt="' . esc_attr(get_the_title()) . '">';
                }
            }
            ?>
        </a>
    </div>
    <?php the_title('<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '">', '</a></h2>'); ?>
    <div class="post_tags">
        <?php the_tags(); ?>
    </div>
    <?php the_excerpt(); ?>
    <div class="post_info clearfix">
        <div class="date"><?php echo get_the_date(); ?></div>
        <?php if (!post_password_required() && (comments_open() || get_comments_number())) { ?>
            <div class="comments_num"><a href="<?php echo esc_url(get_permalink()); ?>#respond"><i
                            class="fa fa-comment"></i><?php echo get_comments_number(); ?></a></div>
        <?php } ?>
    </div>

</article>