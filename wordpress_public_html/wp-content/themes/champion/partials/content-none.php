<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <div class="entry-content">
        <?php if (is_home() && current_user_can('publish_posts')) : ?>

            <p><?php printf(esc_html__('Ready to publish your first post? <a href="%1$s">Get started here</a>.', 'champion'), admin_url('post-new.php')); ?></p>

        <?php elseif (is_search()) : ?>

            <p><?php esc_html_e('Sorry, but nothing matched your search terms. Please try again with some different keywords.', 'champion'); ?></p>
            <div class="widget_search">
                <?php get_search_form(); ?>
            </div>
        <?php
        else : ?>

            <p><?php esc_html_e('It seems we can&rsquo;t find what you&rsquo;re looking for. Perhaps searching can help.', 'champion'); ?></p>
            <div class="widget_search">
                <?php get_search_form(); ?>
            </div>
        <?php endif; ?>
    </div>
    <!-- .entry-content -->
</article><!-- #post-## -->
