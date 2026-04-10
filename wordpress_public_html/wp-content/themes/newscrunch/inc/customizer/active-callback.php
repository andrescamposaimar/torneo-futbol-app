<?php
/**
 * Active Callback for customizer settings
 *
 * @package Newscrunch
*/

// callback function for the date time color
function newscrunch_date_time_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_date_time_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the social icon
function newscrunch_social_icons_callback($control) {
    if(true == $control->manager->get_setting('hide_show_social_icons')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the social icon color
function newscrunch_social_icons_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_social_icon_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the site title & tagline setting
function newscrunch_header_text_choice_callback($control) {
    if (true == $control->manager->get_setting('header_text')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the site identity color
function newscrunch_header_text_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_site_identity_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the theme header color
function newscrunch_theme_header_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_theme_header_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the footer widgets
function newscrunch_footer_widgets_callback($control) {
    if(true == $control->manager->get_setting('hide_show_footer_widgets')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the footer overlay
function newscrunch_footer_overlay_callback($control) {
    if(true == $control->manager->get_setting('footer_overlay_enable')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the theme footer color
function newscrunch_theme_footer_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_theme_footer_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the footer copyright
function newscrunch_footer_copyright_callback($control) {
    if(true == $control->manager->get_setting('hide_show_footer_copyright')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the bottom footer color
function newscrunch_bottom_footer_color_callback($control) {
    if(true == $control->manager->get_setting('hide_show_bottom_footer_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the main banner
function newscrunch_main_banner_callback($control) {
    if(true == $control->manager->get_setting('hide_show_banner')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the news highlight
function newscrunch_news_highlight_callback($control) {
    if(true == $control->manager->get_setting('hide_show_news_highlight')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the scrol to top
function newscrunch_scroll_to_top_callback($control) {
    if (true == $control->manager->get_setting('hide_show_scroll_to_top')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the scrol to top color
function newscrunch_scroll_to_top_color_callback($control) {
    if (true == $control->manager->get_setting('hide_show_scroll_to_top_color')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the page title
function newscrunch_page_title_callback($control) {
    if (false == $control->manager->get_setting('enable_page_title')->value()) {
        return false;
    } else {
        return true;
    }
}

//callback function for the breadcrumbs section
function newscrunch_breadcrumb_section_callback($control) {
    if (false == $control->manager->get_setting('breadcrumb_banner_enable')->value()) {
        return false;
    } else {
        return true;
    }
}

// callback function for the featured video
function newscrunch_featured_video_callback($control) {
    if (true == $control->manager->get_setting('hide_show_featured_video')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for the custom color
function newscrunch_custom_color_callback($control) {
    if (true == $control->manager->get_setting('custom_color_enable')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for highlight title option
function newscrunch_highlight_title_option($control) {
    if ('title' == $control->manager->get_setting('newscrunch_highlight_search_option')->value()) {
        return true;
    } else{
         return false;
    }
}
// callback function for highlight category option
function newscrunch_highlight_category_option($control) {
    if ('category' == $control->manager->get_setting('newscrunch_highlight_search_option')->value()) {
        return true;
    } else {
        return false;
    }
}


// callback function for breadcrumb padding
function newscrunch_breadcrumb_padding_callback($control) {
    if (true == $control->manager->get_setting('breadcrumb_section_padding')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for breadcrumb type
function newscrunch_enable_breadcrumb_callback($control) {
    if (true == $control->manager->get_setting('enable_breadcrumb')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for highlight layout option
function newscrunch_highlight_layout_option($control) {
    if ('2' == $control->manager->get_setting('highlight_layout')->value()) {
        return true;
    } else {
        return false;
    }
}

//  Callback function for related Post  
function newscrunch_releted_post_callback($control) {
    if (true == $control->manager->get_setting('newscrunch_enable_related_post')->value()) {
        return true;
    } else {
        return false;
    }
}

//  Callback function for missed section  
function newscrunch_missed_section_callback($control) {
    if (true == $control->manager->get_setting('hide_show_missed_section')->value()) {
        return true;
    } else {
        return false;
    }
}

//  Callback function for missed color section  
function newscrunch_missed_clr_section_callback($control) {
    if (true == $control->manager->get_setting('hide_show_missed_section_color')->value()) {
        return true;
    } else {
        return false;
    }
}
// callback function for single post reordering
function newscrunch_callback_single_post_layout($control) {
    if ('1' == $control->manager->get_setting('newscrunch_single_post_layout')->value() || '2' == $control->manager->get_setting('newscrunch_single_post_layout')->value()) {
        return true;
    } else {
        return false;
    }
}
// callback function for related product  
function newscrunch_related_product_section_callback($control) {
    if (true == $control->manager->get_setting('newscrunch_wc_related_product')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for upsell product  
function newscrunch_upsell_product_section_callback($control) {
    if (true == $control->manager->get_setting('newscrunch_wc_upsell_product')->value()) {
        return true;
    } else {
        return false;
    }
}

// callback function for sell product  
function newscrunch_sell_product_section_callback($control) {
    if (true == $control->manager->get_setting('newscrunch_wc_cross_sell_product')->value()) {
        return true;
    } else {
        return false;
    }
}

// Active callback function to display the control only when "Show Text" is selected
function newscrunch_show_text_active_callback( $control ) {
    // Check if the 'Show Text' option is selected
    return get_theme_mod('newscrunch_wc_product_sale_badge', 'percentage') === 'text';
}