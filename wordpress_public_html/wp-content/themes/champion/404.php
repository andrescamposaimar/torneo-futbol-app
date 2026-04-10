<?php get_header(); ?>
<?php get_template_part('partials/breadcrumbs'); ?>
    <h1 class="page-title error"><?php esc_html_e('404', 'champion'); ?></h1>
    <article>
        <div class="message_404">:(</div>
        <h3><?php esc_html_e('THE PAGE YOU ARE LOOKING FOR CANNOT BE FOUND', 'champion'); ?></h3>

        <p><?php esc_html_e('You may want to check the following links:', 'champion'); ?></p>

        <div class="page_404_nav">
            <a class="btn btn-danger btn-lg no-grd" href="<?php echo esc_url(home_url()); ?>">
                <?php esc_html_e('Homepage', 'champion'); ?>
                <i class="fa fa-home"></i>
            </a>
            <a class="btn btn-info red btn-lg no-grd" href="<?php echo esc_url(home_url() . '/contact/'); ?>">
                <?php esc_html_e('Contact', 'champion'); ?>
                <i class="fa fa-envelope"></i>
            </a>
        </div>
    </article><!-- #post-## -->
<?php
get_footer();