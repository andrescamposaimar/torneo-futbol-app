<?php

/* Init shortcodes */
add_action( 'init', 'champion_stm_shortcode_buttons' );
function champion_stm_shortcode_buttons() {
	add_filter( "mce_external_plugins", "champion_stm_add_buttons" );
	add_filter( 'mce_buttons', 'champion_stm_register_buttons' );
}

function champion_stm_add_buttons( $plugin_array ) {
	$plugin_array['stm'] = get_template_directory_uri() . '/inc/tinymce/shortcodes.js?' . time();

	return $plugin_array;
}

function champion_stm_register_buttons( $buttons ) {
	array_push( $buttons, 'shortcodes' );

	return $buttons;
}