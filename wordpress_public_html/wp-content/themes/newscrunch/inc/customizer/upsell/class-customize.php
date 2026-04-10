<?php
/**
 * Singleton class for handling the theme's customizer integration.
 *
 * @since  1.0
 * @access public
 */
final class Newscrunch_Customize_Upsell {

	/**
	 * Returns the instance.
	 *
	 * @since  1.0
	 * @access public
	 * @return object
	 */
	public static function get_instance() {

		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self;
			$instance->setup_actions();
		}

		return $instance;
	}

	/**
	 * Constructor method.
	 *
	 * @since  1.0
	 * @access private
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Sets up initial actions.
	 *
	 * @since  1.0
	 * @access private
	 * @return void
	 */
	private function setup_actions() {

		// Register panels, sections, settings, controls, and partials.
		add_action( 'customize_register', array( $this, 'sections' ) );

		// Register scripts and styles for the controls.
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_control_scripts' ), 0 );
	}

	/**
	 * Sets up the customizer sections.
	 *
	 * @since  1.0
	 * @access public
	 * @param  object  $manager
	 * @return void
	 */
	public function sections( $manager ) {

		// Load custom sections.
		get_template_part('inc/customizer/upsell/section-import');

		// Register custom section types.
		$manager->register_section_type( 'Newscrunch_Customize_Upsell_Section' );

		// Register sections.
		if ( class_exists('Newscrunch_Plus') )
		{
			$manager->add_section(new Newscrunch_Customize_Upsell_Section($manager,'newscrunch_upsell',
				array(
					'title'    => esc_html__( 'Import Demo', 'newscrunch' ),
					'pro_text' => esc_html__( 'Click Here',  'newscrunch' ),
					'pro_url'  => esc_url( admin_url( 'admin.php?page=newscrunch-plus-welcome' ) ),
					'priority' => -999,
				)
			));
		}
		else
		{
			if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
				$manager->add_section(new Newscrunch_Customize_Upsell_Section($manager,'newscrunch_upsell',
					array(
						'title'    => esc_html__( 'Import Demo', 'newscrunch' ),
						'pro_text' => esc_html__( 'Click Here',  'newscrunch' ),
						'pro_url'  => esc_url( admin_url( 'admin.php?page=newscrunch-welcome' ) ),
						'priority' => -999,
					)
				));
			}
			else {
				$manager->add_section(new Newscrunch_Customize_Upsell_Section($manager,'newscrunch_upsell',
					array(
						'title'    => esc_html__( 'Import Demo', 'newscrunch' ),
						'pro_text' => esc_html__( 'Click Here',  'newscrunch' ),
						'pro_url'  => esc_url( admin_url( 'admin.php?page=newsblogger-welcome' ) ),
						'priority' => -999,
					)
				));
			}
		}
		
	}

	/**
	 * Loads theme customizer CSS.
	 *
	 * @since  1.0
	 * @access public
	 * @return void
	 */
	public function enqueue_control_scripts() {

		wp_enqueue_script( 'newscrunch-upsell-customize-controls', trailingslashit( get_template_directory_uri() ) . 'inc/customizer/upsell/customize-controls.js', array( 'customize-controls' ) );

		wp_enqueue_style( 'newscrunch-upsell-customize-controls', trailingslashit( get_template_directory_uri() ) . 'inc/customizer/upsell/customize-controls.css' );
	}
}

// Doing this customizer thang!
Newscrunch_Customize_Upsell::get_instance();