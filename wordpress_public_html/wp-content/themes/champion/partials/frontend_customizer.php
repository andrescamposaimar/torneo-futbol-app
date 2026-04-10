<div id="frontend_customizer">
    <div class="customizer_wrapper">
        <div class="customizer_title clearfix">
            <h3><?php esc_html_e('Styling', 'champion'); ?></h3>
            <a href="#" id="reset_customizer"><?php esc_html_e('Reset all', 'champion'); ?></a>
        </div>
        <div class="accordion_section_title active"><a
                    href="#customizer_layout"><?php esc_html_e('Overall Layout Styling', 'champion'); ?></a></div>
        <div class="accordion_section" style="display: block;" id="customizer_layout">
            <a href="#" id="layout-wide"
               class="customizer_option_button cust-col-left-2"><?php esc_html_e('Wide', 'champion'); ?></a>
            <a href="#" id="layout-boxed"
               class="customizer_option_button cust-col-right-2"><?php esc_html_e('Boxed', 'champion'); ?></a>
            <div id="background_images">
                <p><?php esc_html_e('Background Color:', 'champion'); ?></p>
                <div class="colorpicker_el" id="bg_color">
                    <input type="text" value="<?php echo esc_attr(get_theme_mod('bg_color')); ?>">
                    <a href="#"></a>
                </div>
                <p><?php esc_html_e('Background Image', 'champion'); ?></p>
                <ul class="clearfix">
                    <li><a id="background-01" href="#"></a></li>
                    <li><a id="background-02" href="#"></a></li>
                    <li><a id="background-03" href="#"></a></li>
                    <li><a id="background-04" href="#"></a></li>
                    <li><a id="background-05" href="#"></a></li>
                </ul>
                <p><?php esc_html_e('Background Pattern', 'champion'); ?></p>
                <ul class="clearfix">
                    <li><a id="pattern-01" href="#"></a></li>
                    <li><a id="pattern-02" href="#"></a></li>
                    <li><a id="pattern-03" href="#"></a></li>
                    <li><a id="pattern-04" href="#"></a></li>
                    <li><a id="pattern-05" href="#"></a></li>
                </ul>
            </div>
        </div>
        <div class="accordion_section_title"><a
                    href="#customizer_header"><?php esc_html_e('Header Style', 'champion'); ?></a></div>
        <div class="accordion_section" id="customizer_header">
            <a href="#" class="customizer_option_button" id="header_1"><?php esc_html_e('Header 1', 'champion'); ?></a>
            <a href="#" class="customizer_option_button" id="header_2"><?php esc_html_e('Header 2', 'champion'); ?></a>
            <a href="#" class="customizer_option_button" id="header_3"><?php esc_html_e('Header 3', 'champion'); ?></a>
            <a href="#" class="customizer_option_button" id="header_4"><?php esc_html_e('Header 4', 'champion'); ?></a>
            <a href="#" class="customizer_option_button" id="header_5"><?php esc_html_e('Header 5', 'champion'); ?></a>
        </div>
        <div class="accordion_section_title"><a href="#customizer_color"><?php esc_html_e('Color', 'champion'); ?></a>
        </div>
        <div class="accordion_section" id="customizer_color">
            <p><?php esc_html_e('Base Color:', 'champion'); ?></p>
            <div class="colorpicker_el" id="base_color">
                <input type="text" value="<?php echo esc_attr(get_theme_mod('base_color')); ?>">
                <a href="#"></a>
            </div>
        </div>
        <div class="accordion_section_title"><a
                    href="#customizer_font"><?php esc_html_e('Font Family', 'champion'); ?></a></div>
        <div class="accordion_section" id="customizer_font">
            <p><?php esc_html_e('Font Family 1:', 'champion'); ?></p>
            <select name="font" id="theme-font-1">
                <option selected="selected" value="default">Arial</option>
                <option value="http://fonts.googleapis.com/css?family=Lato:300,400,700,300italic,400italic,700italic">
                    Lato
                </option>
                <option value="http://fonts.googleapis.com/css?family=Open+Sans:400italic,700italic,700,400">Open Sans
                </option>
                <option value="http://fonts.googleapis.com/css?family=Roboto+Slab:300,700,400">Roboto Slab</option>
                <option value="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic,700italic">
                    Source Sans Pro
                </option>
            </select>
            <p><?php esc_html_e('Font Family 2:', 'champion'); ?></p>
            <select name="font" id="theme-font-2">
                <option value="http://fonts.googleapis.com/css?family=Anton">Anton</option>
                <option value="http://fonts.googleapis.com/css?family=Cabin+Condensed:400,500,600,700 ">Cabin
                    Condensed
                </option>
                <option selected="selected" value="default">Oswald</option>
                <option value="http://fonts.googleapis.com/css?family=Fjalla+One">Fjalla One</option>
                <option value="http://fonts.googleapis.com/css?family=Roboto+Condensed:300italic,400italic,700italic,700,300,400">
                    Roboto Condensed
                </option>
            </select>
            <p>630+ Google Fonts are available in Customize</p>
        </div>
    </div>
    <div id="frontend_customizer_button"></div>
