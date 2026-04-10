jQuery(document).ready(function ($) {
    "use strict";

    $(".wpml_switcher > a").on('click', function () {
        $(".wpml_switcher ul").slideToggle(300);
        return false;
    });

    $("select.s2").select2({
        minimumResultsForSearch: -1
    });

    $("body").on('click', function (kik) {
        if (!$(kik.target).is(".wpml_switcher, .wpml_switcher *") && $(".wpml_switcher ul").is(":visible")) {
            $(".wpml_switcher ul").slideUp(300);
        }

        if (!$(kik.target).is(".navbar-form, .navbar-form *")) {
            $(".navbar-form .form-group").removeClass('active');
            $(".navbar-form .form-group").fadeOut(300);
        }
    });

    $(".navbar-form button").on('click', function () {
        if (!$(".navbar-form .form-group").hasClass('active')) {
            var w = $(".navbar-form .form-control").innerWidth();
            $(".navbar-form .form-group").fadeIn(300);
            $(".navbar-form .form-control").trigger('focus');
            $(".navbar-form .form-group").addClass('active');
            return false;
        }
    });

    $('body.nav_bar_fixed .navbar-default').affix({
        offset: {
            top: $('#header').height() - $('.navbar-default').height()
        }
    });

    $(".widget_categories > ul > li.i-have-kids > a").on('click', function () {
        $(this).parent('li').toggleClass('active');
        $(this).parent('li').find('> ul.children').slideToggle();
        return false;
    });

    $(".widget_pages > ul > li.page_item_has_children > a").on('click', function () {
        $(this).parent('li').toggleClass('active');
        $(this).parent('li').find('> ul.children').slideToggle();
        return false;
    });

    $(".widget_nav_menu ul.menu > li.menu-item-has-children > a").on('click', function () {
        $(this).parent('li').toggleClass('active');
        $(this).parent('li').find('> ul.sub-menu').slideToggle();
        return false;
    });

    $(".widget_product_categories ul.product-categories > li.cat-parent > a").on('click', function () {
        $(this).parent('li').toggleClass('active');
        $(this).parent('li').find('> ul.children').slideToggle();
        return false;
    });

    $(".fancybox").fancybox({
        openEffect: 'elastic',
        closeEffect: 'elastic'
    });

    if (!$('html').is('.ie9')) {
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i).split('_');
            if (key[0] == 'liked') {
                $(".like_button[data-id='" + key[1] + "']").addClass('disabled');
            }
        }
    }

    $(".wpb_tabs.full_width").each(function () {
        var wpb_tabs = 0;
        wpb_tabs = $(this).find(".wpb_tabs_nav > li").length;
        var w = 100 / wpb_tabs;
        $(this).find(".wpb_tabs_nav > li").css({'width': w + '%'});
    });

    $(".customizer_page .ivan-tabs-wrap").remove();

    $(".close_notice").on('click', function(){
        $(this).parent().slideUp(300);
        return false;
    });

    $(".woocommerce .woocommerce-message, .woocommerce-page .woocommerce-message, .woocommerce .woocommerce-info, .woocommerce-page .woocommerce-info").slideDown(600);

    $(window).load(function () {
        $('body').removeClass('preloader');
        $('#site_preloader').fadeOut(300);
    });

});

function champion_stm_like(t) {
    "use strict";
    var post_id = t.attr('data-id');
    var count = parseInt(t.find("span").text());
    console.log(security_like);
    if (localStorage.getItem("liked_" + post_id)) {
        return false;
    } else {
        localStorage.setItem("liked_" + post_id, 1);
        jQuery.post(ajaxurl, {action: "champion_stm_like", id: post_id, security: security_like}).done(function () {
            t.addClass("disabled");
            count++;
            t.find("span").text(count);
        });
    }
}