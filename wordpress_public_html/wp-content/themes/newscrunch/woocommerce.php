<?php
/**
 * woocommerce
 *
 * @package WordPress
 * @subpackage newscrunch
 */

get_header();

//Breadcrumb Section
do_action( 'newscrunch_breadcrumbs_filter' );

//News Highlight Section
newscrunch_highlight_views('inner'); ?>

<section class="spnc-container spnc-single-post spnc-wc-product-page <?php do_action( 'spncp_single_post_layout_class_hook' );  do_action( 'spncp_check_bread_hook' ); ?>" id="content">
    <div class="spnc-row">
    <?php
    //Left Sidebar
    if (!is_product()): if(get_theme_mod('wc_sidebar_layout','right')=='left'): get_sidebar('woocommerce'); endif; endif; ?>
        <div class="<?php if (is_product()):?> spnc-col-1<?php else: ?>spnc-col-<?php echo ( !is_active_sidebar( 'woocommerce' ) ? '1' :'7' ); endif;?> wow-callback zoomIn <?php echo newscrunch_wc_stickycontent();?>">
            <?php woocommerce_content(); ?>
        </div>
    <?php 
    //Right Sidebar
    if (!is_product()): if(get_theme_mod('wc_sidebar_layout','right')=='right'): get_sidebar('woocommerce'); endif; endif; ?>
    </div>
</section>
<?php
get_footer();