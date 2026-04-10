<?php
$metadata = wp_get_attachment_metadata();
get_header();
?>

    <div id="primary" class="content-area">
        <div class="row">
            <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
                <?php
                if (get_post_meta(get_the_ID(), 'title', true) != 'hide') {
                    the_title('<h1 class="page-title">', '</h1>');
                }
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <div class="entry-attachment">
                        <div class="attachment">
                            <?php champion_stm_the_attached_image(); ?>
                        </div>
                        <!-- .attachment -->

                        <?php if (has_excerpt()) : ?>
                            <div class="entry-caption">
                                <?php the_excerpt(); ?>
                            </div><!-- .entry-caption -->
                        <?php endif; ?>

                        <header class="entry-header">
                            <div class="entry-meta">

                                <span class="entry-date"><time class="entry-date"
                                                               datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time></span>

                                <span class="full-size-link"><a
                                            href="<?php echo esc_url(wp_get_attachment_url()); ?>"><?php echo esc_html($metadata['width']); ?> &times; <?php echo esc_html($metadata['height']); ?></a></span>

                                <span class="parent-post-link"><a
                                            href="<?php echo esc_url(get_permalink($post->post_parent)); ?>"
                                            rel="gallery"><?php echo get_the_title($post->post_parent); ?></a></span>
                                <?php edit_post_link(esc_html__('Edit', 'champion'), '<span class="edit-link">', '</span>'); ?>
                            </div>
                            <!-- .entry-meta -->
                        </header>
                        <!-- .entry-header -->

                    </div>
                    <!-- .entry-attachment -->

                    <?php
                    the_content();
                    wp_link_pages(array(
                        'before' => '<div class="page-links"><span class="page-links-title">' . esc_html__('Pages:', 'champion') . '</span>',
                        'after' => '</div>',
                        'link_before' => '<span>',
                        'link_after' => '</span>',
                    ));
                    ?>

                    <nav id="image-navigation" class="navigation image-navigation">
                        <div class="nav-links">
                            <?php previous_image_link(false, '<div class="previous-image">' . esc_html__('Previous Image', 'champion') . '</div>'); ?>
                            <?php next_image_link(false, '<div class="next-image">' . esc_html__('Next Image', 'champion') . '</div>'); ?>
                        </div>
                        <!-- .nav-links -->
                    </nav>
                    <!-- #image-navigation -->

                </article>
                <!-- #post-## -->
            </div>
            <?php get_sidebar(); ?>
        </div>
    </div>

<?php
get_footer();
