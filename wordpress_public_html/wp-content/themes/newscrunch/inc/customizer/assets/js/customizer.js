jQuery( document ).ready(function($) {
    "use strict";
    /**
     * Dropdown Select2 Custom Control
     *
     * @author Anthony Hortin <http://maddisondesigns.com>
     * @license http://www.gnu.org/licenses/gpl-2.0.html
     * @link https://github.com/maddisondesigns
     */

    jQuery('.customize-control-dropdown-select2').each(function(){
        jQuery('.customize-control-select2').select2({
            allowClear: true
        });
    });

    jQuery(".customize-control-select2").on("change", function() {
        var select2Val = jQuery(this).val();
        jQuery(this).parent().find('.customize-control-dropdown-select2').val(select2Val).trigger('change');
    });

    jQuery("#customize-control-highlight_layout #customizer_img_1").click(function(){
        jQuery("#customize-control-newscrunch_highlight_date_cat_option").hide();
    });
     jQuery("#customize-control-highlight_layout #customizer_img_2").click(function(){
        jQuery("body #customize-control-newscrunch_highlight_date_cat_option").show();
    });

     if(jQuery('input[name="highlight_layout"]:checked').val()=="1")
     {
        jQuery("#customize-control-newscrunch_highlight_date_cat_option").hide();
     }
     else
     {
        jQuery("body #customize-control-newscrunch_highlight_date_cat_option").show();
     }


     jQuery("#_customize-input-newscrunch_highlight_search_option").on("change",function(){
         if(jQuery(this).val()=="category")
         {
            jQuery("#customize-control-news_highlight_dropdown_category").show();
            jQuery("#customize-control-news_highlight_num_posts").show();
            jQuery("#customize-control-news_highlight_dropdown_post_title").hide();
         }
         else
         {
            jQuery("#customize-control-news_highlight_dropdown_category").hide();
            jQuery("#customize-control-news_highlight_num_posts").hide();
            jQuery("#customize-control-news_highlight_dropdown_post_title").show();
         }
     });

    if(jQuery("#_customize-input-newscrunch_highlight_search_option").val()=="category")
    {
       jQuery("#customize-control-news_highlight_dropdown_category").show();
       jQuery("#customize-control-news_highlight_num_posts").show();
       jQuery("#customize-control-news_highlight_dropdown_post_title").hide();
    }
    else
    {
        jQuery("#customize-control-news_highlight_dropdown_category").hide();
        jQuery("#customize-control-news_highlight_num_posts").hide();
        jQuery("#customize-control-news_highlight_dropdown_post_title").show();
    }

});

(function($) {
    $( function() {
        var value = $('input[name="newscrunch_logo_view"]:checked').val();
        if(value == 'desktop') {
            $('#customize-control-newscrunch_logo_length').show();
            $('#customize-control-newscrunch_logo_tablet_length').hide();
            $('#customize-control-newscrunch_logo_mobile_length').hide();
        } 
        else if(value == 'tablet') {
            $('#customize-control-newscrunch_logo_tablet_length').show();
            $('#customize-control-newscrunch_logo_length').hide();
            $('#customize-control-newscrunch_logo_mobile_length').hide();
        } 
        else if(value == 'mobile') {
            $('#customize-control-newscrunch_logo_mobile_length').show();
            $('#customize-control-newscrunch_logo_length').hide();
            $('#customize-control-newscrunch_logo_tablet_length').hide();  
        }else{
            $('#customize-control-newscrunch_logo_length').show();
            $('#customize-control-newscrunch_logo_tablet_length').hide();
            $('#customize-control-newscrunch_logo_mobile_length').hide();
        }
        
        wp.customize('newscrunch_logo_view', function(control) {
            control.bind(function( logo_view ) {
                if(logo_view=='desktop')
                {
                    $('#customize-control-newscrunch_logo_length').show();
                    $('#customize-control-newscrunch_logo_tablet_length').hide();
                    $('#customize-control-newscrunch_logo_mobile_length').hide();
                }
                else if(logo_view=='tablet')
                {
                    $('#customize-control-newscrunch_logo_length').hide();
                    $('#customize-control-newscrunch_logo_tablet_length').show();
                    $('#customize-control-newscrunch_logo_mobile_length').hide();
                }
                else if(logo_view=='mobile')
                {
                    $('#customize-control-newscrunch_logo_length').hide();
                    $('#customize-control-newscrunch_logo_tablet_length').hide();
                    $('#customize-control-newscrunch_logo_mobile_length').show();
                }
                else
                {
                    $('#customize-control-newscrunch_logo_length').show();
                    $('#customize-control-newscrunch_logo_tablet_length').hide();
                    $('#customize-control-newscrunch_logo_mobile_length').hide();  
                }
            });
        });
    });
})(jQuery)


