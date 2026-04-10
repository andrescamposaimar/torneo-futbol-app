<?php
/**
 * woocommerce side bar
 *
 * @package WordPress
 * @subpackage newscrunch
 */

if ( ! is_active_sidebar( 'woocommerce' ) ) {
    return;
}
?>
<div class="spnc-col-9 <?php echo newscrunch_wc_sidebar_sticky();?>">
    <div class="spnc-sidebar spnc-wc-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
        <div class="right-sidebar">
            <?php dynamic_sidebar( 'woocommerce' ); ?>
        </div>
    </div>
</div>