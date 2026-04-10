<?php

function champion_theme_register_customizer_options( $wp_customize ) {

	$wp_customize->add_section( 'layout', array(
			'title'    => esc_html__( 'Layout', 'champion' ),
			'priority' => 10
		)
	);

	$wp_customize->add_setting( 'site_layout', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'site_layout', array(
			'label'    => esc_html__( 'Site Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'site_layout',
			'priority' => 10,
			'type'     => 'radio',
			'choices'  => array(
				''      => esc_html__( 'Full width', 'champion' ),
				'boxed' => esc_html__( 'Boxed', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'content_layout', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'content_layout', array(
			'label'    => esc_html__( 'Content Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'content_layout',
			'priority' => 20,
			'type'     => 'radio',
			'choices'  => array(
				''             => esc_html__( 'Sidebar Right', 'champion' ),
				'sidebar_left' => esc_html__( 'Sidebar Left', 'champion' ),
				'fullwidth'    => esc_html__( 'Fullwidth', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'blog_layout', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'blog_layout', array(
			'label'    => esc_html__( 'Blog Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'blog_layout',
			'priority' => 23,
			'type'     => 'radio',
			'choices'  => array(
				'sidebar_right' => esc_html__( 'Sidebar Right', 'champion' ),
				'sidebar_left'  => esc_html__( 'Sidebar Left', 'champion' ),
				''              => esc_html__( 'Fullwidth', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'shop_layout', array(
		'default'           => 'sidebar_right',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'shop_layout', array(
			'label'    => esc_html__( 'Shop Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'shop_layout',
			'priority' => 25,
			'type'     => 'radio',
			'choices'  => array(
				'sidebar_right' => esc_html__( 'Sidebar Right', 'champion' ),
				'sidebar_left'  => esc_html__( 'Sidebar Left', 'champion' ),
				'fullwidth'     => esc_html__( 'Fullwidth', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'content_layout', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'content_layout', array(
			'label'    => esc_html__( 'Content Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'content_layout',
			'priority' => 20,
			'type'     => 'radio',
			'choices'  => array(
				''             => esc_html__( 'Sidebar Right', 'champion' ),
				'sidebar_left' => esc_html__( 'Sidebar Left', 'champion' ),
				'fullwidth'    => esc_html__( 'Fullwidth', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'background_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'background_subtitle', array(
			'label'    => esc_html__( 'Background', 'champion' ),
			'section'  => 'layout',
			'settings' => 'background_subtitle',
			'priority' => 30
		) )
	);

	$wp_customize->add_setting( 'bg_image', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_Bg_Control( $wp_customize, 'bg_image', array(
			'label'    => esc_html__( 'Background Image', 'champion' ),
			'section'  => 'layout',
			'settings' => 'bg_image',
			'priority' => 60,
			'choices'  => array(
				''              => '',
				'pattern-01'    => esc_html__( 'pattern-01', 'champion' ),
				'pattern-02'    => __( 'pattern-02', 'champion' ),
				'pattern-03'    => __( 'pattern-03', 'champion' ),
				'pattern-04'    => __( 'pattern-04', 'champion' ),
				'pattern-05'    => __( 'pattern-05', 'champion' ),
				'background-01' => __( 'background-01-thumb', 'champion' ),
				'background-02' => __( 'background-02-thumb', 'champion' ),
				'background-03' => __( 'background-03-thumb', 'champion' ),
				'background-04' => __( 'background-04-thumb', 'champion' ),
				'background-05' => __( 'background-05-thumb', 'champion' ),
			)
		) )
	);

	$wp_customize->add_setting( 'bg_custom_image', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Image_Control( $wp_customize, 'bg_custom_image', array(
			'label'    => __( 'Custom Background Image', 'champion' ),
			'section'  => 'layout',
			'settings' => 'bg_custom_image',
			'priority' => 70
		) )
	);


	$wp_customize->add_section( 'typography', array(
			'title'    => __( 'Typography', 'champion' ),
			'priority' => 15
		)
	);

	$fonts_array = file( get_template_directory() . '/inc/customizer/webfonts.json' );
	$fonts_array = json_decode( implode( '', $fonts_array ), true );
	$fonts       = array(
		'' => __( 'Default', 'champion' )
	);
	foreach ( $fonts_array['items'] as $val ) {
		$fonts[ esc_attr( $val['family'] ) ] = esc_attr( $val['family'] );
	}

	$wp_customize->add_setting( 'general_font_family', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'general_font_family', array(
		'label'    => __( 'Font Family 1', 'champion' ),
		'section'  => 'typography',
		'settings' => 'general_font_family',
		'priority' => 20,
		'type'     => 'select',
		'choices'  => $fonts
	) );
	
	$wp_customize->add_setting( 'heading_font_family', array(
		'default'           => '',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'heading_font_family', array(
		'label'    => __( 'Font Family 2', 'champion' ),
		'section'  => 'typography',
		'settings' => 'heading_font_family',
		'priority' => 105,
		'type'     => 'select',
		'choices'  => $fonts
	) );

	$wp_customize->add_setting( 'heading_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'heading_subtitle', array(
			'label'    => __( 'Heading', 'champion' ),
			'section'  => 'typography',
			'settings' => 'heading_subtitle',
			'priority' => 110
		) )
	);

	$wp_customize->add_setting( 'heading_color', array(
		'default'           => '#252c33',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'heading_color', array(
			'label'    => __( 'Heading Color', 'champion' ),
			'section'  => 'typography',
			'settings' => 'heading_color',
			'priority' => 120
		) )
	);

	$wp_customize->add_setting( 'heading_font_weight', array(
		'default'           => '400',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'heading_font_weight', array(
			'label'    => __( 'Heading Font Weight', 'champion' ),
			'section'  => 'typography',
			'settings' => 'heading_font_weight',
			'priority' => 125,
			'type'     => 'select',
			'choices'  => array(
				'100'       => __( 'Thin', 'champion' ),
				'100italic' => __( 'Thin Italic', 'champion' ),
				'200'       => __( 'Light', 'champion' ),
				'200italic' => __( 'Light Italic', 'champion' ),
				'300'       => __( 'Book', 'champion' ),
				'300italic' => __( 'Book Italic', 'champion' ),
				'400'       => __( 'Regular', 'champion' ),
				'400italic' => __( 'Regular Italic', 'champion' ),
				'500'       => __( 'Medium', 'champion' ),
				'500italic' => __( 'Medium Italic', 'champion' ),
				'600'       => __( 'Semi-Bold', 'champion' ),
				'600italic' => __( 'Semi-Bold Italic', 'champion' ),
				'700'       => __( 'Bold', 'champion' ),
				'700italic' => __( 'Bold Italic', 'champion' ),
				'800'       => __( 'Extra Bold', 'champion' ),
				'800italic' => __( 'Extra Bold Italic', 'champion' ),
				'900'       => __( 'Ultra Bold', 'champion' ),
				'900italic' => __( 'Ultra Bold Italic', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'h1_font_size', array(
		'default'           => '60',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h1_font_size', array(
			'label'    => __( 'H1 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h1_font_size',
			'priority' => 130
		)
	);

	$wp_customize->add_setting( 'h2_font_size', array(
		'default'           => '30',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h2_font_size', array(
			'label'    => __( 'H2 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h2_font_size',
			'priority' => 140
		)
	);

	$wp_customize->add_setting( 'h3_font_size', array(
		'default'           => '20',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h3_font_size', array(
			'label'    => __( 'H3 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h3_font_size',
			'priority' => 150
		)
	);

	$wp_customize->add_setting( 'h4_font_size', array(
		'default'           => '17',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h4_font_size', array(
			'label'    => __( 'H4 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h4_font_size',
			'priority' => 160
		)
	);

	$wp_customize->add_setting( 'h5_font_size', array(
		'default'           => '15',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h5_font_size', array(
			'label'    => __( 'H5 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h5_font_size',
			'priority' => 170
		)
	);

	$wp_customize->add_setting( 'h6_font_size', array(
		'default'           => '14',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'h6_font_size', array(
			'label'    => __( 'H6 Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'h6_font_size',
			'priority' => 180
		)
	);

	$wp_customize->add_setting( 'body_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'body_subtitle', array(
			'label'    => __( 'Body', 'champion' ),
			'section'  => 'typography',
			'settings' => 'body_subtitle',
			'priority' => 190
		) )
	);

	$wp_customize->add_setting( 'body_font_size', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'body_font_size', array(
			'label'    => __( 'Body Font Size (px)', 'champion' ),
			'section'  => 'typography',
			'settings' => 'body_font_size',
			'priority' => 210
		)
	);


	$wp_customize->add_section(
		'stm_colors', array(
			'title'    => __( 'Colors', 'champion' ),
			'priority' => 20
		)
	);
	
	$wp_customize->add_setting( 'base_color', array(
		'default'           => '#d61919',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'base_color', array(
			'label'    => __( 'Base Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'base_color',
			'priority' => 5
		) )
	);

	$wp_customize->add_setting( 'bg_color', array(
		'default'           => '#f5f5f5',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'bg_color', array(
			'label'    => __( 'Background Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'bg_color',
			'priority' => 10
		) )
	);

	$wp_customize->add_setting( 'bg_content_color', array(
		'default'           => '#f5f5f5',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'bg_content_color', array(
			'label'    => __( 'Background Content Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'bg_content_color',
			'priority' => 20
		) )
	);

	$wp_customize->add_setting( 'bg_top_nav_color', array(
		'default'           => '#f5f5f5',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'bg_top_nav_color', array(
			'label'    => __( 'Background Top Nav Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'bg_top_nav_color',
			'priority' => 30
		) )
	);

	$wp_customize->add_setting( 'bg_footer_color', array(
		'default'           => '#fff',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'bg_footer_color', array(
			'label'    => __( 'Background Footer Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'bg_footer_color',
			'priority' => 40
		) )
	);

	$wp_customize->add_setting( 'body_font_color', array(
		'default'           => '#252c33',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'body_font_color', array(
			'label'    => __( 'Body Font Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'body_font_color',
			'priority' => 70
		) )
	);

	$wp_customize->add_setting( 'copyright_color', array(
		'default'           => '#868686',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new WP_Customize_Color_Control( $wp_customize, 'copyright_color', array(
			'label'    => __( 'Copyright Color', 'champion' ),
			'section'  => 'stm_colors',
			'settings' => 'copyright_color',
			'priority' => 80
		) )
	);


	$wp_customize->add_section(
		'header', array(
			'title'    => __( 'Header', 'champion' ),
			'priority' => 30
		)
	);

	$wp_customize->add_setting( 'nav_bar_position', array(
		'default'           => 'nav_bar_static',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'nav_bar_position', array(
			'label'    => __( 'Navbar Position', 'champion' ),
			'section'  => 'header',
			'settings' => 'nav_bar_position',
			'priority' => 10,
			'type'     => 'radio',
			'choices'  => array(
				'nav_bar_static' => __( 'Static Top', 'champion' ),
				'nav_bar_fixed'  => __( 'Fixed Top', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'header_type', array(
		'default'           => 'header_3',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'header_type', array(
			'label'    => __( 'Header type:', 'champion' ),
			'section'  => 'header',
			'settings' => 'header_type',
			'priority' => 15,
			'type'     => 'radio',
			'choices'  => array(
				''         => __( 'Header 1', 'champion' ),
				'header_2' => __( 'Header 2', 'champion' ),
				'header_3' => __( 'Header 3', 'champion' ),
				'header_4' => __( 'Header 4', 'champion' ),
				'header_5' => __( 'Header 5', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'logo_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'logo_subtitle', array(
			'label'    => __( 'Logo', 'champion' ),
			'section'  => 'header',
			'settings' => 'logo_subtitle',
			'priority' => 30
		) )
	);

	$wp_customize->add_setting( 'logo', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'logo',
			array(
				'label'    => __( 'Logo', 'champion' ),
				'section'  => 'header',
				'settings' => 'logo',
				'priority' => 40,
				'context'  => ''
			)
		)
	);

	$wp_customize->add_setting( 'logo_font_size', array(
		'default'           => 20,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'logo_font_size', array(
			'label'    => __( 'Logo Font Size (px)', 'champion' ),
			'section'  => 'header',
			'settings' => 'logo_font_size',
			'priority' => 50
		)
	);

	$wp_customize->add_setting( 'logo_name', array(
		'default'           => __( 'FC Champion', 'champion' ),
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'logo_name', array(
			'label'    => __( 'Logo Name', 'champion' ),
			'section'  => 'header',
			'settings' => 'logo_name',
			'priority' => 60
		)
	);

	$wp_customize->add_section( 'footer', array(
			'title'    => __( 'Footer', 'champion' ),
			'priority' => 50,
		)
	);

	$wp_customize->add_setting( 'copyright', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'copyright', array(
			'label'    => __( 'Copyright', 'champion' ),
			'section'  => 'footer',
			'setting'  => 'copyright',
			'priority' => 10
		)
	);

	$wp_customize->add_setting( 'stm_widget_areas', array(
		'default'           => 3,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'stm_widget_areas', array(
			'label'    => __( 'Widget Areas', 'champion' ),
			'section'  => 'footer',
			'setting'  => 'stm_widget_areas',
			'priority' => 20,
			'type'     => 'radio',
			'choices'  => array(
				1          => __( 'One', 'champion' ),
				2          => __( 'Two', 'champion' ),
				3          => __( 'Three', 'champion' ),
				4          => __( 'Four', 'champion' ),
				'disabled' => __( 'Disable Widgets', 'champion' )
			)
		)
	);

	$wp_customize->add_section( 'other', array(
			'title'    => __( 'Other', 'champion' ),
			'priority' => 80
		)
	);

	$wp_customize->add_setting( 'breadcrumbs', array(
		'default'           => 0,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'breadcrumbs', array(
			'label'    => __( 'Breadcrumbs', 'champion' ),
			'section'  => 'other',
			'settings' => 'breadcrumbs',
			'priority' => 10,
			'type'     => 'radio',
			'choices'  => array(
				0 => __( 'Show', 'champion' ),
				1 => __( 'Hide', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'preloader', array(
		'default'           => 0,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'preloader', array(
			'label'    => __( 'Preloader', 'champion' ),
			'section'  => 'other',
			'settings' => 'preloader',
			'priority' => 15,
			'type'     => 'radio',
			'choices'  => array(
				0 => __( 'Show', 'champion' ),
				1 => __( 'Hide', 'champion' )
			)
		)
	);
	
	$wp_customize->add_setting( 'nice_scroll', array(
		'default'           => 1,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'nice_scroll', array(
			'label'    => __( 'Nice Scroll', 'champion' ),
			'section'  => 'other',
			'settings' => 'nice_scroll',
			'priority' => 15,
			'type'     => 'radio',
			'choices'  => array(
				0 => __( 'Enable', 'champion' ),
				1 => __( 'Disable', 'champion' )
			)
		)
	);
	
	$wp_customize->add_setting( 'frontend_customizer', array(
		'default'           => 0,
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'frontend_customizer', array(
			'label'    => __( 'Frontend Customizer', 'champion' ),
			'section'  => 'other',
			'settings' => 'frontend_customizer',
			'priority' => 15,
			'type'     => 'radio',
			'choices'  => array(
				0 => __( 'Hide', 'champion' ),
				1 => __( 'Show', 'champion' )
			)
		)
	);

	$wp_customize->add_setting( 'favicon', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'favicon',
			array(
				'label'    => __( 'Favicon', 'champion' ),
				'section'  => 'other',
				'settings' => 'favicon',
				'priority' => 16,
				'context'  => ''
			)
		)
	);
	
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

	if ( champion_is_use_plugin( 'woocommerce/woocommerce.php' ) && champion_is_use_plugin( 'revslider/revslider.php' )) {

		$wp_customize->add_setting( 'shop_slider', array(
			'default'           => 0,
			'sanitize_callback' => 'champion_stm_sanitize_callback'
		) );

		$wp_customize->add_control( 'shop_slider', array(
				'label'    => __( 'Shop Slider', 'champion' ),
				'section'  => 'other',
				'settings' => 'shop_slider',
				'priority' => 17,
				'type'     => 'radio',
				'choices'  => array(
					0 => __( 'Show', 'champion' ),
					1 => __( 'Hide', 'champion' )
				)
			)
		);

		if ( class_exists( 'RevSlider' ) ) {
			$rev_slider = new RevSlider();
			$sliders = $rev_slider->get_sliders();
		}
		
		$revsliders = array();
		if ( !empty($sliders) ) {
			foreach ( $sliders as $slider ) {
			    if ( !empty( $slider->alias ) ) {
				    $revsliders[ esc_attr( $slider->alias ) ] = esc_attr( $slider->title );
			    }
			}
		} else {
			$revsliders[0] = __( 'No sliders found', 'champion' );
		}

		$wp_customize->add_setting( 'shop_slider_slug', array(
			'default'           => 'shop',
			'sanitize_callback' => 'champion_stm_sanitize_callback'
		) );

		$wp_customize->add_control( 'shop_slider_slug', array(
				'label'    => __( 'Select Shop Slider', 'champion' ),
				'section'  => 'other',
				'settings' => 'shop_slider_slug',
				'priority' => 18,
				'type'     => 'select',
				'choices'  => $revsliders
			)
		);

	}

	$wp_customize->add_setting( 'socials_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'socials_subtitle', array(
			'label'    => __( 'Social Buttons', 'champion' ),
			'section'  => 'other',
			'settings' => 'socials_subtitle',
			'priority' => 20
		) )
	);

	$socialLinks = array(
		'facebook'  => __( 'Facebook', 'champion' ),
		'twitter'   => __( 'Twitter', 'champion' ),
		'instagram' => __( 'Instagram', 'champion' ),
		'youtube'   => __( 'Youtube', 'champion' )
	);

	foreach ( $socialLinks as $setting => $label ) {
		$wp_customize->add_setting( 'socials[' . $setting . ']', array(
			'sanitize_callback' => 'champion_stm_sanitize_callback'
		) );
		$wp_customize->add_control( 'socials[' . $setting . ']', array(
				'label'    => $label,
				'section'  => 'other',
				'settings' => 'socials[' . $setting . ']',
				'type'     => 'text',
				'priority' => 30
			)
		);
	}

	$wp_customize->add_setting( 'google_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'google_subtitle', array(
			'label'    => __( 'Google', 'champion' ),
			'section'  => 'other',
			'settings' => 'google_subtitle',
			'priority' => 40
		) )
	);

	$wp_customize->add_setting( 'google_analytics_script', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback',
	) );

	$wp_customize->add_control(
		new Stm_Customize_Textarea_Control( $wp_customize, 'google_analytics_script', array(
			'label'    => __( 'Google Analytics Script', 'champion' ),
			'section'  => 'other',
			'settings' => 'google_analytics_script',
			'priority' => 50
		) )
	);

	$wp_customize->add_setting( 'mailchimp_subtitle', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control(
		new Stm_Customize_SubTitle_Control( $wp_customize, 'mailchimp_subtitle', array(
			'label'    => __( 'Mailchimp', 'champion' ),
			'section'  => 'other',
			'settings' => 'mailchimp_subtitle',
			'priority' => 60
		) )
	);

	$wp_customize->add_setting( 'mailchimp_api_key', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'mailchimp_api_key', array(
			'label'    => 'MailChimp ApiKey',
			'section'  => 'other',
			'settings' => 'mailchimp_api_key',
			'type'     => 'text',
			'priority' => 70
		)
	);

	$wp_customize->add_setting( 'mailchimp_list_id', array(
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'mailchimp_list_id', array(
			'label'    => 'MailChimp ListID',
			'section'  => 'other',
			'settings' => 'mailchimp_list_id',
			'type'     => 'text',
			'priority' => 80
		)
	);




	/*UPDATES*/
	$wp_customize->add_setting( 'event_list_layout', array(
		'default'           => 'theme',
		'sanitize_callback' => 'champion_stm_sanitize_callback'
	) );

	$wp_customize->add_control( 'event_list_layout', array(
			'label'    => __( 'Event List Layout', 'champion' ),
			'section'  => 'layout',
			'settings' => 'event_list_layout',
			'priority' => 25,
			'type'     => 'radio',
			'choices'  => array(
				'theme'=> __( 'Theme Default', 'champion' ),
				'sp' => __( 'Sportpress Customizable', 'champion' ),
			),
			'default' => 'theme'
		)
	);


	$wp_customize->get_setting( 'site_layout' )->transport      = 'postMessage';
	$wp_customize->get_setting( 'bg_color' )->transport         = 'postMessage';
	$wp_customize->get_setting( 'bg_content_color' )->transport = 'postMessage';
	$wp_customize->get_setting( 'bg_top_nav_color' )->transport = 'postMessage';
	$wp_customize->get_setting( 'bg_footer_color' )->transport  = 'postMessage';
	$wp_customize->get_setting( 'copyright_color' )->transport  = 'postMessage';
	$wp_customize->get_setting( 'bg_image' )->transport         = 'postMessage';
	$wp_customize->get_setting( 'bg_custom_image' )->transport  = 'postMessage';
	$wp_customize->get_setting( 'nav_bar_position' )->transport = 'postMessage';
	$wp_customize->get_setting( 'header_type' )->transport      = 'postMessage';


}

add_action( 'customize_register', 'champion_theme_register_customizer_options' );

function champion_stm_sanitize_callback( $value ) {
	return $value;
}