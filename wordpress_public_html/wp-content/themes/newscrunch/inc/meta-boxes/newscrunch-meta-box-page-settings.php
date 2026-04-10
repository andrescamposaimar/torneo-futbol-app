<?php
$newscrunch_site_layout = get_post_meta( get_the_ID(), 'newscrunch_site_layout', true );
$newscrunch_page_sidebar = get_post_meta( get_the_ID(), 'newscrunch_page_sidebar', true );
$newscrunch_page_left_sidebar = get_post_meta( get_the_ID(), 'newscrunch_page_left_sidebar', true );

$newscrunch_sidebar_layout_choices = apply_filters(
							'newscrunch_layout_choices',
							array(
								'newscrunch_site_layout_left' => array(
									'label' => '',
									'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/customizer/assets/img/left.jpg',
								),
								'newscrunch_site_layout_right' => array(
									'label' => '',
									'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/customizer/assets/img/right.jpg',
								),
								'newscrunch_site_layout_without_sidebar' => array(
									'label' => '',
									'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/customizer/assets/img/full.jpg',
								),
								'newscrunch_site_layout_stretched' => array(
									'label' => '',
									'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/customizer/assets/img/stretched.jpg',
								),
								'newscrunch_site_layout_both' => array(
									'label' => 'ddd',
									'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/customizer/assets/img/both-sidebar.jpg',
								)
							)
						);

$newscrunch_sidebar_layout_choices = array(
							'' => array(
								'label' => '',
								'url'   => NEWSCRUNCH_TEMPLATE_DIR_URI . '/inc/meta-boxes/customizer.png',
							),
						) + $newscrunch_sidebar_layout_choices; ?>


<table class="form-table">
	<tbody>
		<tr>
			<th><label for="newscrunch_site_layout"><?php echo esc_html__('Layout','newscrunch'); ?></label></th>
			<td><?php $i=1; foreach ( $newscrunch_sidebar_layout_choices as $newscrunch_layout_id => $newscrunch_value ) : ?>
			<label class="tg-label">
				<input id="spnc-meta-input_<?php echo esc_attr($i);?>" type="radio" class="newscrunch_site_layout" name="newscrunch_site_layout" value="<?php echo esc_attr( $newscrunch_layout_id ); ?>" <?php checked( $newscrunch_site_layout, $newscrunch_layout_id ); ?> />
				<img id="<?php echo esc_attr($i);?>" src="<?php echo esc_url( $newscrunch_value['url'] ); ?>"/>
			</label>
			<?php $i++; endforeach; ?>
			</td>	
		</tr>
		<tr>
			<th><label for="seo_tobots"><?php echo esc_html__('Right Sidebar','newscrunch'); ?></label></th>
			<td>
				<select id="newscrunch_page_sidebar" name="newscrunch_page_sidebar">
					<?php if ( class_exists('Newscrunch_Plus') ): ?>
					<option value="" <?php selected( '', $newscrunch_page_sidebar ); ?>><?php echo esc_html__('From Customizer','newscrunch'); ?></option>
					<?php endif;?>
					<option value="sidebar-1" <?php selected( 'sidebar-1', $newscrunch_page_sidebar ); ?>><?php echo esc_html__('Primary Right','newscrunch'); ?></option>
					<option value="left-sidebar" <?php selected( 'left-sidebar', $newscrunch_page_sidebar ); ?>><?php echo esc_html__('Primary Left','newscrunch'); ?></option>
					<option value="front-page-side-1" <?php selected( 'front-page-side-1', $newscrunch_page_sidebar ); ?>><?php echo esc_html__('Front Sidebar 1','newscrunch'); ?></option>
					<option value="front-page-side-2" <?php selected( 'front-page-side-2', $newscrunch_page_sidebar ); ?>><?php echo esc_html__('Front Sidebar 2','newscrunch'); ?></option>
					<option value="footer-sidebar-1" <?php selected( 'footer-sidebar-1', $newscrunch_page_sidebar ); ?> ><?php echo esc_html__('Footer 1','newscrunch'); ?></option>
					<option value="footer-sidebar-2" <?php selected( 'footer-sidebar-2', $newscrunch_page_sidebar ); ?> ><?php echo esc_html__('Footer 2','newscrunch'); ?></option>
					<option value="footer-sidebar-3" <?php selected( 'footer-sidebar-3', $newscrunch_page_sidebar ); ?> ><?php echo esc_html__('Footer 3','newscrunch'); ?></option>
					<option value="footer-sidebar-4" <?php selected( 'footer-sidebar-4', $newscrunch_page_sidebar ); ?> ><?php echo esc_html__('Footer 4','newscrunch'); ?></option>
					</select>
				</td>
			</tr>
		<tr>
			<th><label for="seo_tobots"><?php echo esc_html__('Left Sidebar','newscrunch'); ?></label>
				<span class="spnc-meta-th"><?php echo esc_html__('This sidebar option will work with only both sidebar layout (The last one)','newscrunch'); ?></span></th>
			<td>
				<select id="newscrunch_page_left_sidebar" name="newscrunch_page_left_sidebar">
					<?php if ( class_exists('Newscrunch_Plus') ): ?>
					<option value="" <?php selected( '', $newscrunch_page_left_sidebar ); ?>><?php echo esc_html__('From Customizer','newscrunch'); ?></option>
					<?php endif;?>
					<option value="left-sidebar" <?php selected( 'left-sidebar', $newscrunch_page_left_sidebar ); ?>><?php echo esc_html__('Primary Left','newscrunch'); ?></option>
					<option value="sidebar-1" <?php selected( 'sidebar-1', $newscrunch_page_left_sidebar ); ?>><?php echo esc_html__('Primary Right','newscrunch'); ?></option>
					<option value="front-page-side-1" <?php selected( 'front-page-side-1', $newscrunch_page_left_sidebar ); ?>><?php echo esc_html__('Front Sidebar 1','newscrunch'); ?></option>
					<option value="front-page-side-2" <?php selected( 'front-page-side-2', $newscrunch_page_left_sidebar ); ?>><?php echo esc_html__('Front Sidebar 2','newscrunch'); ?></option>
					<option value="footer-sidebar-1" <?php selected( 'footer-sidebar-1', $newscrunch_page_left_sidebar ); ?> ><?php echo esc_html__('Footer 1','newscrunch'); ?></option>
					<option value="footer-sidebar-2" <?php selected( 'footer-sidebar-2', $newscrunch_page_left_sidebar ); ?> ><?php echo esc_html__('Footer 2','newscrunch'); ?></option>
					<option value="footer-sidebar-3" <?php selected( 'footer-sidebar-3', $newscrunch_page_left_sidebar ); ?> ><?php echo esc_html__('Footer 3','newscrunch'); ?></option>
					<option value="footer-sidebar-4" <?php selected( 'footer-sidebar-4', $newscrunch_page_left_sidebar ); ?> ><?php echo esc_html__('Footer 4','newscrunch'); ?></option>
					</select>
				</td>
			</tr>	
		</tbody>
	</table>
<?php