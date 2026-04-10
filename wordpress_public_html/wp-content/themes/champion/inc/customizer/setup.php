<?php

require_once( 'print_styles.php' );
require_once( 'controls.php' );
require_once( 'register.php' );

function champion_stm_remove_customizer_sections( $wp_customize ) {
	$wp_customize->remove_section( 'title_tagline' );
	$wp_customize->remove_section( 'colors' );
	$wp_customize->remove_section( 'background_image' );
	$wp_customize->remove_section( 'header_image' );
	$wp_customize->remove_section( 'nav' );
	$wp_customize->remove_section( 'static_front_page' );
}

add_action( 'customize_register', 'champion_stm_remove_customizer_sections' );

function champion_customizer_styles_admin() {
	wp_enqueue_style( 'customizer_style_admin', get_template_directory_uri() . '/inc/customizer/customizer-admin.css', null, ( WP_DEBUG ) ? time() : null, 'all' );
}

add_action( 'customize_controls_print_styles', 'champion_customizer_styles_admin' );

function champion_customizer_preview_js() {
	wp_enqueue_script( 'stm_customizer', get_template_directory_uri() . '/inc/customizer/customizer.js', NULL, ( WP_DEBUG ) ? time() : null, true );
}

add_action( 'customize_controls_print_styles', 'champion_customizer_preview_js' );

function champion_customizer_preview() {
	wp_enqueue_script( 'stm_customizer', get_template_directory_uri() . '/inc/customizer/customizer-preview.js', array( 'customize-preview' ), ( WP_DEBUG ) ? time() : null, true );
}

add_action( 'customize_preview_init', 'champion_customizer_preview' );

function champion_customizer_styles() {
	$general_font_family = get_theme_mod( 'general_font_family' );
	$heading_font_family = get_theme_mod( 'heading_font_family' );
	if(!empty($general_font_family)){
		$general_font_family_src = 'https://fonts.googleapis.com/css?family=' . str_replace( ' ', '+', $general_font_family ) . ':100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic&amp;subset=latin,latin-ext,cyrillic,cyrillic-ext,greek-ext,greek,vietnamese';
		wp_enqueue_style( 'general_font_family', esc_url( $general_font_family_src ), null, null, 'all' );
	}
    if(!empty($heading_font_family)){
        $heading_font_family_src = 'https://fonts.googleapis.com/css?family=' . str_replace( ' ', '+', $heading_font_family ) . ':100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic&amp;subset=latin,latin-ext,cyrillic,cyrillic-ext,greek-ext,greek,vietnamese';
        wp_enqueue_style( 'heading_font_family', esc_url( $heading_font_family_src ), null, null, 'all' );
    }
}

add_action( 'wp_enqueue_scripts', 'champion_customizer_styles' );

function champion_body_class( $classes ) {
	global $wp_customize;

	$bg_custom_image = get_theme_mod( 'bg_custom_image' );
	$site_layout = get_theme_mod( 'site_layout' );

	$classes[]      = get_theme_mod( 'header_type' );
	$classes[]      = $site_layout;
	$classes[]      = get_theme_mod( 'nav_bar_position' );

	if ( ! get_theme_mod( 'preloader' ) ) {
		$classes[] = 'preloader';
	}
	
	if ( ! get_theme_mod( 'nice_scroll' ) ) {
		$classes[] = 'nice_scroll';
	}

	if(empty($bg_custom_image) && $site_layout == 'boxed'){
		$classes[]      = get_theme_mod( 'bg_image' );
	}

	if ( isset( $wp_customize ) ) {
		$classes[] = 'customizer_page';
	}

	return $classes;
}

add_filter( 'body_class', 'champion_body_class' );

function champion_stm_customize_save_after() {

	$header_type     = get_theme_mod( 'header_type' );
	$old_header_type = get_theme_mod( 'old_header_type' );

	if ( $header_type == '' ) {
		$header_type = 'header_1';
	}

	if ( $old_header_type != $header_type ) {
		if ( $header_type == 'header_2' ) {
			set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
			$skin = 'style_2.txt';
		} elseif ( $header_type == 'header_3' ) {
			set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
			$skin = 'style_3.txt';
		} elseif ( $header_type == 'header_4' ) {
			set_theme_mod( 'bg_top_nav_color', 'rgba(255, 255, 255, 0)' );
			$skin = 'style_4.txt';
		} elseif ( $header_type == 'header_5' ) {
			set_theme_mod( 'bg_top_nav_color', 'rgba(21, 22, 24, 0.9)' );
			$skin = 'style_5.txt';
		} else {
			set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
			$skin = 'style_1.txt';
		}

		if(defined('STM_CONFIGURATIONS_PATH')) {
            $backup_file_content = champion_get_local_file_contents(STM_CONFIGURATIONS_PATH . '/demo/data/mega_main_menu/' . $skin);
            if ($backup_file_content !== false && ($options_backup = json_decode($backup_file_content, true))) {
                if (isset($options_backup['last_modified'])) {
                    $options_backup['last_modified'] = time() + 30;
                    set_theme_mod('old_header_type', $header_type);
                    update_option('mega_main_menu_options', $options_backup);
                }
            }
        }
	}

}

add_action('customize_save_after', 'champion_stm_customize_save_after', 99);

function champion_favicon_link() {
	if ( $favicon = get_theme_mod( 'favicon' ) ) {
		echo '<link rel="shortcut icon" type="image/x-icon" href="' . esc_url( $favicon ) . '" />' . "\n";
	} else {
		echo '<link rel="shortcut icon" type="image/x-icon" href="' . get_template_directory_uri() . '/favicon.ico" />' . "\n";
	}
}

add_action( 'wp_head', 'champion_favicon_link' );

if( get_theme_mod( 'base_color' ) == '' ){
	set_theme_mod( 'base_color', '#d61919' );
}