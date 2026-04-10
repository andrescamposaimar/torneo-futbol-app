<?php 
/**
* Widget API: Post Tabs Widget Class
* @package Newscrunch
*/
class Newscrunch_Tabs_Widget_Controller extends WP_Widget {
	//construct part
    function __construct()
    {
        parent::__construct(
        //Base ID of widget
        'newscrunch_post_tabs',

        //widget name will appear in UI
        esc_html__('Newscrunch : Post Tab Sidebar','newscrunch'),

        // Widget description
        array( 'description' => esc_html__('Display your posts in tabs','newscrunch'))  
        );

    }
    //Widget Front End
	public function widget( $args, $instance ) {
		if ( ! isset( $args['widget_id'] ) )  { $args['widget_id'] = $this->id; }
		$title 			= isset( $instance['title'] )  ? $instance['title'] :  '';
		$number 		= isset( $instance['number'] )  ? absint( $instance['number'] ) : 5;
		$show_date 		= isset( $instance['show_date'] ) ? $instance['show_date'] : true;
		if ( class_exists('Newscrunch_Plus') ): $show_read_time = isset( $instance['show_read_time'] ) ? $instance['show_read_time'] : true; endif;
		$rtitle 			= isset( $instance['rtitle'] )  ? $instance['rtitle'] :  __('Recent','newscrunch');
		$ptitle 			= isset( $instance['ptitle'] )  ? $instance['ptitle'] :  __('Popular','newscrunch');
		$ctitle 			= isset( $instance['ctitle'] )  ? $instance['ctitle'] :  __('Comment','newscrunch');
		$show_post_image= isset( $instance['show_post_image'] )  ? $instance['show_post_image'] : 'yes';
		$show_no_preview_img = isset( $instance['show_no_preview_img'] ) ? $instance['show_no_preview_img'] : 'none';
		$tab_id = time() + rand(1, 500);
		echo wp_kses_post($args['before_widget']);
		if ( $title ) { echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']); } ?>
		<script type="text/javascript">
		jQuery(document).ready(function() 
		{
			var tab_exp = jQuery(".spnc-tabs-one<?php echo esc_attr( $tab_id ); ?>").attr('id').split("-");
        	jQuery("#spnc-recent"+tab_exp[2]+"-panel").show();
        	jQuery("#"+jQuery(".spnc-tabs-one<?php echo esc_attr( $tab_id ); ?>").attr('id')+"-panel").show();
    		jQuery('.spnc-label-tab<?php echo esc_attr( $tab_id ); ?>').click(function()
    		{
        		jQuery(".spnc-panel<?php echo esc_attr( $tab_id ); ?>").hide();
        		var words = jQuery(this).attr('id').split("-");
        		jQuery("#"+words[0]+"-"+words[1]+words[2]+"-panel").show();
        		jQuery(this).addClass('active').siblings().removeClass('active');
    		});
		});
		</script>
		<input class="spnc-radio" id="spnc-recent<?php echo esc_attr( $tab_id ); ?>" name="group" type="radio" checked>
		<input class="spnc-radio" id="spnc-popular<?php echo esc_attr( $tab_id ); ?>" name="group" type="radio">
		<input class="spnc-radio" id="spnc-trending<?php echo esc_attr( $tab_id ); ?>" name="group" type="radio">
		<!--tabs panel-->
		<div class="spnc-tabs">
			<div class="spnc-tabs-one spnc-tabs-one<?php echo esc_attr( $tab_id ); ?> spnc-label-tab  spnc-label-tab<?php echo esc_attr( $tab_id ); ?> active" id="spnc-recent-<?php echo esc_attr( $tab_id ); ?>">
				<label class="spnc-tab" id="spnc-recent-tab" for="spnc-recent<?php echo esc_attr( $tab_id ); ?>">
					<?php echo esc_attr( $rtitle ); ?>
				</label>
			</div>
			<div class="spnc-tabs-two spnc-label-tab spnc-label-tab<?php echo esc_attr( $tab_id ); ?>" id="spnc-popular-<?php echo esc_attr( $tab_id ); ?>">
				<label class="spnc-tab" id="spnc-popular-tab" for="spnc-popular<?php echo esc_attr( $tab_id ); ?>">
					<?php echo esc_attr( $ptitle ); ?>
				</label>
			</div>
			<div class="spnc-tabs-three spnc-label-tab spnc-label-tab<?php echo esc_attr( $tab_id ); ?>" id="spnc-trending-<?php echo esc_attr( $tab_id ); ?>">
				<label class="spnc-tab" id="spnc-trending-tab" for="spnc-trending<?php echo esc_attr( $tab_id ); ?>">
					<?php echo esc_attr( $ctitle ); ?>
				</label>
			</div>
		</div>

		<!--tabs panel section-->
		<div class="spnc-panels">
			<!--Recent Panel-->
			<div class="spnc-panel spnc-panel<?php echo esc_attr( $tab_id ); ?>" id="spnc-recent<?php echo esc_attr( $tab_id ); ?>-panel">
				<ul class="wp-block-latest-posts__list has-dates has-author wp-block-latest-posts">
					<?php
						$recent_query_args = new WP_Query( apply_filters( 'widget_posts_args', array(
							'no_found_rows'       => true,
							'post_status'         => 'publish',
							'posts_per_page'      => $number,
							'ignore_sticky_posts' => true
						) ) );
						if ( $recent_query_args->have_posts() ) { 
							while ( $recent_query_args->have_posts() ) {
							$recent_query_args->the_post();?>
							<li>
								<?php if ( $show_post_image == 'yes' ) { ?>
								<div class="wp-block-latest-posts__featured-image <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
									<?php 
									if ( has_post_thumbnail() ) { 
										the_post_thumbnail( 'thumbnail', ['class' => 'size-thumbnail wp-post-image'] ); 
									} 
									else {
										if ( $show_no_preview_img == 'view' ) { ?>
											<img src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
										<?php }
									} ?>
								</div>
								<?php }
								if ( $show_date ) { ?>
								<span class="date"> 
									<i class="fa fa-clock"></i>
									<?php echo newcrunch_post_date_time(get_the_ID(), 'no'); ?>
								</span>
								<?php } 
								if ( class_exists('Newscrunch_Plus') ):
                                    if(get_theme_mod('reading_time_enable',false) === true):
                                        if($show_read_time=='on'){ ?>
                                            <span class="spnc-read-time"><i class="fa fa-eye"></i> <?php spncp_reading_time();?></span>
                                 <?php } endif; endif;?>
								<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
							</li>
							<?php }
							wp_reset_postdata();
						}
						?>	
				</ul>
			</div>

			<!--Popular Panel-->	
			<div class="spnc-panel spnc-panel<?php echo esc_attr( $tab_id ); ?>" id="spnc-popular<?php echo esc_attr( $tab_id ); ?>-panel">
				<ul class="wp-block-latest-posts__list has-dates has-author wp-block-latest-posts">
					<?php
					$popular_query = new WP_Query( apply_filters( 'widget_posts_args', array(
						'no_found_rows'       => true,
						'post_status'         => 'publish',
						'posts_per_page'      => $number,
						'orderby'   		  => 'meta_value_num',
						'meta_key'  		  => 'newscrunch_views',
						'ignore_sticky_posts' => true
					) ) );
				
					if ( $popular_query->have_posts() ) {
						while ( $popular_query->have_posts() ) {
						$popular_query->the_post(); ?>
						<li>
							<?php if ( $show_post_image == 'yes' ) { ?>
							<div class="wp-block-latest-posts__featured-image <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
								<?php 
								if ( has_post_thumbnail() ){
									the_post_thumbnail( 'thumbnail', ['class' => 'size-thumbnail wp-post-image'] ); 
								} 
								else {
									if ( $show_no_preview_img == 'view' ) { ?>
										<img src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
									<?php }
									}
								?>
							</div>
							<?php }
							if ( $show_date ) { ?>
								<span class="date"> 
									<i class="fa fa-clock"></i>
									<?php echo newcrunch_post_date_time(get_the_ID(), 'no'); ?>
								</span>
							<?php } 
							if ( class_exists('Newscrunch_Plus') ):
                                    if(get_theme_mod('reading_time_enable',false) === true):
                                        if($show_read_time=='on'){ ?>
                                            <span class="spnc-read-time"><i class="fa fa-eye"></i> <?php spncp_reading_time();?></span>
                                 <?php } endif; endif;?>
							<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
						</li>
						<?php 
						}
						wp_reset_postdata();
					}
					?>
				</ul>
			</div>

			<!--Trending Panel-->
			<div class="spnc-panel spnc-panel<?php echo esc_attr( $tab_id ); ?>" id="spnc-trending<?php echo esc_attr( $tab_id ); ?>-panel">
				<ul class="wp-block-latest-posts__list has-dates has-author wp-block-latest-posts">
					<?php
						$comment_query = new WP_Query( apply_filters( 'widget_posts_args', array(
							'posts_per_page'      => $number,
							'post_status'         => 'publish',
							'orderby'   		  => 'comment_count',
							'ignore_sticky_posts' => true
						) ) );
						if ( $comment_query->have_posts() ) {
							while ( $comment_query->have_posts() ) {
							$comment_query->the_post(); ?>
							<li>
								<?php if ( $show_post_image == 'yes' ) { ?>
								<div class="wp-block-latest-posts__featured-image <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
									<?php 
									if ( has_post_thumbnail() ) { 
										the_post_thumbnail( 'thumbnail', ['class' => 'size-thumbnail wp-post-image'] ); 
									} 
									else {
										if ( $show_no_preview_img == 'view' ) { ?>
											<img src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
										<?php }
									}
									?>
								</div>
								<?php }
								if ( $show_date ) { ?>
									<span class="date"> 
										<i class="fa fa-clock"></i>
										<?php echo newcrunch_post_date_time(get_the_ID(), 'no'); ?>
									</span>
								<?php }
								if ( class_exists('Newscrunch_Plus') ):
                                    if(get_theme_mod('reading_time_enable',false) === true):
                                        if($show_read_time=='on'){ ?>
                                            <span class="spnc-read-time"><i class="fa fa-eye"></i> <?php spncp_reading_time();?></span>
                                 <?php } endif; endif;?>
								<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
							</li>
							<?php
						}
						wp_reset_postdata();
					}
					?>
				</ul>
			</div>
		</div>
		<?php echo wp_kses_post($args['after_widget']); ?>
	<?php	
	}
	
	
	public function form( $instance ) {
		if ( isset( $instance[ 'title' ])){ $title = $instance[ 'title' ]; } else { $title = '' ;}
		if ( isset( $instance[ 'number' ])){ $number = $instance[ 'number' ]; } else { $number = 5 ;}
		if ( isset( $instance[ 'show_date' ])){ $show_date = (bool) $instance['show_date']; } else { $show_date = false ;}
		if ( class_exists('Newscrunch_Plus') ): 
			if ( isset( $instance[ 'show_read_time' ])){ $show_read_time = (bool) $instance['show_read_time']; } else { $show_read_time = false ;}
			if ( isset( $instance[ 'rtitle' ])){ $rtitle = $instance[ 'rtitle' ]; } else { $rtitle = __('Recent','newscrunch') ;}
			if ( isset( $instance[ 'ptitle' ])){ $ptitle = $instance[ 'ptitle' ]; } else { $ptitle = __('Popular','newscrunch') ;}
			if ( isset( $instance[ 'ctitle' ])){ $ctitle = $instance[ 'ctitle' ]; } else { $ctitle = __('Comment','newscrunch') ;} 
		endif;
		if ( isset( $instance[ 'show_post_image' ])){ $show_post_image = $instance[ 'show_post_image' ]; } else { $show_post_image = 'yes' ;}
		if ( isset( $instance[ 'show_no_preview_img' ])){ $show_no_preview_img = $instance[ 'show_no_preview_img' ]; } else { $show_no_preview_img = 'yes' ;} ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' )); ?>"><?php echo esc_html__( 'Title' , 'newscrunch' ); echo ':'; ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
			</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'show_no_preview_img' )); ?>"><?php esc_html_e( 'Hide default thumbnail image', 'newscrunch' ); echo ':'; ?></label>
				<select name="<?php echo esc_attr( $this->get_field_name( 'show_no_preview_img' )); ?>">
					<option <?php if ( $show_no_preview_img == 'none' ) {  echo 'selected'; } ?> value="none"><?php esc_html_e( 'Hide' , 'newscrunch' ); ?></option>
					<option <?php if ( $show_no_preview_img == 'view' ) {  echo 'selected'; } ?> value="view"><?php esc_html_e( 'Show' , 'newscrunch' ); ?></option>
				</select>
			</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'number' )); ?>"><?php esc_html_e( 'Number of posts to show', 'newscrunch' ); echo ':'; ?></label>
				<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'number' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' )); ?>" type="number" step="1" min="1" value="<?php echo esc_attr( $number); ?>" size="3" />
			</p>
			<p>
				<input class="checkbox" type="checkbox"<?php checked( $show_date ); ?> id="<?php echo esc_attr( $this->get_field_id( 'show_date' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_date' )); ?>" />
				<label for="<?php echo esc_attr( $this->get_field_id( 'show_date' )); ?>"><?php esc_html_e( 'Show post date', 'newscrunch' ); ?></label>
			</p>
			<?php if ( class_exists('Newscrunch_Plus') ):?>
			<p>
				<input class="checkbox" type="checkbox"<?php checked( $show_read_time ); ?> id="<?php echo esc_attr( $this->get_field_id( 'show_read_time' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_read_time' )); ?>" />
				<label for="<?php echo esc_attr( $this->get_field_id( 'show_read_time' )); ?>"><?php esc_html_e( 'Show read time', 'newscrunch' ); ?></label>
				<label style="color: #11b524;" > <?php echo esc_html__( 'If you want display post read time, then you should need to enable this setting from customizer ( Global >> Read Time )','newscrunch' ); ?> </label>
			</p>
			<!-- Recent Title -->
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'rtitle' )); ?>"><?php echo esc_html__( 'Recent Title' , 'newscrunch' ); echo ':'; ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'rtitle' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'rtitle' )); ?>" type="text" value="<?php echo esc_attr( $rtitle ); ?>" />
			</p>
			<!-- Popular Title -->
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'ptitle' )); ?>"><?php echo esc_html__( 'Popular Title' , 'newscrunch' ); echo ':'; ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ptitle' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'ptitle' )); ?>" type="text" value="<?php echo esc_attr( $ptitle ); ?>" />
			</p>
			<!-- Comment Title -->
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'ctitle' )); ?>"><?php echo esc_html__( 'Comment Title' , 'newscrunch' ); echo ':'; ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ctitle' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'ctitle' )); ?>" type="text" value="<?php echo esc_attr( $ctitle ); ?>" />
			</p>
			<?php endif; ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'show_post_image' )); ?>"><?php esc_html_e( 'Show post featured image', 'newscrunch' ); echo ':'; ?></label>
				<select name="<?php echo esc_attr( $this->get_field_name( 'show_post_image' )); ?>">
					<option <?php if ( $show_post_image == 'yes' ) {  echo 'selected'; } ?> value="yes"><?php esc_html_e( 'Show' , 'newscrunch' ); ?></option>
					<option <?php if ( $show_post_image == 'no' ) {  echo 'selected'; } ?> value="no"><?php esc_html_e( 'Hide' , 'newscrunch' ); ?></option>
				</select>
			</p>
		<?php
	}	


	//save or uption option
	public function update( $new_instance, $old_instance ) {
	  $instance 			= $old_instance;
      $instance['title'] 	= ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
      $instance['number'] 	= ( ! empty( $new_instance['number'] ) ) ? absint($new_instance['number']) : '';
      $instance['show_date'] = ( ! empty( $new_instance['show_date'] ) ) ? newscrunch_sanitize_checkbox($new_instance['show_date']) : false;
      if ( class_exists('Newscrunch_Plus') ): 
      	$instance['show_read_time'] = ( ! empty( $new_instance['show_read_time'] ) ) ? newscrunch_sanitize_checkbox($new_instance['show_read_time']) : false; 
      	$instance['rtitle'] 	= ( ! empty( $new_instance['rtitle'] ) ) ? sanitize_text_field( $new_instance['rtitle'] ) : '';
      	$instance['ptitle'] 	= ( ! empty( $new_instance['ptitle'] ) ) ? sanitize_text_field( $new_instance['ptitle'] ) : '';
      	$instance['ctitle'] 	= ( ! empty( $new_instance['ctitle'] ) ) ? sanitize_text_field( $new_instance['ctitle'] ) : '';
      endif;
      $instance['show_post_image'] 	= ( ! empty( $new_instance['show_post_image'] ) ) ? sanitize_text_field($new_instance['show_post_image']) : 'yes';
      $instance['show_no_preview_img'] 	= ( ! empty( $new_instance['show_no_preview_img'] ) ) ? sanitize_text_field( $new_instance['show_no_preview_img'] ) : 'none';
		return $instance;
	}
}