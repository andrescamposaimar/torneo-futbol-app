<?php
$inc_path = get_template_directory() . '/inc';

/* Initials*/
require_once($inc_path . '/sforms.class.php');
require_once($inc_path . '/wp_bootstrap_navwalker.php');

if (is_admin()) {
    require_once($inc_path . '/tgm/tgm-plugin-registration.php');
}

require_once($inc_path . '/customizer/setup.php');

/* Customize theme */
require_once($inc_path . '/setup.php');
require_once($inc_path . '/shortcodes.php');
require_once($inc_path . '/scripts_styles.php');


/* Widgets init */
require_once($inc_path . '/walker_category.php');
require_once($inc_path . '/product_cat_list_walker.php');


/* Custom functions */
require_once($inc_path . '/custom.php');
require_once($inc_path . '/frontend_customizer.php');
require_once($inc_path . '/breadcrumbs.class.php');
require_once($inc_path . '/pagination.php');
require_once($inc_path . '/comment_form.php');

add_action('after_setup_theme', 'stm_woo_setup');
function stm_woo_setup()
{
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
}