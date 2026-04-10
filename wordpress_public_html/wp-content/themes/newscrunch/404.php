<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @link https://codex.wordpress.org/Creating_an_Error_404_Page
 *
 * @package Newscrunch
 */
get_header();
do_action( 'newscrunch_breadcrumbs_filter' );
newscrunch_highlight_views('inner');
?>
<section data-wow-delay=".8s" class="wow-callback zoomIn page-section-space blog default spnc-page-404" id="content">
	<div class="spnc_column_container">
		<div class="spnc_error_404">
			<div class="spnc_error_heading">
				<h2><?php esc_html_e('Error 404','newscrunch');?></h2>
				<h3><?php esc_html_e('Oops! Page not found','newscrunch'); ?></h3>
			</div>
			<div class="spnc_404_link">
				<a href="<?php echo esc_url(home_url('/')); ?>" title="<?php esc_attr_e('Back to Home','newscrunch'); ?>">		<?php esc_html_e('Back to Home','newscrunch'); ?>
				</a>
			</div>
			<div class="spnc_404_img">
				<img src="<?php echo esc_url(NEWSCRUNCH_TEMPLATE_DIR_URI.'/assets/images/404-img.png');?>" alt="<?php esc_attr_e('404 Image','newscrunch'); ?>">
			</div>	 	
		</div>
    </div>
</section>
<?php
get_footer(); ?>