</div> <!--.container-->
</div> <!--ID-main-->
<footer id="footer">
    <?php get_sidebar('footer'); ?>
    <div class="footer">
        <div class="container">
            <div class="row">
                <div class="col-xs-12 col-sm-5 col-md-5 col-lg-5">
                    <?php if ($copyright = get_theme_mod('copyright')) { ?>
                        <div class="copyright">
                            <?php echo champion_filtered_output($copyright); ?>
                        </div>
                    <?php } else { ?>
	                    <div class="copyright">
		                    <?php esc_html_e('Copyright © 2019 Champion Theme by Stylemix Themes', 'champion'); ?>
	                    </div>
                    <?php } ?>
                </div>
                <div class="col-xs-12 col-sm-7 col-md-7 col-lg-7">
                    <?php
                    wp_nav_menu(
                        array(
                            'theme_location' => 'footer_menu',
                            'depth' => 1,
                            'container' => '',
                            'container_class' => '',
                            'container_id' => '',
                            'menu_class' => 'footer_menu clearfix'
                        )
                    );
                    ?>
                </div>
            </div>
        </div>
    </div>
</footer>
<?php if (get_theme_mod('frontend_customizer')) {
    get_template_part('partials/frontend_customizer');
}
?>
</div> <!--ID-wrapper-->
<?php echo get_theme_mod('google_analytics_script'); ?>
<?php wp_footer(); ?>
</body>
</html>