</div>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        "use strict";

        $("#frontend_customizer").delay(1500).animate({left: -250}, 300);

        $("#frontend_customizer_button").toggle(function () {
            $("#frontend_customizer").animate({left: 0}, 300);
            $("#frontend_customizer").addClass('open');
        }, function () {
            $("#frontend_customizer").animate({left: -250}, 300);
            $("#frontend_customizer").removeClass('open');
        });

        $(".accordion_section_title a").on('click', function () {
            if (!$(this).parent().hasClass('active')) {
                $(".accordion_section_title.active").removeClass('active');
                $(this).parent().addClass('active');
                $(".accordion_section").slideUp(300);
                var id = $(this).attr('href');
                $(".accordion_section" + id).slideDown(300);
            }

            return false;
        });

        var layoutStyle = $.cookie('layoutStyle');

        if (layoutStyle === 'boxed') {
            $('body').addClass('boxed');
            $('#customizer_layout #layout-wide').removeClass('active');
            $('#customizer_layout #layout-boxed').addClass('active');
        }

        if ($('body').hasClass('boxed')) {
            $('#customizer_layout #layout-boxed').addClass('active');
            $('#frontend_customizer #background_images').show();
        } else {
            $('#customizer_layout #layout-wide').addClass('active');
            $('#frontend_customizer #background_images').hide();
        }

        $('#customizer_layout a#layout-wide, #customizer_layout a#layout-boxed').on('click', function () {
            if ($(this).attr('id') == 'layout-wide') {
                $('body').removeClass('boxed');
                $('#frontend_customizer #background_images').slideUp(300);
                $.cookie('layoutStyle', 'wide');
                $(window).trigger('resize');
            } else {
                $('body').addClass('boxed');
                $('#frontend_customizer #background_images').slideDown(300);
                $.cookie('layoutStyle', 'boxed');
                $(window).trigger('resize');
            }
            $(this).addClass('active').siblings().removeClass('active');

            return false;
        });

        if ($('body').hasClass('header_2')) {
            $('#customizer_header #header_2').addClass('active');
        } else if ($('body').hasClass('header_3')) {
            $('#customizer_header #header_3').addClass('active');
        } else if ($('body').hasClass('header_4')) {
            $('#customizer_header #header_4').addClass('active');
        } else if ($('body').hasClass('header_5')) {
            $('#customizer_header #header_5').addClass('active');
        } else {
            $('#customizer_header #header_1').addClass('active');
        }

        $("#customizer_header a").on('click', function () {
            var id = $(this).attr('id');
            $.cookie('headerStyle', id);
            location.reload();

            return false;
        });

        var base_color = '<?php echo get_theme_mod('base_color'); ?>';
        var base_color_cookie = $.cookie('base_color');
        if (base_color_cookie) {
            base_color = base_color_cookie;
            _color_values.base_color = base_color;

            print_color_styles();
        }

        var bg_color = '<?php echo get_theme_mod('bg_color'); ?>';
        var bg_color_cookie = $.cookie('bg_color');
        if (bg_color_cookie) {
            bg_color = bg_color_cookie;
            _color_values.bg_color = bg_color;

            print_color_styles();
        }

        $("#base_color input, #base_color a").ColorPicker({
            color: base_color.replace('#', ''),
            livePreview: false,
            onShow: function (defaultColor) {
                $(defaultColor).fadeIn(300);
                return false;
            },
            onHide: function (defaultColor) {
                $(defaultColor).fadeOut(300);
                return false;
            },
            onChange: function (hsb, hex, rgb) {
                $("#base_color a").css('backgroundColor', '#' + hex);
                $("#base_color input").val('#' + hex);
                $.cookie('base_color', '#' + hex);
                _color_values.base_color = '#' + hex;
                print_color_styles();
            },
            onSubmit: function (hsb, hex, rgb) {
                $("#base_color a").css('backgroundColor', '#' + hex);
                $("#base_color input").val('#' + hex);
                $.cookie('base_color', '#' + hex);
                _color_values.base_color = '#' + hex;
                print_color_styles();
            }
        }).parent().find('a').css('backgroundColor', base_color);

        $("#bg_color input, #bg_color a").ColorPicker({
            color: bg_color.replace('#', ''),
            onShow: function (defaultColor) {
                $(defaultColor).fadeIn(300);
                $("body").removeClass("pattern-01 pattern-02 pattern-03 pattern-04 pattern-05 background-01 background-02 background-03 background-04 background-05");
                $.removeCookie('bg_image');
                $("#frontend_customizer #background_images ul li a.active").removeClass('active');
                return false;
            },
            onHide: function (defaultColor) {
                $(defaultColor).fadeOut(300);
                return false;
            },
            onChange: function (hsb, hex, rgb) {
                $("#bg_color a").css('backgroundColor', '#' + hex);
                $("#bg_color input").val('#' + hex);
                $.cookie('bg_color', '#' + hex);
                _color_values.bg_color = '#' + hex;
                print_color_styles();
            }
        }).parent().find('a').css('backgroundColor', bg_color);

        $('#reset_customizer').on('click', function () {
            $.removeCookie('layoutStyle');
            $.removeCookie('headerStyle');
            $.removeCookie('base_color');
            $.removeCookie('bg_color');
            $.removeCookie('bg_image');
            location.reload();
            return false;
        });

        $('#theme-font-1, #theme-font-2').on('change', function () {
            var font_1_url = $('#theme-font-1 option:selected').val();
            var font_1_text = $('#theme-font-1 option:selected').text();
            if (font_1_url != 'default') {
                $('#remote-font-1').attr('href', font_1_url);
            }

            var font_2_url = $('#theme-font-2 option:selected').val();
            var font_2_text = $('#theme-font-2 option:selected').text();
            if (font_2_url != 'default') {
                $('#remote-font-2').attr('href', font_2_url);
            }

            print_font_family_styles(font_1_text, font_2_text);
        });

        $("#frontend_customizer #background_images ul li a").on('click', function () {
            var id = $(this).attr('id').replace('#', '');
            $(this).closest('#background_images').find('a').removeClass('active');
            $("body").removeClass("pattern-01 pattern-02 pattern-03 pattern-04 pattern-05 background-01 background-02 background-03 background-04 background-05");
            $(this).addClass('active');
            $.cookie('bg_image', id);
            $("body").addClass(id);
            return false;
        });

        if ($.cookie('bg_image')) {
            $("body").removeClass("pattern-01 pattern-02 pattern-03 pattern-04 pattern-05 background-01 background-02 background-03 background-04 background-05");
            $("body").addClass($.cookie('bg_image'));
            $("#frontend_customizer #background_images ul li a#" + $.cookie('bg_image')).addClass('active');
        }

    });

    function print_color_styles() {
        "use strict";
        var $ = jQuery;
        if (typeof _color_selectors != 'object' || typeof _color_values != 'object') return;
        var style = '';
        var gen = function (values, key, rules) {
            var value = values[key];
            if (typeof (rules) == 'string' && typeof (value) == 'string') {
                rules = rules.replace(/%value/g, value);
                rules = rules.replace(/%rgba-0.9/g, convertHex(value, 90));
                rules = rules.replace(/%rgba-0.5/g, convertHex(value, 50));
                rules = rules.replace(/%rgba-0.8/g, convertHex(value, 80));
                style += '\n' + rules;
            }
            if (typeof (rules) == 'object' && typeof (value) == 'object') {
                $.each(rules, gen.on(this, value));
            }
        };
        $.each(_color_selectors, gen.on($(this), _color_values));

        $('#stm-custom-colors-css').html(style);
    }

    function print_font_family_styles(font_1, font_2) {
        "use strict";
        var $ = jQuery;
        if (typeof _font_family_selectors != 'object') return;
        var style = '';
        var gen = function (values, key, rules) {
            var value = values[key];
            if (typeof (rules) == 'string' && typeof (font_1) == 'string' && typeof (font_2) == 'string') {
                rules = rules.replace(/%value-1/g, font_1);
                rules = rules.replace(/%value-2/g, font_2);
                style += '\n' + rules;
            }
            if (typeof (rules) == 'object' && typeof (value) == 'object') {
                $.each(rules, gen.on(this, value));
            }
        };
        $.each(_font_family_selectors, gen.on($(this), _color_values));

        $('#stm-custom-font-family-css').html(style);
    }

    function convertHex(hex, opacity) {
        hex = hex.replace('#', '');
        r = parseInt(hex.substring(0, 2), 16);
        g = parseInt(hex.substring(2, 4), 16);
        b = parseInt(hex.substring(4, 6), 16);

        result = 'rgba(' + r + ',' + g + ',' + b + ',' + opacity / 100 + ')';
        return result;
    }
</script>