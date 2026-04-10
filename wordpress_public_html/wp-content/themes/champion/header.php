<!DOCTYPE html>
<!--[if IE 7]>
<html class="ie ie7" <?php language_attributes(); ?>>
<![endif]-->
<!--[if IE 8]>
<html class="ie ie8" <?php language_attributes(); ?>>
<![endif]-->
<!--[if IE 9]>
<html class="ie ie9" <?php language_attributes(); ?>>
<![endif]-->
<!--[if !(IE 7) | !(IE 8) | !(IE 9) ]><!-->
<html <?php language_attributes(); ?>>
<!--<![endif]-->
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="http://gmpg.org/xfn/11">
    <link rel="pingback" href="<?php bloginfo('pingback_url'); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if (!get_theme_mod('preloader') && is_front_page()) { ?>
    <div id="site_preloader"></div>
<?php } ?>
<?php
if (class_exists('WooCommerce')) {
    $cart_url = wc_get_cart_url();
}
?>

<div id="wrapper">
    <header id="header">
        <div class="container">
            <div class="pre_top_nav">
                <div class="row">

                    <div class="col-xs-12 col-sm-7 col-md-7 col-lg-7">
                        <?php champion_lang_switcher(); ?>
                        <?php
                        wp_nav_menu(
                            array(
                                'theme_location' => 'secondary',
                                'depth' => 1,
                                'container' => '',
                                'container_class' => '',
                                'container_id' => '',
                                'menu_class' => 'secondary_menu clearfix'
                            )
                        );
                        ?>
                    </div>

                    <div class="col-xs-12 col-sm-5 col-md-5 col-lg-5">
                        <form class="navbar-form two navbar-right" action="<?php echo esc_url(home_url('/')); ?>"
                              method="get" role="search">
                            <div class="form-group">
                                <input type="text" class="form-control" name="s"
                                       value="<?php echo esc_attr(get_search_query()); ?>"
                                       placeholder="<?php esc_attr_e('Search', 'champion'); ?>">
                            </div>
                            <button type="submit" class="search_button"><i class="fa fa-search"></i></button>
                            <?php if (class_exists('WooCommerce')) { ?>
                                <a href="<?php echo esc_url($cart_url); ?>"><i class="fa fa-shopping-cart"></i></a>
                            <?php } ?>
                        </form>
                        <?php
                        wp_nav_menu(
                            array(
                                'theme_location' => 'secondary',
                                'depth' => 1,
                                'container' => '',
                                'container_class' => '',
                                'container_id' => '',
                                'menu_class' => 'secondary_menu two clearfix'
                            )
                        );
                        ?>
                        <?php if ($socials = get_theme_mod('socials')) { ?>
                            <ul class="top_socials clearfix">
                                <?php foreach ($socials as $key => $social) {
                                    if (!empty($social)) {
                                        ?>
                                        <li class="<?php echo esc_attr($key); ?>">
                                            <?php if ($key == 'instagram') { ?>
                                                <a href="<?php echo esc_url($social); ?>"><i
                                                            class="fa fa-<?php echo esc_attr($key); ?>"></i></a>
                                            <?php } else { ?>
                                                <a href="<?php echo esc_url($social); ?>"><i
                                                            class="fa fa-<?php echo esc_attr($key); ?>-square"></i></a>
                                            <?php } ?>
                                        </li>
                                        <?php
                                    }
                                }
                                ?>
                            </ul>
                        <?php } ?>
                    </div>

                </div>
            </div>
            <?php
            if (class_exists('mega_main_init')) {
                wp_nav_menu(
                    array(
                        'theme_location' => 'primary'
                    )
                );
            }
            ?>
        </div>
        <?php if (!class_exists('mega_main_init')) { ?>
            <nav class="navbar navbar-default" role="navigation">
                <div class="container">
                    <div class="navbar-header">
                        <button type="button" class="navbar-toggle" data-toggle="collapse"
                                data-target="#navbar-collapse-1">
                            <span class="sr-only"><?php esc_html_e('Toggle navigation', 'champion'); ?></span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                        </button>
                        <a class="navbar-brand" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php if (get_theme_mod('logo')) { ?>
                                <img src="<?php echo esc_url(get_theme_mod('logo')); ?> "
                                     alt="<?php echo (get_theme_mod('logo_name')) ? esc_attr(get_theme_mod('logo_name')) : esc_html__('FC Champion', 'champion'); ?>"/>
                            <?php } else { ?>
                                <?php echo (get_theme_mod('logo_name')) ? esc_html(get_theme_mod('logo_name')) : esc_html__('FC Champion', 'champion'); ?>
                            <?php } ?>
                        </a>
                    </div>
                    <div class="collapse navbar-collapse" id="navbar-collapse-1">
                        <?php
                        wp_nav_menu(
                            array(
                                'theme_location' => 'primary',
                                'container' => '',
                                'container_class' => '',
                                'container_id' => '',
                                'menu_class' => 'nav navbar-nav navbar-right',
                                'fallback_cb' => 'wp_bootstrap_navwalker::fallback',
                                'walker' => new wp_bootstrap_navwalker()
                            )
                        );
                        ?>
                    </div>
                </div>
            </nav>
        <?php } ?>
    </header>
    <!--ID-header-->
    <div id="main">
        <div class="container">