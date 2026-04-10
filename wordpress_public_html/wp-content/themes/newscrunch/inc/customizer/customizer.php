<?php 
/**
 * Newscrunch Customizer Controls
 *
 * @package Newscrunch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Newscrunch_Customizer' ) ) :

	/**
	 * The Newscrunch Customizer class
	*/
	class Newscrunch_Customizer {

		/**
		 * Setup class
		*/
		public function __construct() {

			add_action( 'customize_register', 					array( $this, 'custom_controls' ) );
			add_action( 'customize_register', 					array( $this, 'controls_helpers' ) );
			add_action( 'after_setup_theme',  					array( $this, 'register_options' ) );
			add_action( 'customize_controls_enqueue_scripts', 	array( $this, 'custom_customize_enqueue' ) );

		}


		/**
		 * Adds custom controls
		*/
		public function custom_controls( $wp_customize ) {
			// Load customize control classes
			get_template_part( 'inc/customizer/custom-controls/customizer-text-radio/text-radio-control' );
			get_template_part( 'inc/customizer/custom-controls/toggle/class-toggle-control' );
			get_template_part( 'inc/customizer/custom-controls/customizer-tabs/class/class-newscrunch-customize-control-tabs' );
			get_template_part( 'inc/customizer/custom-controls/customizer-repeater/class/customizer-repeater-control' );
			get_template_part( 'inc/customizer/custom-controls/customizer-slider/customizer-slider' );
			get_template_part( 'inc/customizer/custom-controls/customizer-image-radio/customizer-image-radio' );
			get_template_part( 'inc/customizer/custom-controls/customizer-alpha-color-picker/class-customize-alpha-color-control' );
			get_template_part( 'inc/customizer/custom-controls/dropdown-posts/dropdown-posts-control' );
			get_template_part( 'inc/customizer/custom-controls/multiple-category-dropdown/multiple-category-dropdown-control' );
			get_template_part( 'inc/customizer/custom-controls/sortable/class-sortable-control' );

			// Register custom controls
			$wp_customize->register_control_type('Newscrunch_Toggle_Control');
			$wp_customize->register_control_type( 'Newscrunch_Control_Sortable' );
		}


		/**
		 * Adds customizer helpers
		*/
		public function controls_helpers() {
			get_template_part('inc/customizer/active-callback');
		}


		/**
		 * Adds customizer options
		*/
		public function register_options() {
			get_template_part( 'inc/customizer/settings/general-settings' );
			get_template_part( 'inc/customizer/settings/advertisement' );
			get_template_part( 'inc/customizer/settings/woocommerce' );
			get_template_part( 'inc/customizer/settings/top-header' );
			get_template_part( 'inc/customizer/repeater-default-value' );
			get_template_part( 'inc/customizer/settings/site-identity' );
			get_template_part( 'inc/customizer/settings/theme-header' );
			get_template_part( 'inc/customizer/settings/theme-footer' );
			get_template_part( 'inc/customizer/settings/bottom-footer' );
			get_template_part( 'inc/customizer/settings/main-banner' );
			get_template_part( 'inc/customizer/settings/news-highlight' );
			get_template_part( 'inc/customizer/settings/left-content-right-sidebar' );
			get_template_part( 'inc/customizer/settings/left-sidebar-right-content' );
			get_template_part( 'inc/customizer/settings/missed-section' );
			get_template_part( 'inc/customizer/settings/scroll-to-top' );
			get_template_part( 'inc/customizer/settings/archives-options' );
			get_template_part( 'inc/customizer/settings/single-post-options' );
			get_template_part( 'inc/customizer/settings/featured-video' );
			get_template_part( 'inc/customizer/settings/reorder-sections' );
		}


		/**
		 * Load scripts for customizer
		*/
		public function custom_customize_enqueue() {
			/* Enqueue the CSS files */
			wp_enqueue_style( 'newscrunch-customize-css', NEWSCRUNCH_TEMPLATE_DIR_URI .'/inc/customizer/assets/css/customize.css' );
			get_template_part('inc/customizer/custom_style');
		}

	}

endif;

new newscrunch_Customizer();