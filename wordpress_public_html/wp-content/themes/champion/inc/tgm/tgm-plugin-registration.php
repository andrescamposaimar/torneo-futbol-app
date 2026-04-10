<?php

require_once dirname(__FILE__) . '/class-tgm-plugin-activation.php';

add_action('tgmpa_register', 'champion_stm_require_plugins');

function champion_stm_require_plugins()
{
    $plugins_path = 'https://champion.stylemixthemes.com/demo-plugins/';
    $plugins = array(
        array(
            'name' => 'Mega Main Menu',
            'slug' => 'mega_main_menu',
            'source' => $plugins_path . '/mega_main_menu.zip',
            'required' => false,
            'external_url' => 'http://menu.megamain.com',
            'version' => '2.1.5'
        ),
        array(
            'name' => 'STM Configurations',
            'slug' => 'stm-configurations',
            'source' => $plugins_path . '/stm-configurations.zip',
            'required' => true,
            'external_url' => 'https://stylemixthemes.com/',
            'version' => '1.1'
        ),
        array(
            'name' => 'WPBakery Visual Composer',
            'slug' => 'js_composer',
            'source' => $plugins_path . '/js_composer.zip',
            'required' => true,
            'external_url' => 'http://vc.wpbakery.com',
            'version' => '6.0.5'
        ),
        array(
            'name' => 'Revolution Slider',
            'slug' => 'revslider',
            'source' => $plugins_path . '/revslider.zip',
            'required' => false,
            'external_url' => 'http://www.themepunch.com/revolution/',
            'version' => '6.1.4'
        ),
        array(
            'name' => 'Ivan Visual Composer',
            'slug' => 'ivan-visual-composer',
            'source' => $plugins_path . '/ivan-visual-composer.zip',
            'external_url' => 'http://code9rs.com/',
            'required' => false,
        ),
        array(
            'name' => 'TS Visual Composer Extend',
            'slug' => 'ts-visual-composer-extend',
            'source' => $plugins_path . '/ts-visual-composer-extend.zip',
            'required' => false,
            'external_url' => 'http://codecanyon.net/item/visual-composer-extensions/7190695/',
            'version' => '5.3.2'
        ),
        array(
            'name' => 'GDPR Compliance & Cookie Consent',
            'slug' => 'stm-gdpr-compliance',
            'source' => $plugins_path . '/stm-gdpr-compliance.zip',
            'required' => false,
            'external_url' => 'https://stylemixthemes.com/',
            'version' => '1.1'
        ),
        array(
            'name' => 'SportsPress – Sports Club & League Manager',
            'slug' => 'sportspress',
            'required' => false
        ),
        array(
            'name' => 'WooCommerce',
            'slug' => 'woocommerce',
            'required' => false
        ),
        array(
            'name' => 'TinyMCE Advanced',
            'slug' => 'tinymce-advanced',
            'required' => false
        )
    );

    tgmpa($plugins, array('is_automatic' => false));

}