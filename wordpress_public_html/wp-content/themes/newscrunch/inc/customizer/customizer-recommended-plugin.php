<?php
/* Notifications in customizer */
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer-notify/newscrunch-customizer-notify.php';

function newscrunch_customizer_notify_setup() {
	$newscrunch_config_customizer = array(
		'recommended_plugins'       => array(
			'spice-starter-sites' => array(
				'recommended' => true,
				'description' => sprintf( esc_html__( 'Install and activate the','newscrunch') . ' %s ' . esc_html__('plugin to import demo.', 'newscrunch' ), sprintf( '<strong>%s</strong>', 'Spice Starter Sites' ) ),
			),
		),
		'recommended_actions'       => array(),
		'recommended_actions_title' => esc_html__( 'Recommended Actions', 'newscrunch' ),
		'recommended_plugins_title' => esc_html__( 'Recommended Plugin', 'newscrunch' ),
		'install_button_label'      => esc_html__( 'Install and Activate', 'newscrunch' ),
		'activate_button_label'     => esc_html__( 'Activate', 'newscrunch' ),
		'deactivate_button_label'   => esc_html__( 'Deactivate', 'newscrunch' ),
	);
	Newscrunch_Customizer_Notify::init(apply_filters('newscrunch_customizer_notify_array', $newscrunch_config_customizer ) );
}
add_action( 'init', 'newscrunch_customizer_notify_setup' );