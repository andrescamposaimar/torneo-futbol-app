<?php

if ( ! isset( $content_width ) ) {
	$content_width = 940;
}

add_action( 'after_setup_theme', 'local_theme_setup' );

function local_theme_setup() {
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-header' );
	add_theme_support( 'custom-background' );
    add_theme_support( 'sportspress' );
    add_theme_support( 'woocommerce' );
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-formats', array(
        'aside', 'image', 'video', 'audio', 'quote', 'link', 'gallery',
    ) );
    add_theme_support( 'automatic-feed-links' );
    add_theme_support( 'html5', array(
        'search-form', 'comment-form', 'comment-list', 'gallery', 'caption'
    ) );

	remove_action( 'wp_head', 'wp_generator' );

	add_image_size( 'blog_list', 270, 220, true );
	add_image_size( 'gallery_thumbnail', 80, 80, true );
	add_image_size( 'gallery_image', 560, 367, true );
	add_image_size( 'gallery_image_mini', 143, 116, true );
	add_image_size( 'player_photo', 740, 740, true );
	add_image_size( 'team_logo', 98, 98, false );

	if(!is_textdomain_loaded('champion')) {
        load_theme_textdomain('champion', get_template_directory() . '/languages');
    }

	register_nav_menus(
		array(
			'primary'   => __( 'Top primary menu', 'champion' ),
			'secondary' => __( 'Secondary menu header', 'champion' ),
			'footer_menu' => __( 'Footer menu', 'champion' ),
		)
	);

}

add_action( 'vc_before_init', 'stm_vcSetAsTheme' );

function stm_vcSetAsTheme() {
    vc_set_as_theme( true );
}

add_action('widgets_init', 'champion_register_sidebars');

function champion_register_sidebars() {
    register_sidebar(
        array(
            'name'          => __( 'Primary Sidebar', 'champion' ),
            'id'            => 'sidebar-1',
            'description'   => __( 'Main sidebar that appears on the right.', 'champion' ),
            'before_widget' => '<aside id="%1$s" class="widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="widget_title">',
            'after_title'   => '</div>',
        )
    );

    register_sidebar(
        array(
            'name'          => __( 'Shop Sidebar', 'champion' ),
            'id'            => 'shop',
            'description'   => __( 'Shop sidebar that appears on the right.', 'champion' ),
            'before_widget' => '<aside id="%1$s" class="widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="widget_title">',
            'after_title'   => '</div>',
        )
    );

    register_sidebar(
        array(
            'name'          => __( 'Sport Sidebar', 'champion' ),
            'id'            => 'sport',
            'description'   => __( 'Sport sidebar that appears on the right.', 'champion' ),
            'before_widget' => '<aside id="%1$s" class="widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="widget_title">',
            'after_title'   => '</div>',
        )
    );

    register_sidebar(
        array(
            'name'          => __( 'Footer area', 'champion' ),
            'id'            => 'footer',
            'description'   => __( 'Footer widget area that appears at the bottom.', 'champion' ),
            'before_widget' => '<aside id="%1$s" class="widget footer_widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="widget_title">',
            'after_title'   => '</div>',
        )
    );
}