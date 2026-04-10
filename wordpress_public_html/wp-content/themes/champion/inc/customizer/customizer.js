jQuery(document).ready(function ($) {
	var bg_type = $("#customize-control-bg_type select");
	var bg_color = $("#customize-control-bg_color input[type='text']");
	var bg_image = $("#customize-control-bg_image input");
	var site_layout = $("#customize-control-site_layout input");
	var site_layout_checked = $("#customize-control-site_layout input:checked");

	wp.customize('site_layout', function (value) {
		value.bind(function (to) {
			if (to == 'boxed') {
				$("#customize-control-background_subtitle").show();
				$("#customize-control-bg_image").show();
				$("#customize-control-bg_custom_image").show();
			} else {
				$("#customize-control-background_subtitle").hide();
				$("#customize-control-bg_image").hide();
				$("#customize-control-bg_custom_image").hide();
			}
		});
	});

	wp.customize('bg_color', function (value) {
		value.bind(function (to) {
			$(".theme_bg li").css({'background-color': to});
		});
	});

	if (site_layout_checked.val() == 'boxed') {
		$("#customize-control-background_subtitle").show();
		$("#customize-control-bg_image").show();
		$("#customize-control-bg_custom_image").show();
	} else {
		$("#customize-control-background_subtitle").hide();
		$("#customize-control-bg_image").hide();
		$("#customize-control-bg_custom_image").hide();
	}

	bg_image.on('change', function () {
		$(".theme_bg li.active").removeClass('active');
		$(this).closest('li').addClass('active');
	});

	$("#customize-control-bg_image input[name='bg_image']:checked").closest('li').addClass('active');

	$(".theme_bg li").css({'background-color': bg_color.val()});
});