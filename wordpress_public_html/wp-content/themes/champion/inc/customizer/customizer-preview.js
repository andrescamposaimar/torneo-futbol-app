(function ($) {
	wp.customize('site_layout', function (value) {
		value.bind(function (to) {
			$("body").removeClass('boxed');
			$("body").addClass(to);
		});
	});

	wp.customize('bg_color', function (value) {
		value.bind(function (to) {
			$("body").css({'background-color': to});
		});
	});

    wp.customize('bg_content_color', function (value) {
        value.bind(function (to) {
            $('#wrapper').css({'background-color': to});
        });
    });

    wp.customize('bg_top_nav_color', function (value) {
        value.bind(function (to) {
            $('.pre_top_nav').css({'background-color': to});
        });
    });

    wp.customize('bg_footer_color', function (value) {
        value.bind(function (to) {
            $('.footer').css({'background-color': to});
        });
    });

    wp.customize('copyright_color', function (value) {
        value.bind(function (to) {
            $('.footer .copyright').css({'color': to});
        });
    });

	wp.customize('bg_image', function (value) {
		value.bind(function (to) {
			$("body").removeClass('pattern-01 pattern-02 pattern-03 pattern-04 pattern-05 pattern-06 pattern-07 pattern-08 pattern-09 pattern-10 pattern-11 background-01 background-02 background-03 background-04 background-05 background-06');
			$("body").addClass(to);
		});
	});

	wp.customize('bg_custom_image', function (value) {
		value.bind(function (to) {
			if (to != '') {
				$("body").css({'background-image': 'url('+to+')'});
			} else {
				$("body").css({'background-image': ''});
			}
		});
	});

	wp.customize('nav_bar_position', function (value) {
		value.bind(function (to) {
			$("body").removeClass('nav_bar_static nav_bar_fixed');
			$("body").addClass(to);
		});
	});

	wp.customize('header_type', function (value) {
		value.bind(function (to) {
			$("body").removeClass('header_2 header_3 header_4');
			$("body").addClass(to);
		});
	});



})(jQuery);

function strpos(haystack, needle, offset) {
	var i = (haystack + '').indexOf(needle, (offset || 0));
	return i === -1 ? false : i;
}