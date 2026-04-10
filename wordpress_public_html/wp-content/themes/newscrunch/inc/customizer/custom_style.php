<?php
// Add custom CSS in WordPress Customizer
function newscrunch_customizer_styles() { ?>
    <style>
		li#accordion-panel-newscrunch_general_settings:before { content: "<?php echo __('General Options', 'newscrunch'); ?>" }
		li#accordion-panel-newscrunch_top_header_panel:before { content: "<?php echo __('Header Options', 'newscrunch'); ?>" }
		li#accordion-section-newscrunch_theme_footer:before { content: "<?php echo __('Footer Options', 'newscrunch'); ?>" }
		li#accordion-section-colors:before { content: "<?php echo __('Core Options', 'newscrunch'); ?>" }
		li#accordion-section-newscrunch_main_banner_section:before { content: "<?php echo __('Frontpage Sections', 'newscrunch'); ?>" }
		li#accordion-section-newscrunch_blog_section:before { content: "<?php echo __('Theme Options', 'newscrunch'); ?>" }
		#customize-control-banner_left_dropdown_category:before { content: "<?php echo __('Left', 'newscrunch'); ?>" }
		#customize-control-banner_center_dropdown_category:before { content: "<?php echo __('Center', 'newscrunch'); ?>" }
		#customize-control-banner_right_dropdown_category:before { content:"<?php echo __('Right', 'newscrunch'); ?>" }
		#customize-control-headerpreset_setting_enable:before{ content: "<?php echo __('Background Setting', 'newscrunch'); ?>" }
		#customize-control-subscribe_btn_enable:before{ content: "<?php echo __('Subscribe Button', 'newscrunch'); ?>" }
		#customize-control-banner_ads_enable:before{ content: "<?php echo __('Banner Advertisement', 'newscrunch'); ?>" }
		#sub-accordion-section-newscrunch_theme_header li#customize-control-menu_link_color:before {content: "<?php echo __('Menus', 'newscrunch'); ?>"}
		#sub-accordion-section-newscrunch_theme_header li#customize-control-submenu_bg_color:before {content: "<?php echo __('Submenus', 'newscrunch'); ?>"}
		#sub-accordion-section-newscrunch_theme_header li#customize-control-search_icon_color:before {content: "<?php echo __('Search Icon & Switcher Icon', 'newscrunch'); ?>"}
		#sub-accordion-section-newscrunch_bottom_footer li#customize-control-bfooter_menu_color:before {content: "<?php echo __('Menus', 'newscrunch'); ?>"}
		#sub-accordion-section-newscrunch_bottom_footer li#customize-control-copyright_text_color:before {content: "<?php echo __('Copyright', 'newscrunch'); ?>"}
	</style>
<?php }
add_action('customize_controls_print_styles', 'newscrunch_customizer_styles');