jQuery( document ).ready(function($) {
    jQuery('#accordion-section-seo_optiomize_section').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/mdRy1lCau_U" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#sub-accordion-section-theme_color li:first-child').after('<div class="video-guide"><a target="_blank" href="https://youtu.be/ac5uw5QBc6o" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#sub-accordion-section-newscrunch_category_color_section :first-child + li').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/ac5uw5QBc6o?si=RlyV0hkr1M2NUhFN&t=58" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-site_identity_tab').after('<div class="video-guide"><a target="_blank" href="https://youtu.be/ZZrK0j2dEvs" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-theme_header_tab').after('<div class="video-guide"><a target="_blank" href="https://youtu.be/Z3rsjuepWgY" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#accordion-section-newscrunch_date_time_section').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/Z3rsjuepWgY" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-hide_show_banner').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/3vqiJh6KErY" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-newscrunch_highlight_view').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/dxFWQ2GDr0I" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-hide_show_featured_video').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/-PZGoKtnXIM" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#accordion-section-sidebar-widgets-front-page-1').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/wxyvxCnZ_ns?si=5LxLxsPZC6JDTrQo" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#accordion-section-sidebar-widgets-front-page-2').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/wxyvxCnZ_ns?si=5LxLxsPZC6JDTrQo" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#customize-control-front_page_content_sort').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/CP48r3Dqonc" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-hide_show_footer_widgets').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/UGO9P_hZKJE" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#customize-control-hide_show_footer_copyright').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/UGO9P_hZKJE?si=-HPqeQY28N-r01M6&t=68" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('#customize-control-background_color').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/uaX-IxeaOpI" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#customize-control-background_image').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/uaX-IxeaOpI?si=f2DywDe6ONGWfYg8&t=28" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');

    jQuery('.customize-section-title-nav_menus-heading').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/xo3UE5c7j8A" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');


    /* Plus Plugin Video Guide Button */
    jQuery('#accordion-section-top_header_typography').before('<div class="video-guide"><a target="_blank" href="https://youtu.be/_xqVogwfvVY" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');
    jQuery('#customize-control-newscrunch_plus_missed_tab').after('<div class="video-guide"><a target="_blank" href="https://youtu.be/Q1TqMFtjjsE" class="video-icon" >Video Guide<span class="dashicons dashicons-video-alt3 video-tutorial"></span></a></div>');


    /* Left Content Right Sidebar */
    jQuery('#accordion-section-sidebar-widgets-front-page-1').after('<div class="cus-side-btn"><h3>Widgets you can show in the Front Content Area</h3><a target="_blank" href="https://helpdoc.spicethemes.com/newscrunch/widgets/#carea_wid" class=" button-primary">Learn More</a></div>');
    jQuery('#accordion-section-sidebar-widgets-front-page-side-1').after('<div class="cus-side-btn"><h3>Widgets you can show in the Sidebar Area</h3><a target="_blank" href="https://helpdoc.spicethemes.com/newscrunch/widgets/#sarea_wid" class=" button-primary">Learn More</a></div>');
    
    /* Left Sidebar Right Content */
    jQuery('#accordion-section-sidebar-widgets-front-page-2').after('<div class="cus-side-btn"><h3>Widgets you can show in the Front Content Area</h3><a target="_blank" href="https://helpdoc.spicethemes.com/newscrunch/widgets/#carea_wid" class=" button-primary">Learn More</a></div>');
    jQuery('#accordion-section-sidebar-widgets-front-page-side-2').after('<div class="cus-side-btn"><h3>Widgets you can show in the Sidebar Area</h3><a target="_blank" href="https://helpdoc.spicethemes.com/newscrunch/widgets/#sarea_wid" class=" button-primary">Learn More</a></div>');
});