<?php
// newscrunch default social icons data
if ( ! function_exists( 'newscrunch_social_icons_default_customize_register' ) ) :
	function newscrunch_social_icons_default_customize_register( $wp_customize ) {
				
		$newscrunch_social_icon_content_control = $wp_customize->get_setting( 'social_icons' );
		if ( ! empty( $newscrunch_social_icon_content_control ) ) {
			$newscrunch_social_icon_content_control->default = json_encode( array(
				array(
					'icon_value' 	=> 	'fab fa-facebook-f',
					'link'       	=> 	'#',
					'open_new_tab' 	=> 	'yes',
					'id'         	=> 	'customizer_repeater_641419a132086',
				),
				array(
					'icon_value' 	=> 	'fa-brands fa-x-twitter',
					'link'       	=> 	'#',
					'open_new_tab' 	=> 	'yes',
					'id'         	=> 	'customizer_repeater_641419a132087',
				),
				array(
					'icon_value' 	=> 	'fab fa-dribbble',
					'link'       	=> 	'#',
					'open_new_tab' 	=> 	'yes',
					'id'         	=> 	'customizer_repeater_641419a132088',
				),
				array(
					'icon_value' 	=> 	'fab fa-instagram',
					'link'       	=> 	'#',
					'open_new_tab' 	=> 	'yes',
					'id'         	=> 	'customizer_repeater_641419a132089',
				),
				array(
					'icon_value' 	=> 	'fab fa-youtube',
					'link'       	=> 	'#',
					'open_new_tab' 	=> 	'yes',
					'id'         	=> 	'customizer_repeater_641419a132090',
				)
			) );
		}
	}
add_action( 'customize_register', 'newscrunch_social_icons_default_customize_register' );
endif;