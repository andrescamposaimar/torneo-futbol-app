<?php
/**
 * The sidebar containing the main widget area
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Newscrunch
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>
<div class="spnc-col-9">
    <div class="spnc-sidebar spnc-main-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
        <div class="right-sidebar">
            <?php dynamic_sidebar( 'sidebar-1' ); ?>
        </div>
    </div>
</div>