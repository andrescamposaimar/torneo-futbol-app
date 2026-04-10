(function($) {
    $( function() {

        if(jQuery("#_customize-input-spncp_wc_product_sale_badge").val()=="percentage")
        {
            jQuery("#customize-control-spncp_wc_product_sale_badge_text").hide();
        }
        else
        {
            jQuery("#customize-control-spncp_wc_product_sale_badge_text").show();
        }
        
        /* =============================================================
        ** HIDE SHOW HEADER PRESET SETTINGS
        ================================================================ */
        
        //CONDITION 1: WHEN AUTOLOAD CHECK THEME HEADER SETTINGS
        var theme_header_autoload = $('input[name="header_layout"]:checked').val();
        //run when header 1, header 2, header 3 selected
        if(theme_header_autoload == "customizer_img_1" || theme_header_autoload == "customizer_img_2" || theme_header_autoload == "customizer_img_3")
        {
            $("#customize-control-hide_show_header_border").show();        //Show Enable/Disable Border Setting
            $("#customize-control-hide_show_header_border_radius").show(); //Show Enable/Disable Border Radius Settig
        }
        else
        {
            $("#customize-control-hide_show_header_border").hide();        //Hide Enable/Disable Border
            $("#customize-control-hide_show_header_border_radius").hide(); //Hide Enable/Disable Border Radius Setting
        }
        
        // run only if header 9 & header 11 selected
        if((theme_header_autoload == 7) || (theme_header_autoload == 9))
        {
            $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").show();
            $("#customize-control-header_seven_img_overlay .customize-inside-control-row").show();
            $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").show();
            $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").show();
            $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").show();
            $("#customize-control-header_seven_img_reapeat select").show();
            $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").show();
            $("#customize-control-headerpreset_setting_enable").show();
                if(theme_header_autoload == 9)
                {
                    $("#customize-control-advertisement_redirect").show();
                    $("#customize-control-advertisement_url").show();
                    $("#customize-control-banner_ads_img").show();
                    $("#customize-control-banner_ads_enable").show();
                    $("#customize-control-subscribe_redirect").show();
                    $("#customize-control-subscribe_url").show();
                    $("#customize-control-subscribe_url").show();
                    $("#customize-control-subscribe_label").show();
                    $("#customize-control-subscribe_btn_enable").show();
                    $("#customize-control-header_menu_bg_clr").show();
                }
        }
        // run only except header 9 & 11
        else
        {
            $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").hide(); //Background Image Overlay Color
            $("#customize-control-header_seven_img_overlay .customize-inside-control-row").hide();
            $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").hide();
            $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").hide();
            $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").hide();
            $("#customize-control-header_seven_img_reapeat select").hide();
            $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").hide();
            $("#customize-control-headerpreset_setting_enable").hide();
            $("#customize-control-advertisement_redirect").hide();
            $("#customize-control-advertisement_url").hide();
            $("#customize-control-banner_ads_img").hide();
            $("#customize-control-banner_ads_enable").hide();
            $("#customize-control-subscribe_redirect").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_label").hide();
            $("#customize-control-subscribe_btn_enable").hide();
            $("#customize-control-header_menu_bg_clr").hide();
            
        }


        //CONDITION 2: WHEN CLICK EVENT TRIGGERED CHECK THEME HEADER SETTINGS
         $('#customize-control-header_layout img').on('click', function() {
          //run when header 1, header 2, header 3 selected  
          if($(this).attr('id') == "customizer_img_1" || $(this).attr('id') == "customizer_img_2" || $(this).attr('id') == "customizer_img_3")
          {
            $("#customize-control-hide_show_header_border").show(); // Show Enable/Disable Border Setting
            $("#customize-control-hide_show_header_border_radius").show(); //Show Enable/Disable Border Radius Setting

          }
          else{
            $("#customize-control-hide_show_header_border").hide(); //Hide Enable/Disable Border Setting
            $("#customize-control-hide_show_header_border_radius").hide(); //Hide Enable/Disable Border Radius Setting
          }

          // run only if header 9 selected
          if(($(this).attr('id') == "customizer_img_9") || ($(this).attr('id') == "customizer_img_11"))
          {
            $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").show();
            $("#customize-control-header_seven_img_overlay .customize-inside-control-row").show();
            $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").show();
            $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").show();
            $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").show();
            $("#customize-control-header_seven_img_reapeat select").show();
            $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").show();
            $("#customize-control-headerpreset_setting_enable").show();
            $("#customize-control-advertisement_redirect").hide();
            $("#customize-control-advertisement_url").hide();
            $("#customize-control-banner_ads_img").hide();
            $("#customize-control-banner_ads_enable").hide();
            $("#customize-control-subscribe_redirect").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_label").hide();
            $("#customize-control-subscribe_btn_enable").hide();
            $("#customize-control-header_menu_bg_clr").hide();

            if($(this).attr('id') == "customizer_img_11")
            {
                $("#customize-control-advertisement_redirect").show();
                $("#customize-control-advertisement_url").show();
                $("#customize-control-banner_ads_img").show();
                $("#customize-control-banner_ads_enable").show();
                $("#customize-control-subscribe_redirect").show();
                $("#customize-control-subscribe_url").show();
                $("#customize-control-subscribe_url").show();
                $("#customize-control-subscribe_label").show();
                $("#customize-control-subscribe_btn_enable").show();
                $("#customize-control-header_menu_bg_clr").show();
            }
          }
          // run only except header 9 & 11
          else
          {
           $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").hide();
            $("#customize-control-header_seven_img_overlay .customize-inside-control-row").hide();
            $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").hide();
            $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").hide();
            $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").hide();
            $("#customize-control-header_seven_img_reapeat select").hide();
            $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").hide();
            $("#customize-control-headerpreset_setting_enable").hide();
            $("#customize-control-advertisement_redirect").hide();
            $("#customize-control-advertisement_url").hide();
            $("#customize-control-banner_ads_img").hide();
            $("#customize-control-banner_ads_enable").hide();
            $("#customize-control-subscribe_redirect").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_url").hide();
            $("#customize-control-subscribe_label").hide();
            $("#customize-control-subscribe_btn_enable").hide();
            $("#customize-control-header_menu_bg_clr").hide();
          }
        });

        //CONDITION 2: WHEN TRIGGERED CUSTOMIZER TABS CHECK THEME HEADER SETTINGS
        $( '#input_theme_header_tab .newscrunch-customizer-tab > #theme_header_tab-general' ).on(
            'click', function () {       
            if($(this).val()=="general")
            {
                var theme_header_trigger = $('input[name="header_layout"]:checked').attr('id');
                //run when header 1, header 2, header 3 selected 
                if(theme_header_trigger == "input_1" || theme_header_trigger == "input_2" || theme_header_trigger == "input_3")
                {
                    $("#customize-control-hide_show_header_border").show();       //Show Enable/Disable Border Setting
                    $("#customize-control-hide_show_header_border_radius").show();//Show Enable/Disable Border Radius Setting
                }
                else
                {
                    $("#customize-control-hide_show_header_border").hide();       //Hide Enable/Disable Border
                    $("#customize-control-hide_show_header_border_radius").hide();//Hide Enable/Disable Border Radius Setting
                } 

                
                // run only if header 9 & header 11 selected
                if((theme_header_trigger == 'input_9') || (theme_header_trigger == 'input_11'))
                {
                    $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").show();
                    $("#customize-control-header_seven_img_overlay .customize-inside-control-row").show();
                    $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").show();
                    $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").show();
                    $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").show();
                    $("#customize-control-header_seven_img_reapeat select").show();
                    $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").show();
                    $("#customize-control-headerpreset_setting_enable").show();
                    if(theme_header_trigger == 'input_11')
                    {
                        $("#customize-control-advertisement_redirect").show();
                        $("#customize-control-advertisement_url").show();
                        $("#customize-control-banner_ads_img").show();
                        $("#customize-control-banner_ads_enable").show();
                        $("#customize-control-subscribe_redirect").show();
                        $("#customize-control-subscribe_url").show();
                        $("#customize-control-subscribe_url").show();
                        $("#customize-control-subscribe_label").show();
                        $("#customize-control-subscribe_btn_enable").show();
                        $("#customize-control-header_menu_bg_clr").show();
                    }
                    
                } 
                // run only except header 9 & 11
                else
                {
                    $("#customize-control-header_seven_overlay_color .customize-control-title, #customize-control-header_seven_overlay_color button").hide();
                    $("#customize-control-header_seven_img_overlay .customize-inside-control-row").hide();
                    $("#customize-control-header_seven_img_size label, #customize-control-header_seven_img_size select ").hide();
                    $("#customize-control-header_seven_img_position label, #customize-control-header_seven_img_position select").hide();
                    $("#customize-control-header_seven_img_reapeat label,customize-control-header_seven_img_reapeat select").hide();
                    $("#customize-control-header_seven_img_reapeat select").hide();
                    $("#customize-control-header_seven_background_img span,#customize-control-header_seven_background_img div").hide();
                    $("#customize-control-headerpreset_setting_enable").hide();
                    $("#customize-control-advertisement_redirect").hide();
                    $("#customize-control-advertisement_url").hide();
                    $("#customize-control-banner_ads_img").hide();
                    $("#customize-control-banner_ads_enable").hide();
                    $("#customize-control-subscribe_redirect").hide();
                    $("#customize-control-subscribe_url").hide();
                    $("#customize-control-subscribe_url").hide();
                    $("#customize-control-subscribe_label").hide();
                    $("#customize-control-subscribe_btn_enable").hide();
                    $("#customize-control-header_menu_bg_clr").hide();
                    
                }              
            }
        });

         /* =============================================================
        **  END -> HIDE SHOW HEADER PRESET SETTINGS
        ================================================================ */


        /* =============================================================
            **    Enable Disble Background Setting For Theme Header
            ================================================================ */
        if($("#toggle_headerpreset_setting_enable").val() == 'true')
        {   
            $("#customize-control-header_seven_background_img").show();
            $("#customize-control-header_seven_img_reapeat").show();
            $("#customize-control-header_seven_img_position").show();
            $("#customize-control-header_seven_img_size").show();
            $("#customize-control-header_seven_img_overlay").show();
            $("#customize-control-header_seven_overlay_color").show();
        }
        else
        {
            $("#customize-control-header_seven_background_img").hide();
            $("body #customize-control-header_seven_img_reapeat").hide();
            $("body #customize-control-header_seven_img_position").hide();
            $("body #customize-control-header_seven_img_size").hide();
            $("body #customize-control-header_seven_img_overlay").hide();
            $("body #customize-control-header_seven_overlay_color").hide();
        }



        if($("#toggle_hide_show_banner").val()== 'true')
        {
            /* =============================================================
            **    Left Banner
            ================================================================ */
            // Onload
            if($("#_customize-input-spncp_banner_left_overlay_options").val()=='custom')
            {
                $("#customize-control-spncp_banner_left1_gradient").hide();
                $("#customize-control-spncp_banner_left2_gradient").hide();
                $("#customize-control-spncp_banner_left_overlay").show();
                $("#customize-control-spncp_banner_left_color").show();
                if($("#toggle_spncp_banner_left_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_banner_left_color").hide();
                }
            }
            else if($("#_customize-input-spncp_banner_left_overlay_options").val()=='gradient')
            {
                $("#customize-control-spncp_banner_left_overlay").hide();
                $("#customize-control-spncp_banner_left_color").hide();
                $("#customize-control-spncp_banner_left1_gradient").show();
                $("#customize-control-spncp_banner_left2_gradient").show();   
            }
            // Onchange
            $("#_customize-input-spncp_banner_left_overlay_options").change(function(){
            if($(this).val()=='gradient')
            {   
                $("#customize-control-spncp_banner_left_overlay").hide();
                $("#customize-control-spncp_banner_left_color").hide();
                $("#customize-control-spncp_banner_left1_gradient").show();
                $("#customize-control-spncp_banner_left2_gradient").show();
            }
            else if($(this).val()=='custom')
            { 
                $("#customize-control-spncp_banner_left1_gradient").hide();
                $("#customize-control-spncp_banner_left2_gradient").hide();
                $("#customize-control-spncp_banner_left_overlay").show();
                $("#customize-control-spncp_banner_left_color").show();
                if($("#toggle_spncp_banner_left_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_banner_left_color").hide();
                }
            } 
        });


        /* =============================================================
        **    Center Banner Gradient Color
        ================================================================ */
        // onload
        if($("#_customize-input-spncp_banner_center_overlay_options").val()=='custom')
        {
            $("#customize-control-spncp_banner_center1_gradient").hide();
            $("#customize-control-spncp_banner_center2_gradient").hide();
            $("#customize-control-spncp_slider_overlay").show();
            $("#customize-control-spncp_cslider_color").show();
            if($("#toggle_spncp_slider_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_cslider_color").hide();
                }
        }
        else if($("#_customize-input-spncp_banner_center_overlay_options").val()=='gradient')
        {
            $("#customize-control-spncp_slider_overlay").hide();
            $("#customize-control-spncp_cslider_color").hide();
            $("#customize-control-spncp_banner_center1_gradient").show();
            $("#customize-control-spncp_banner_center2_gradient").show();
        }
        // onchange
        $("#_customize-input-spncp_banner_center_overlay_options").change(function(){
            if($(this).val()=='gradient')
            {
                $("#customize-control-spncp_slider_overlay").hide();
                $("#customize-control-spncp_cslider_color").hide();
                $("#customize-control-spncp_banner_center1_gradient").show();
                $("#customize-control-spncp_banner_center2_gradient").show();
            }
            else if($(this).val()=='custom')
            {
                $("#customize-control-spncp_banner_center1_gradient").hide();
                $("#customize-control-spncp_banner_center2_gradient").hide();
                $("#customize-control-spncp_slider_overlay").show();
                $("#customize-control-spncp_cslider_color").show();
                if($("#toggle_spncp_slider_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_cslider_color").hide();
                }
            } 
        });  

        /* =============================================================
        **    Right Banner
        ================================================================ */
        // onload
        if($("#_customize-input-spncp_banner_right_overlay_options").val()=='custom')
        {
            $("#customize-control-spncp_banner_right1_gradient").hide();
            $("#customize-control-spncp_banner_right2_gradient").hide();
            $("#customize-control-spncp_banner_right_overlay").show();
            $("#customize-control-spncp_banner_right_color").show();
            if($("#toggle_spncp_banner_right_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_banner_right_color").hide();
                }
        }
        else if($("#_customize-input-spncp_banner_right_overlay_options").val()=='gradient')
        {
            $("#customize-control-spncp_banner_right_overlay").hide();
            $("#customize-control-spncp_banner_right_color").hide();
            $("#customize-control-spncp_banner_right1_gradient").show();
            $("#customize-control-spncp_banner_right2_gradient").show();
        }
        // onchange
        $("#_customize-input-spncp_banner_right_overlay_options").change(function(){
            if($(this).val()=='gradient')
            {  
                $("#customize-control-spncp_banner_right_overlay").hide();
                $("#customize-control-spncp_banner_right_color").hide();
                $("#customize-control-spncp_banner_right1_gradient").show();
                $("#customize-control-spncp_banner_right2_gradient").show();
            }
            else if($(this).val()=='custom')
            {   
                $("#customize-control-spncp_banner_right1_gradient").hide();
                $("#customize-control-spncp_banner_right2_gradient").hide();
                $("#customize-control-spncp_banner_right_overlay").show();
                $("#customize-control-spncp_banner_right_color").show();
                if($("#toggle_spncp_banner_right_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_banner_right_color").hide();
                }
            } 
        });
        }

        /* =============================================================
        **    Banner Toggle ON/OFF
        ================================================================ */
        $("#toggle_hide_show_banner").click(function(){
           if($(this).is(':checked'))
           {
                /* =============================================================
                *    Left Banner
                ================================================================ */
                if( $("#_customize-input-spncp_banner_left_overlay_options").val() == 'custom')
                {
                    $("#customize-control-spncp_banner_left1_gradient").delay(500000).hide();
                    $("#customize-control-spncp_banner_left2_gradient").delay(500000).hide();
                    $("#customize-control-spncp_banner_left_overlay").show();
                    $("#customize-control-spncp_banner_left_color").show();
                    if($("#toggle_spncp_banner_left_overlay").val() == 'false')
                    {
                        $("#customize-control-spncp_banner_left_color").delay(500000).hide();
                    }
                }
                else if($("#_customize-input-spncp_banner_left_overlay_options").val() == 'gradient')
                {
                    $("#customize-control-spncp_banner_left_overlay").delay(500000).hide();
                    $("#customize-control-spncp_banner_left_color").delay(500000).hide();
                    $("#customize-control-spncp_banner_left1_gradient").show();
                    $("#customize-control-spncp_banner_left2_gradient").show();
                }

                /* =============================================================
                *    Center Banner
                ================================================================ */
                if($("#_customize-input-spncp_banner_center_overlay_options").val()=='custom')
                {
                    $("#customize-control-spncp_banner_center1_gradient").delay(500000).hide();
                    $("#customize-control-spncp_banner_center2_gradient").delay(500000).hide();
                    $("#customize-control-spncp_slider_overlay").show();
                    $("#customize-control-spncp_cslider_color").show();
                    if($("#toggle_spncp_slider_overlay").val() == 'false')
                        {
                            $("#customize-control-spncp_cslider_color").delay(500000).hide();
                        }
                }
                else if($("#_customize-input-spncp_banner_center_overlay_options").val()=='gradient')
                {
                    $("#customize-control-spncp_slider_overlay").delay(500000).hide();
                    $("#customize-control-spncp_cslider_color").delay(500000).hide();
                    $("#customize-control-spncp_banner_center1_gradient").show();
                    $("#customize-control-spncp_banner_center2_gradient").show();
                }

                /* =============================================================
                *    Right Banner
                ================================================================ */
                if($("#_customize-input-spncp_banner_right_overlay_options").val()=='custom')
                {
                    $("#customize-control-spncp_banner_right1_gradient").delay(500000).hide();
                    $("#customize-control-spncp_banner_right2_gradient").delay(500000).hide();
                    $("#customize-control-spncp_banner_right_overlay").show();
                    $("#customize-control-spncp_banner_right_color").show();
                    if($("#toggle_spncp_banner_right_overlay").val() == 'false')
                        {
                            $("#customize-control-spncp_banner_right_color").delay(500000).hide();
                        }
                }
                else if($("#_customize-input-spncp_banner_right_overlay_options").val()=='gradient')
                {
                    $("#customize-control-spncp_banner_right_overlay").delay(500000).hide();
                    $("#customize-control-spncp_banner_right_color").delay(500000).hide();
                    $("#customize-control-spncp_banner_right1_gradient").show();
                    $("#customize-control-spncp_banner_right2_gradient").show();
                }
                

                $("#_customize-input-spncp_banner_left_overlay_options").change(function(){
                if($(this).val()=='gradient')
                {   
                    $("#customize-control-spncp_banner_left_overlay").hide();
                    $("#customize-control-spncp_banner_left_color").hide();
                    $("#customize-control-spncp_banner_left1_gradient").show();
                    $("#customize-control-spncp_banner_left2_gradient").show();
                }
                else if($(this).val()=='custom')
                { 
                    $("#customize-control-spncp_banner_left1_gradient").hide();
                    $("#customize-control-spncp_banner_left2_gradient").hide();
                    $("#customize-control-spncp_banner_left_overlay").show();
                    $("#customize-control-spncp_banner_left_color").show();
                    if($("#toggle_spncp_banner_left_overlay").val() == 'false')
                    {
                        $("#customize-control-spncp_banner_left_color").hide();
                    }
                } 
            });

            $("#_customize-input-spncp_banner_center_overlay_options").change(function(){
            if($(this).val()=='gradient')
            {
                $("#customize-control-spncp_slider_overlay").hide();
                $("#customize-control-spncp_cslider_color").hide();
                $("#customize-control-spncp_banner_center1_gradient").show();
                $("#customize-control-spncp_banner_center2_gradient").show();
            }
            else if($(this).val()=='custom')
            {
                $("#customize-control-spncp_banner_center1_gradient").hide();
                $("#customize-control-spncp_banner_center2_gradient").hide();
                $("#customize-control-spncp_slider_overlay").show();
                $("#customize-control-spncp_cslider_color").show();
                if($("#toggle_spncp_slider_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_cslider_color").hide();
                }
            } 
            });  

            $("#_customize-input-spncp_banner_right_overlay_options").change(function(){
            if($(this).val()=='gradient')
            {  
                $("#customize-control-spncp_banner_right_overlay").hide();
                $("#customize-control-spncp_banner_right_color").hide();
                $("#customize-control-spncp_banner_right1_gradient").show();
                $("#customize-control-spncp_banner_right2_gradient").show();
            }
            else if($(this).val()=='custom')
            {   
                $("#customize-control-spncp_banner_right1_gradient").hide();
                $("#customize-control-spncp_banner_right2_gradient").hide();
                $("#customize-control-spncp_banner_right_overlay").show();
                $("#customize-control-spncp_banner_right_color").show();
                if($("#toggle_spncp_banner_right_overlay").val() == 'false')
                {
                    $("#customize-control-spncp_banner_right_color").hide();
                }
            } 
            });
           }

           else{
                $("#customize-control-spncp_banner_left_overlay").hide();
                $("#customize-control-spncp_banner_left_color").hide();
                $("#customize-control-spncp_banner_left1_gradient").hide();
                $("#customize-control-spncp_banner_left2_gradient").hide();
                $("#customize-control-spncp_slider_overlay").hide();
                $("#customize-control-spncp_cslider_color").hide();
                $("#customize-control-spncp_banner_center1_gradient").hide();
                $("#customize-control-spncp_banner_center2_gradient").hide();
                $("#customize-control-spncp_banner_right_overlay").hide();
                $("#customize-control-spncp_banner_right_color").hide();
                $("#customize-control-spncp_banner_right1_gradient").hide();
                $("#customize-control-spncp_banner_right2_gradient").hide();
           }
        });         
       

        /* =============================================================
            *      Autoload Script Left Side Topbar
        ================================================================ */
        if($("#_customize-input-color_options").val()=='custom')
        {
            $("#customize-control-gradient_color_enable").hide();
            $("#customize-control-gradient_left_color").hide();
            $("#customize-control-gradient_right_color").hide();
            $("#customize-control-custom_color_enable").show();
            $("#customize-control-link_color").show();
        }
        else if($("#_customize-input-color_options").val()=='gradient')
        {
            $("#customize-control-custom_color_enable").hide();
            $("#customize-control-link_color").hide();
            $("#customize-control-gradient_color_enable").show();
            $("#customize-control-gradient_left_color").show();
            $("#customize-control-gradient_right_color").show();
        }


        $("#_customize-input-color_options").change(function(){
            if($(this).val()=='gradient')
            {
            $("#customize-control-custom_color_enable").hide();
            $("#customize-control-link_color").hide();
            $("#customize-control-gradient_color_enable").show();
            $("#customize-control-gradient_left_color").show();
            $("#customize-control-gradient_right_color").show();
            }
            else if($(this).val()=='custom')
            {
            $("#customize-control-gradient_color_enable").hide();
            $("#customize-control-gradient_left_color").hide();
            $("#customize-control-gradient_right_color").hide();
            $("#customize-control-custom_color_enable").show();
            $("#customize-control-link_color").show();
            } 
        });
        /* =============================================================
            *   Onclick Script Left Side Topbar
        ================================================================ */
        $( '#input_contatc_info_section_tab .newscrunch-customizer-tab > label' ).on(
            'click', function () {       
            if($("#_customize-input-top_left_variation").val()=='left_1' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs for Date Time
                $("#customize-control-hide_show_date").show(); 
                $("#customize-control-hide_show_time").show();
                $("#customize-control-hide_show_left_social_icons").hide();
                $("#customize-control-topbar_left_social_icons").hide();
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();
            }
            else if($("#_customize-input-top_left_variation").val()=='left_1' && $(this).text()=='StyleStyle')
            {
               //Style Tabs For Date Time
                $("#customize-control-hide_show_date_time_color").show();
                $("#customize-control-date_color").show();
                $("#customize-control-time_color").show();
                $("#customize-control-hide_show_left_social_icon_color").hide();
                $("#customize-control-left_social_icon_color").hide();
                $("#customize-control-left_social_icon_hover_color").hide();
                $("#customize-control-left_social_icon_bg_color").hide();
                $("#customize-control-left_social_icon_bg_hover_color").hide();
                $("#customize-control-hide_show_left_trend").hide();
                $("#customize-control-hide_show_left_trend_title_color").hide();
                $("#customize-control-hide_show_left_trend_post_color").hide(); 
            }

            else if($("#_customize-input-top_left_variation").val()=='left_2' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Social Icons
                $("#customize-control-hide_show_left_social_icons").show();
                $("#customize-control-topbar_left_social_icons").show();
                $("#customize-control-hide_show_date").hide();  
                $("#customize-control-hide_show_time").hide(); 
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();
            }
            else if($("#_customize-input-top_left_variation").val()=='left_2' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Social Icons
                $("#customize-control-hide_show_left_social_icon_color").show();
                $("#customize-control-left_social_icon_color").show();
                $("#customize-control-left_social_icon_hover_color").show();
                $("#customize-control-left_social_icon_bg_color").show();
                $("#customize-control-left_social_icon_bg_hover_color").show();
                $("#customize-control-hide_show_date_time_color").hide(); 
                $("#customize-control-date_color").hide();
                $("#customize-control-time_color").hide();
                $("#customize-control-hide_show_left_trend").hide();
                $("#customize-control-hide_show_left_trend_title_color").hide();
                $("#customize-control-hide_show_left_trend_post_color").hide();
            }


            else if($("#_customize-input-top_left_variation").val()=='left_3' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Trending Post
                $("#customize-control-hide_show_left_trending").show();
                $("#customize-control-spnc_left_trending").show(); 
                $("#customize-control-hide_show_left_social_icons").hide();
                $("#customize-control-topbar_left_social_icons").hide(); 
                $("#customize-control-hide_show_date").hide(); 
                $("#customize-control-hide_show_time").hide();
            }
            else if($("#_customize-input-top_left_variation").val()=='left_3' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Trending Post
                $("#customize-control-hide_show_left_trend").show();
                $("#customize-control-hide_show_left_trend_title_color").show();
                $("#customize-control-hide_show_left_trend_post_color").show();
                $("#customize-control-hide_show_left_social_icon_color").hide();
                $("#customize-control-left_social_icon_color").hide();
                $("#customize-control-left_social_icon_hover_color").hide();
                $("#customize-control-left_social_icon_bg_color").hide();
                $("#customize-control-left_social_icon_bg_hover_color").hide();
                $("#customize-control-hide_show_date_time_color").hide(); 
                $("#customize-control-date_color").hide();
                $("#customize-control-time_color").hide();              
            }

            else if($("#_customize-input-top_left_variation").val()=='left_4' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Date With Social Icons
                $("#customize-control-hide_show_left_social_icons").show();
                $("#customize-control-topbar_left_social_icons").show(); 
                $("#customize-control-hide_show_date").show(); 
                $("#customize-control-hide_show_time").show(); 
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();  
            }
            else if($("#_customize-input-top_left_variation").val()=='left_4' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Date With Social Icons
                $("#customize-control-hide_show_left_social_icon_color").show();
                $("#customize-control-left_social_icon_color").show();
                $("#customize-control-left_social_icon_hover_color").show();
                $("#customize-control-left_social_icon_bg_color").show();
                $("#customize-control-left_social_icon_bg_hover_color").show();
                $("#customize-control-hide_show_date_time_color").show();
                $("#customize-control-date_color").show();
                $("#customize-control-time_color").show();
                $("#customize-control-hide_show_left_trend").hide();
                $("#customize-control-hide_show_left_trend_title_color").hide();
                $("#customize-control-hide_show_left_trend_post_color").hide();
            }
       
        });


        /* =============================================================
            *   Onclick Script Right Side Topbar
        ================================================================ */
        $( '#input_social_icon_section_tab .newscrunch-customizer-tab > label' ).on(
            'click', function () {       
            if($("#_customize-input-top_right_variation").val()=='right_1' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs for Date Time
                $("#customize-control-hide_show_right_date").show();
                $("#customize-control-hide_show_right_time").show();
                $("#customize-control-hide_show_social_icons").hide();
                $("#customize-control-social_icons").hide();
                $("#customize-control-spnc_right_trending").hide();
                $("#customize-control-hide_show_right_trending").hide();
            }
            else if($("#_customize-input-top_right_variation").val()=='right_1' && $(this).text()=='StyleStyle')
            {
               //Style Tabs For Date Time
                $("#customize-control-hide_show_right_date_time_color").show();
                $("#customize-control-right_date_color").show();
                $("#customize-control-right_time_color").show();

                $("#customize-control-hide_show_social_icon_color").hide();
                $("#customize-control-social_icon_color").hide(); 
                $("#customize-control-social_icons").hide(); 
                $("#customize-control-social_icon_hover_color").hide();
                $("#customize-control-social_icon_bg_color").hide();
                $("#customize-control-social_icon_bg_hover_color").hide();

                $("#customize-control-hide_show_right_trend").hide();
                $("#customize-control-hide_show_right_trend_title_color").hide();
                $("#customize-control-hide_show_right_trend_post_color").hide(); 
            }

            else if($("#_customize-input-top_right_variation").val()=='right_2' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Social Icons
                $("#customize-control-hide_show_social_icons").show(); 
                $("#customize-control-social_icons").show();  
                $("#customize-control-hide_show_right_date").hide(); 
                $("#customize-control-hide_show_right_time").hide(); 
                $("#customize-control-spnc_right_trending").hide();
                $("#customize-control-hide_show_right_trending").hide();
            }
            else if($("#_customize-input-top_right_variation").val()=='right_2' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Social Icons
                $("#customize-control-hide_show_social_icon_color").show();
                $("#customize-control-social_icon_color").show(); 
                
                $("#customize-control-social_icon_hover_color").show();
                $("#customize-control-social_icon_bg_color").show();
                $("#customize-control-social_icon_bg_hover_color").show();

                 $("#customize-control-hide_show_right_date_time_color").hide();
                $("#customize-control-right_date_color").hide() ;
                $("#customize-control-right_time_color").hide();

                $("#customize-control-hide_show_right_trend").hide();
                $("#customize-control-hide_show_right_trend_title_color").hide();
                $("#customize-control-hide_show_right_trend_post_color").hide(); 
            }


            else if($("#_customize-input-top_right_variation").val()=='right_3' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Trending Post
                $("#customize-control-hide_show_right_trending").show();
                $("#customize-control-spnc_right_trending").show();
                $("#customize-control-hide_show_social_icons").hide(); 
                $("#customize-control-social_icons").hide();  
                $("#customize-control-hide_show_right_date").hide(); 
                $("#customize-control-hide_show_right_time").hide();
            }
            else if($("#_customize-input-top_right_variation").val()=='right_3' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Trending Post
                $("#customize-control-hide_show_right_trend").show();
                $("#customize-control-hide_show_right_trend_title_color").show();
                $("#customize-control-hide_show_right_trend_post_color").show();
                
                $("#customize-control-hide_show_social_icon_color").hide();
                $("#customize-control-social_icon_color").hide();
                $("#customize-control-social_icons").hide(); 
                $("#customize-control-social_icon_hover_color").hide();
                $("#customize-control-social_icon_bg_color").hide();
                $("#customize-control-social_icon_bg_hover_color").hide();

                $("#customize-control-hide_show_right_date_time_color").hide();
                $("#customize-control-right_date_color").hide();
                $("#customize-control-right_time_color").hide();              
            }

            else if($("#_customize-input-top_right_variation").val()=='right_4' && $(this).text()=='GeneralGeneral')
            {
                //General Tabs For Date With Social Icons
                $("#customize-control-hide_show_social_icons").show(); 
                $("#customize-control-social_icons").show();  
                $("#customize-control-hide_show_right_date").show(); 
                $("#customize-control-hide_show_right_time").show(); 
                $("#customize-control-spnc_right_trending").hide();
                $("#customize-control-hide_show_right_trending").hide();
            }
            else if($("#_customize-input-top_right_variation").val()=='right_4' && $(this).text()=='StyleStyle')
            {
                //Style Tabs For Date With Social Icons
                $("#customize-control-hide_show_right_trend").hide();
                $("#customize-control-hide_show_right_trend_title_color").hide();
                $("#customize-control-hide_show_right_trend_post_color").hide();
                $("#customize-control-hide_show_social_icon_color").show();
                $("#customize-control-social_icon_color").show();
                $("#customize-control-social_icon_hover_color").show();
                $("#customize-control-social_icon_bg_color").show();
                $("#customize-control-social_icon_bg_hover_color").show();
                $("#customize-control-right_date_color").show();
                $("#customize-control-right_time_color").show();
            }
       
        });

         /* =============================================================
            *      Autoload Script Left Side Topbar
        ================================================================ */
        if($("#_customize-input-top_left_variation").val()=='left_1')
        {
            $("#customize-control-hide_show_date").show(); 
            $("#customize-control-hide_show_time").show();
            $("#customize-control-hide_show_left_social_icons").hide();
            $("#customize-control-topbar_left_social_icons").hide();
            $("#customize-control-hide_show_left_trending").hide();
            $("#customize-control-spnc_left_trending").hide();
        }
        else if($("#_customize-input-top_left_variation").val()=='left_2')
        {
            $("#customize-control-hide_show_left_social_icons").show();
            $("#customize-control-topbar_left_social_icons").show();
            $("#customize-control-hide_show_date").hide();
            $("#customize-control-hide_show_time").hide();
            $("#customize-control-hide_show_left_trending").hide();
            $("#customize-control-spnc_left_trending").hide();
        }
        else if($("#_customize-input-top_left_variation").val()=='left_3')
        {
            $("#customize-control-hide_show_left_trending").show();
            $("#customize-control-spnc_left_trending").show(); 
            $("#customize-control-hide_show_left_social_icons").hide();
            $("#customize-control-topbar_left_social_icons").hide();
            $("#customize-control-hide_show_date").hide();
            $("#customize-control-hide_show_time").hide();
        }
        else if($("#_customize-input-top_left_variation").val()=='left_4')
        {
            $("#customize-control-hide_show_left_social_icons").show();
            $("#customize-control-topbar_left_social_icons").show();
            $("#customize-control-hide_show_date").show();
            $("#customize-control-hide_show_time").show();
             $("#customize-control-hide_show_left_trending").hide();
            $("#customize-control-spnc_left_trending").hide();
        }


         /* =============================================================
            *      Autoload Script Right Side Topbar
        ================================================================ */
        if($("#_customize-input-top_right_variation").val()=='right_1')
        {
            //Date Time   
            $("#customize-control-hide_show_right_date").show();
            $("#customize-control-hide_show_right_time").show();
            $("#customize-control-hide_show_social_icons").hide();
            $("#customize-control-social_icons").hide(); 
            $("#customize-control-spnc_right_trending").hide();
            $("#customize-control-hide_show_right_trending").hide();
        }
        else if($("#_customize-input-top_right_variation").val()=='right_2')
        {
            //Social Icons
            $("#customize-control-hide_show_social_icons").show(); 
            $("#customize-control-social_icons").show();  
            $("#customize-control-hide_show_right_date").hide(); 
            $("#customize-control-hide_show_right_time").hide(); 
            $("#customize-control-spnc_right_trending").hide(); 
            $("#customize-control-hide_show_right_trending").hide();           
        }
        else if($("#_customize-input-top_right_variation").val()=='right_3')
        {
            //Trending Post
            $("#customize-control-hide_show_right_trending").show();
            $("#customize-control-spnc_right_trending").show(); 
            $("#customize-control-hide_show_social_icons").hide(); 
            $("#customize-control-social_icons").hide();  
            $("#customize-control-hide_show_right_date").hide(); 
            $("#customize-control-hide_show_right_time").hide();
        }
        else if($("#_customize-input-top_right_variation").val()=='right_4')
        {
            //Date With Social Icons
            $("#customize-control-hide_show_social_icons").show(); 
            $("#customize-control-social_icons").show();  
            $("#customize-control-hide_show_right_date").show(); 
            $("#customize-control-hide_show_right_time").show(); 
            $("#customize-control-spnc_right_trending").hide();
            $("#customize-control-hide_show_right_trending").hide();
        }


        /* =============================================================
            *      Customizer Script Left Side Topbar
        ================================================================ */
        wp.customize('top_left_variation', function(control) {
        control.bind(function( spncp_top_left ) 
        {
            if(spncp_top_left=='left_1')
            {   
                $("#customize-control-hide_show_date").show();
                $("#customize-control-hide_show_time").show();
                $("#customize-control-hide_show_left_social_icons").hide();
                $("#customize-control-topbar_left_social_icons").hide();
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();
            }
            else if(spncp_top_left=='left_2')
            {
                $("#customize-control-hide_show_left_social_icons").show();
                $("#customize-control-topbar_left_social_icons").show();
                $("#customize-control-hide_show_date").hide();
                $("#customize-control-hide_show_time").hide();
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();
            }
            else if(spncp_top_left=='left_3')
            {
                $("#customize-control-hide_show_left_trending").show();
                $("#customize-control-spnc_left_trending").show();
                 $("#customize-control-hide_show_left_social_icons").hide();
                $("#customize-control-topbar_left_social_icons").hide();
                $("#customize-control-hide_show_date").hide();
                $("#customize-control-hide_show_time").hide();
             }
            else if(spncp_top_left=='left_4')
            {
                //General Tabs
                $("#customize-control-hide_show_left_social_icons").show();
                $("#customize-control-topbar_left_social_icons").show();
                $("#customize-control-hide_show_date").show();
                $("#customize-control-hide_show_time").show();
                $("#customize-control-hide_show_left_trending").hide();
                $("#customize-control-spnc_left_trending").hide();
            }
        });
        });



        /* =============================================================
            *      Customizer Script Right Side Topbar
        ================================================================ */
    
        wp.customize('top_right_variation', function(control) {
        control.bind(function( spncp_right_side ) 
        {    
            if(spncp_right_side=='right_1')
            {
                //Date Time   
                $("#customize-control-hide_show_right_date").show();  
                $("#customize-control-hide_show_right_time").show(); 
                $("#customize-control-hide_show_social_icons").hide();
                $("#customize-control-social_icons").hide();
                $("#customize-control-spnc_right_trending").hide()
                $("#customize-control-hide_show_right_trending").hide();
            }
            
            else if(spncp_right_side=='right_2')
            {
                //Social Icons
                $("#customize-control-hide_show_social_icons").show(); 
                $("#customize-control-social_icons").show();  
                $("#customize-control-hide_show_right_date").hide(); 
                $("#customize-control-hide_show_right_time").hide(); 
                $("#customize-control-spnc_right_trending").hide();
                $("#customize-control-hide_show_right_trending").hide();
            }
            
            else if(spncp_right_side=='right_3')
            {
                //Trending Post
                $("#customize-control-hide_show_right_trending").show();
                $("#customize-control-spnc_right_trending").show();
                $("#customize-control-hide_show_social_icons").hide(); 
                $("#customize-control-social_icons").hide();  
                $("#customize-control-hide_show_right_date").hide(); 
                $("#customize-control-hide_show_right_time").hide(); 
            }
            else if(spncp_right_side=='right_4')
            {
                //Date With Social Icons
                $("#customize-control-hide_show_social_icons").show(); 
                $("#customize-control-social_icons").show();  
                $("#customize-control-hide_show_right_date").show(); 
                $("#customize-control-hide_show_right_time").show(); 
                $("#customize-control-spnc_right_trending").hide();
                $("#customize-control-hide_show_right_trending").hide();
            }
        });
        });


        //Auto-Load Advance scroll to top
        if($("#customize-control-scroll_to_top_position #_customize-input-scroll_to_top_position-radio-right").is(':checked')) 
        {
            $('#customize-control-scroll_to_top_margin_left').hide();
            $("#customize-control-scroll_to_top_margin_right").show();
        }
        else
        {
            $('#customize-control-scroll_to_top_margin_left').show();
            $("#customize-control-scroll_to_top_margin_right").hide();
        }

        //Click Event Advance scroll to top
        $("#customize-control-scroll_to_top_position input").click(function(){
            if($(this).attr('id')==='_customize-input-scroll_to_top_position-radio-right')
            {
                $('#customize-control-scroll_to_top_margin_left').hide();
                $("#customize-control-scroll_to_top_margin_right").show();
            }
            else
            {
                $('#customize-control-scroll_to_top_margin_left').show();
                $("#customize-control-scroll_to_top_margin_right").hide();
            }
      });



        /* =============================================================
            *      Customizer Script For Video Layout
        ================================================================ */
        //Autoload 
        if($("#customize-control-video_layout #input_3").is(':checked')) 
        {   
            $("#customize-control-featured_video_url_5").hide();
        }
        
        //Click Event
        $("#customize-control-video_layout img").click(function(){
            if($(this).attr('id')==='customizer_img_3')
            {
                $("#customize-control-featured_video_url_5").hide();
            }
            else
            {
                $("#customize-control-featured_video_url_5").show();
            }
      });



        /* =============================================================
            *      Breadcrumb setting
        ================================================================ */

        var breadcrumb_style=$('#_customize-input-bredcrumb_style option:selected').val();
        if(breadcrumb_style==2)
        {
            $("#customize-control-bredcrumb_page_title").hide();
            $("#customize-control-enable_page_title").hide();
            $("#customize-control-bredcrumb_position").hide();
            $("#customize-control-bredcrumb_markup").hide();
            $("#customize-control-enable_breadcrumb").hide();
            $("#customize-control-breadcrumb_section_padding").hide();
            $("#customize-control-breadcrumb_top_padding").hide();
            $("#customize-control-breadcrumb_right_padding").hide();
            $("#customize-control-breadcrumb_bottom_padding").hide();
            $("#customize-control-breadcrumb_left_padding").hide();

                $("#customize-control-bredcrumb_alignment #customizer_img_1").hide();
                $("#customize-control-bredcrumb_alignment #customizer_img_2").hide();

                var imageUrl_centered = $('#customize-control-bredcrumb_alignment #customizer_img_3').attr('src');
                var imageUrl_centered = imageUrl_centered.replace('breadcrumb-center.png', 'style-2/breadcrumb-center.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_3").attr("src", imageUrl_centered);

                var imageUrl_left = $('#customize-control-bredcrumb_alignment #customizer_img_4').attr('src');
                var imageUrl_left = imageUrl_left.replace('both-on-left.png', 'style-2/breadcrumb-left.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_4").attr("src", imageUrl_left);

                var imageUrl_right = $('#customize-control-bredcrumb_alignment #customizer_img_5').attr('src');
                var imageUrl_right = imageUrl_right.replace('both-on-right.png', 'style-2/breadcrumb-right.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_5").attr("src", imageUrl_right);   
        }

        $("#_customize-input-bredcrumb_style").change(function(){
            if($(this).val() == 1)
            {
                console.log($("#toggle_breadcrumb_section_padding").val());
                $("#customize-control-bredcrumb_page_title").show();
                $("#customize-control-enable_page_title").show();
                $("#customize-control-bredcrumb_position").show();
                $("#customize-control-bredcrumb_markup").show();
                $("#customize-control-enable_breadcrumb").show();
                $("#customize-control-breadcrumb_section_padding").show();
                if($("#toggle_breadcrumb_section_padding").val()== true)
                {
                    $("#customize-control-breadcrumb_top_padding").show();
                    $("#customize-control-breadcrumb_right_padding").show();
                    $("#customize-control-breadcrumb_bottom_padding").show();
                    $("#customize-control-breadcrumb_left_padding").show();
                }
                $("#customize-control-bredcrumb_alignment #customizer_img_1").show();
                $("#customize-control-bredcrumb_alignment #customizer_img_2").show();

                var imageUrl_centered = $('#customize-control-bredcrumb_alignment #customizer_img_3').attr('src');
                var imageUrl_centered = imageUrl_centered.replace('style-2/breadcrumb-center.png', 'breadcrumb-center.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_3").attr("src", imageUrl_centered);

                var imageUrl_left = $('#customize-control-bredcrumb_alignment #customizer_img_4').attr('src');
                var imageUrl_left = imageUrl_left.replace('style-2/breadcrumb-left.png', 'both-on-left.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_4").attr("src", imageUrl_left);

                var imageUrl_right = $('#customize-control-bredcrumb_alignment #customizer_img_5').attr('src');
                var imageUrl_right = imageUrl_right.replace('style-2/breadcrumb-right.png', 'both-on-right.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_5").attr("src", imageUrl_right);
            }
            else
            {
                
                $("#customize-control-bredcrumb_page_title").hide();
                $("#customize-control-enable_page_title").hide();
                $("#customize-control-bredcrumb_position").hide();
                $("#customize-control-bredcrumb_markup").hide();
                $("#customize-control-enable_breadcrumb").hide();
                $("#customize-control-breadcrumb_section_padding").hide();  
                $("#customize-control-breadcrumb_top_padding").hide();
                $("#customize-control-breadcrumb_right_padding").hide();
                $("#customize-control-breadcrumb_bottom_padding").hide();
                $("#customize-control-breadcrumb_left_padding").hide(); 

                $("#customize-control-bredcrumb_alignment #customizer_img_1").hide();
                $("#customize-control-bredcrumb_alignment #customizer_img_2").hide();

                var imageUrl_centered = $('#customize-control-bredcrumb_alignment #customizer_img_3').attr('src');
                var imageUrl_centered = imageUrl_centered.replace('breadcrumb-center.png', 'style-2/breadcrumb-center.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_3").attr("src", imageUrl_centered);

                var imageUrl_left = $('#customize-control-bredcrumb_alignment #customizer_img_4').attr('src');
                var imageUrl_left = imageUrl_left.replace('both-on-left.png', 'style-2/breadcrumb-left.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_4").attr("src", imageUrl_left);

                var imageUrl_right = $('#customize-control-bredcrumb_alignment #customizer_img_5').attr('src');
                var imageUrl_right = imageUrl_right.replace('both-on-right.png', 'style-2/breadcrumb-right.png');
                $("#customize-control-bredcrumb_alignment #customizer_img_5").attr("src", imageUrl_right);             
            }
        });
        
        if($("#_customize-input-ad_type").val()=="banner")
        {
            $("#customize-control-ad_popup_title").hide();
            $("#customize-control-ad_popup_des").hide();
            $("#customize-control-ad_popup_img").hide();
            $("#customize-control-ad_popup_btitle").hide();
            $("#customize-control-ad_popup_url").hide();
            $("#customize-control-ad_popup_height").hide();
            $("#customize-control-ad_popup_width").hide();
            $("#customize-control-ad_popup_new_tab").hide();
            $("#customize-control-ad_popup_bgcolor").hide();
            $("#customize-control-ad_popup_title_color").hide();
            $("#customize-control-ad_popup_content_color").hide();
            $("#customize-control-advertisement_items").show();
        }
        else if($("#_customize-input-ad_type").val()=="popup")
        {
            $("#customize-control-advertisement_items").hide();
            $("#customize-control-ad_popup_title").show();
            $("#customize-control-ad_popup_des").show();
            $("#customize-control-ad_popup_img").show();
            $("#customize-control-ad_popup_btitle").show();
            $("#customize-control-ad_popup_url").show();
            $("#customize-control-ad_popup_height").show();
            $("#customize-control-ad_popup_width").show();
            $("#customize-control-ad_popup_new_tab").show();
            $("#customize-control-ad_popup_bgcolor").show();
            $("#customize-control-ad_popup_title_color").show();
            $("#customize-control-ad_popup_content_color").show();
        }

        $("#_customize-input-ad_type").change(function(){
            if($(this).val()=="popup")
            {
                $("#customize-control-advertisement_items").hide();
                $("#customize-control-ad_popup_title").show();
                $("#customize-control-ad_popup_des").show();
                $("#customize-control-ad_popup_img").show();
                $("#customize-control-ad_popup_btitle").show();
                $("#customize-control-ad_popup_url").show();
                $("#customize-control-ad_popup_height").show();
                $("#customize-control-ad_popup_width").show();
                $("#customize-control-ad_popup_new_tab").show();
                $("#customize-control-ad_popup_bgcolor").show();
                $("#customize-control-ad_popup_title_color").show();
                $("#customize-control-ad_popup_content_color").show();
            }
            else if($(this).val()=="banner")
            {
                $("#customize-control-ad_popup_title").hide();
                $("#customize-control-ad_popup_des").hide();
                $("#customize-control-ad_popup_img").hide();
                $("#customize-control-ad_popup_btitle").hide();
                $("#customize-control-ad_popup_url").hide();
                $("#customize-control-ad_popup_height").hide();
                $("#customize-control-ad_popup_width").hide();
                $("#customize-control-ad_popup_new_tab").hide();
                $("#customize-control-ad_popup_bgcolor").hide();
                $("#customize-control-ad_popup_title_color").hide();
                $("#customize-control-ad_popup_content_color").hide();
                $("#customize-control-advertisement_items").show();
            }
        });

    });
})(jQuery)