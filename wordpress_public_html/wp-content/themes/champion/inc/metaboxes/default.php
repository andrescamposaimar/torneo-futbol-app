<?php

if ( ! empty( $fields ) ) {
	echo '<table class="match_params">';
	foreach ( $fields as $fieldName => $fieldData ) {
		$options = array();
		if ( ! empty( $fieldData['options'] ) ) {
			$options = $fieldData['options'];
		}
		$type = $fieldData['type'];

		if ( $type == 'image_select' ) {
			$needMediaUpload = true;
		}

		if ( $type == 'datepicker' ) {
			$initDateTimePicker = true;
		}

		echo "<tr>";
		do_action( 'stm_metabox_'.$type, array( 'fieldName' => $fieldName, 'options' => $options ) );
		echo '</tr>';

	}
	echo '</table>';
}


?>


<?php if ( ! empty( $needMediaUpload ) ) { ?>
	<br class="ui-helper-clearfix">
	<?php wp_enqueue_media(); ?>

	<script type="text/javascript">

		jQuery(document).ready(function ($) {
			$('.stm_image .single_image_select').on('click', function (e) {
				e.preventDefault();
				ImageIds = [];
                var t = $(this);
				var custom_uploader = wp.media({
					title   : 'Select images',
					button  : {
						text: 'Attach'
					},
					multiple: true  // Set this to true to allow multiple files to be selected
				})
					.on('select', function () {
						var attachments = custom_uploader.state().get('selection').toJSON();
                        t.closest('.stm_image').find('.images ul li').remove();
                        t.closest('.stm_image').find('.remove_media').show();

						jQuery.each(attachments, function (i, data) {

                            console.log(data);

                            t.closest('.stm_image').find('.images ul').append('<li class="item-' + data.id + '" ></li>');

                            t.closest('.stm_image').find('.item-' + data.id).append('<img src="'+data.sizes.thumbnail.url+'" />');

							ImageIds.push(data.id);
						});

                        t.closest('.stm_image').find('.new_id_list').val(ImageIds);

					})
					.open();
			});

            $('.remove_media').on('click',function(){
                $(this).hide();
                $(this).closest('.stm_image').find(".new_id_list").val("");
                $(this).closest('.stm_image').find(".images ul li").remove();
            });
		});
	</script>
<?php } ?>


<?php

if ( ! empty( $initDateTimePicker ) ):
	wp_enqueue_script( 'jquery-ui-datepicker', 'http://code.jquery.com/ui/1.10.3/jquery-ui.js' );
	wp_enqueue_style( 'jquery-ui-datepicker', 'http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css' );
	?>
	<script type="text/javascript">
		jQuery(function () {
			jQuery('.datetimepicker').datepicker();
		});
	</script>
<?php
endif;


?>