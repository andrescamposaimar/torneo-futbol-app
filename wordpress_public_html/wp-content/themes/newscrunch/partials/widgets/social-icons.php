<?php 
/**
* Widget API: Social Icon Widget Class
* @package Newscrunch
*/
class Newscrunch_Social_Icon_Widget_Controller extends WP_Widget {
	//construct part
	function __construct()
	{
	    parent::__construct(
	    //Base ID of widget
	    'newscrunch_social_icons',

	    //widget name will appear in UI
	    esc_html__('Newscrunch : Social Icons Sidebar','newscrunch'),

	    // Widget description
	    array( 'description' => esc_html__('Display your social icons','newscrunch'))  
	    );

	}
	//Widget Front End	
	public function widget( $args, $instance ) 
	{
		if ( ! isset( $args['widget_id'] ) ) { $args['widget_id'] = $this->id;}
		$title = isset( $instance['title'] )  ? $instance['title'] :  '';
		echo wp_kses_post($args['before_widget']);
        if ( $title ) { echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']); } ?>
        
        <div class="spnc-social-follow">
			<ul class="spnc-services-grid">
				<?php
					//Twitter Link
					$twitter_num = isset( $instance['twitter_num'] ) ? $instance['twitter_num'] : '';
					$twitter_follow = isset( $instance['twitter_follow'] ) ? $instance['twitter_follow'] : '';
					if(!empty($instance['twitter_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-twitter" href="<?php echo esc_url($instance['twitter_link']); ?>" <?php if(!empty($instance['twitter_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Twitter','newscrunch'); ?>">
								<i class="fa-brands fa-x-twitter f-twitter"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($twitter_num);?></span>
									<span class="spnc-label"><?php echo esc_html($twitter_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Facebook Link
					$facebook_num = isset( $instance['facebook_num'] ) ? $instance['facebook_num'] : '';
					$facebook_follow = isset( $instance['facebook_follow'] ) ? $instance['facebook_follow'] : '';
					if(!empty($instance['facebook_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-facebook" href="<?php echo esc_url($instance['facebook_link']); ?>" <?php if(!empty($instance['facebook_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Facebook','newscrunch'); ?>">
								<i class="fab fa-facebook-f"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($facebook_num);?></span>
									<span class="spnc-label"><?php echo esc_html($facebook_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Instagram Link
					$instagram_num = isset( $instance['instagram_num'] ) ? $instance['instagram_num'] : '';
					$instagram_follow = isset( $instance['instagram_follow'] ) ? $instance['instagram_follow'] : '';
					if(!empty($instance['instagram_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-instagram" href="<?php echo esc_url($instance['instagram_link']); ?>" <?php if(!empty($instance['instagram_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Instagram','newscrunch'); ?>">
								<i class="fab fa-instagram"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($instagram_num);?></span>
									<span class="spnc-label"><?php echo esc_html($instagram_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Youtube Link
					$youtube_num = isset( $instance['youtube_num'] ) ? $instance['youtube_num'] : '';
					$youtube_follow = isset( $instance['youtube_follow'] ) ? $instance['youtube_follow'] : '';
					if(!empty($instance['youtube_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-youtube" href="<?php echo esc_url($instance['youtube_link']); ?>" <?php if(!empty($instance['youtube_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Youtube','newscrunch'); ?>">
								<i class="fab fa-youtube"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($youtube_num);?></span>
									<span class="spnc-label"><?php echo esc_html($youtube_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Linkedin Link
					$linkedin_num = isset( $instance['linkedin_num'] ) ? $instance['linkedin_num'] : '';
					$linkedin_follow = isset( $instance['linkedin_follow'] ) ? $instance['linkedin_follow'] : '';
					if(!empty($instance['linkedin_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-linkedin" href="<?php echo esc_url($instance['linkedin_link']); ?>" <?php if(!empty($instance['linkedin_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('LinkedIn','newscrunch'); ?>">
								<i class="fab fa-linkedin"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($linkedin_num);?></span>
									<span class="spnc-label"><?php echo esc_html($linkedin_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Skype Link
					$skype_num = isset( $instance['skype_num'] ) ? $instance['skype_num'] : '';
					$skype_follow = isset( $instance['skype_follow'] ) ? $instance['skype_follow'] : '';
					if(!empty($instance['skype_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-skype" href="<?php echo esc_url($instance['skype_link']); ?>" <?php if(!empty($instance['skype_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Skype','newscrunch'); ?>">
								<i class="fab fa-skype"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($skype_num);?></span>
									<span class="spnc-label"><?php echo esc_html($skype_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Vimeo Link
					$vimeo_num = isset( $instance['vimeo_num'] ) ? $instance['vimeo_num'] : '';
					$vimeo_follow = isset( $instance['vimeo_follow'] ) ? $instance['vimeo_follow'] : '';
					if(!empty($instance['vimeo_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-vimeo" href="<?php echo esc_url($instance['vimeo_link']); ?>" <?php if(!empty($instance['vimeo_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Vimeo','newscrunch'); ?>">
								<i class="fab fa-vimeo-square"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($vimeo_num);?></span>
									<span class="spnc-label"><?php echo esc_html($vimeo_follow);?></span>
								</div>
							</a>
						</li>
					<?php }

					//Dribble Link
					$dribble_num = isset( $instance['dribble_num'] ) ? $instance['dribble_num'] : '';
					$dribble_follow = isset( $instance['dribble_follow'] ) ? $instance['dribble_follow'] : '';
					if(!empty($instance['dribble_link'])) { ?>
						<li class="spnc-service-wrap">
							<a class="spnc-service-link spnc-dribbble" href="<?php echo esc_url($instance['dribble_link']); ?>" <?php if(!empty($instance['dribble_target'])){ echo "target='_blank'"; } ?> title="<?php esc_attr_e('Dribble','newscrunch'); ?>">
								<i class="fab fa-dribbble"></i>
								<div class="spnc-social-content">
									<span class="spnc-count"><?php echo esc_html($dribble_num);?></span>
									<span class="spnc-label"><?php echo esc_html($dribble_follow);?></span>
								</div>
							</a>
						</li>
					<?php } 
				?>
			</ul>
		</div>
		<?php
		echo wp_kses_post($args['after_widget']);
	}

	// Widget Backend
	public function form( $instance ) {
		if ( isset( $instance[ 'title' ])){ $title = $instance[ 'title' ]; } else { $title = esc_html__('Widget Title','newscrunch' );}
		//facebook
		if ( isset( $instance[ 'facebook_link' ])){ $facebook_link = $instance[ 'facebook_link' ]; } else { $facebook_link = '#'; }
		if ( isset( $instance[ 'facebook_target' ])){ $facebook_target = $instance[ 'facebook_target' ]; } 
		if ( isset( $instance[ 'facebook_num' ])){ $facebook_num = $instance[ 'facebook_num' ]; } else { $facebook_num = '14k'; }
		if ( isset( $instance[ 'facebook_follow' ])){ $facebook_follow = $instance[ 'facebook_follow' ]; } else { $facebook_follow = esc_html__('Followers','newscrunch' ); }

		//twitter
		if ( isset( $instance[ 'twitter_link' ])){ $twitter_link = $instance[ 'twitter_link' ]; } else { $twitter_link = '#'; }
		if ( isset( $instance[ 'twitter_target' ])){ $twitter_target = $instance[ 'twitter_target' ]; } 
		if ( isset( $instance[ 'twitter_num' ])){ $twitter_num = $instance[ 'twitter_num' ]; } else { $twitter_num = '45k'; }
		if ( isset( $instance[ 'twitter_follow' ])){ $twitter_follow = $instance[ 'twitter_follow' ]; } else { $twitter_follow = esc_html__('Followers','newscrunch' ); }		

		//Lindedin
		if ( isset( $instance[ 'linkedin_link' ])){ $linkedin_link = $instance[ 'linkedin_link' ]; } else { $linkedin_link = '#'; }
		if ( isset( $instance[ 'linkedin_target' ])){ $linkedin_target = $instance[ 'linkedin_target' ]; } 
		if ( isset( $instance[ 'linkedin_num' ])){ $linkedin_num = $instance[ 'linkedin_num' ]; } else { $linkedin_num = '55k'; }
		if ( isset( $instance[ 'linkedin_follow' ])){ $linkedin_follow = $instance[ 'linkedin_follow' ]; } else { $linkedin_follow = esc_html__('Followers','newscrunch' ); }

		//Instagram
		if ( isset( $instance[ 'instagram_link' ])){ $instagram_link = $instance[ 'instagram_link' ]; } else { $instagram_link = '#'; }
		if ( isset( $instance[ 'instagram_target' ])){ $instagram_target = $instance[ 'instagram_target' ]; } 
		if ( isset( $instance[ 'instagram_num' ])){ $instagram_num = $instance[ 'instagram_num' ]; } else { $instagram_num = '55k'; }
		if ( isset( $instance[ 'instagram_follow' ])){ $instagram_follow = $instance[ 'instagram_follow' ]; } else { $instagram_follow = esc_html__('Followers','newscrunch' ); }

		//Youtube
		if ( isset( $instance[ 'youtube_link' ])){ $youtube_link = $instance[ 'youtube_link' ]; } else { $youtube_link = '#'; }
		if ( isset( $instance[ 'youtube_target' ])){ $youtube_target = $instance[ 'youtube_target' ]; } 
		if ( isset( $instance[ 'youtube_num' ])){ $youtube_num = $instance[ 'youtube_num' ]; } else { $youtube_num = '65k'; }
		if ( isset( $instance[ 'youtube_follow' ])){ $youtube_follow = $instance[ 'youtube_follow' ]; } else { $youtube_follow = esc_html__('Followers','newscrunch' ); }

		//Skype
		if ( isset( $instance[ 'skype_link' ])){ $skype_link = $instance[ 'skype_link' ]; } else { $skype_link = '#'; }
		if ( isset( $instance[ 'skype_target' ])){ $skype_target = $instance[ 'skype_target' ]; } 
		if ( isset( $instance[ 'skype_num' ])){ $skype_num = $instance[ 'skype_num' ]; } else { $skype_num = '75k'; }
		if ( isset( $instance[ 'skype_follow' ])){ $skype_follow = $instance[ 'skype_follow' ]; } else { $skype_follow = esc_html__('Followers','newscrunch' ); }

		//Vimeo
		if ( isset( $instance[ 'vimeo_link' ])){ $vimeo_link = $instance[ 'vimeo_link' ]; } else { $vimeo_link = '#'; }
		if ( isset( $instance[ 'vimeo_target' ])){ $vimeo_target = $instance[ 'vimeo_target' ]; } 
		if ( isset( $instance[ 'vimeo_num' ])){ $vimeo_num = $instance[ 'vimeo_num' ]; } else { $vimeo_num = '85k'; }
		if ( isset( $instance[ 'vimeo_follow' ])){ $vimeo_follow = $instance[ 'vimeo_follow' ]; } else { $vimeo_follow = esc_html__('Followers','newscrunch' ); }

		//Drrible
		if ( isset( $instance[ 'dribble_link' ])){ $dribble_link = $instance[ 'dribble_link' ]; } else { $dribble_link = '#'; }
		if ( isset( $instance[ 'dribble_target' ])){ $dribble_target = $instance[ 'dribble_target' ]; } 
		if ( isset( $instance[ 'dribble_num' ])){ $dribble_num = $instance[ 'dribble_num' ]; } else { $dribble_num = '5k'; }
		if ( isset( $instance[ 'dribble_follow' ])){ $dribble_follow = $instance[ 'dribble_follow' ]; } else { $dribble_follow = esc_html__('Followers','newscrunch' ); }


		// Widget admin form
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' )); ?>"><?php echo esc_html__( 'Widget Title' , 'newscrunch' ); echo ':';?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
			
			<!-- Twitter Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'twitter_link' )); ?>"><?php esc_html_e('Twitter','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'twitter_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'twitter_num' )); ?>" type="text" value="<?php if($twitter_num) echo esc_attr( $twitter_num ); ?>" placeholder="<?php echo esc_attr( '45k' ); ?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'twitter_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'twitter_follow' )); ?>" type="text" value="<?php if($twitter_follow) echo esc_attr( $twitter_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'twitter_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'twitter_link' )); ?>" type="text" value="<?php if($twitter_link) echo esc_attr( $twitter_link ); ?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['twitter_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'twitter_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'twitter_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'twitter_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Facebook Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'facebook_link' )); ?>"><?php esc_html_e('Facebook','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'facebook_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'facebook_num' )); ?>" type="text" value="<?php if($facebook_num) echo esc_attr( $facebook_num ); ?>" placeholder="<?php echo esc_attr('14k'); ?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'facebook_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'facebook_follow' )); ?>" type="text" value="<?php if($facebook_follow) echo esc_attr( $facebook_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'facebook_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'facebook_link' )); ?>" type="text" value="<?php if($facebook_link) echo esc_attr( $facebook_link ); ?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['facebook_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'facebook_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'facebook_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'facebook_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Instagram Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'instagram_link' )); ?>"><?php esc_html_e('Instagram','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'instagram_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'instagram_num' )); ?>" type="text" value="<?php if($instagram_num) echo esc_attr( $instagram_num ); ?>" placeholder="<?php echo esc_attr( '55k' ); ?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'instagram_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'instagram_follow' )); ?>" type="text" value="<?php if($instagram_follow) echo esc_attr( $instagram_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'instagram_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'instagram_link' )); ?>" type="text" value="<?php if($instagram_link) echo esc_attr( $instagram_link );?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['instagram_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'instagram_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'instagram_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'instagram_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Youtube Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'youtube_link' )); ?>"><?php esc_html_e('Youtube','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'youtube_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'youtube_num' )); ?>" type="text" value="<?php if($youtube_num) echo esc_attr( $youtube_num ); ?>" placeholder="<?php echo esc_attr( '65k' ); ?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'youtube_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'youtube_follow' )); ?>" type="text" value="<?php if($youtube_follow) echo esc_attr( $youtube_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'youtube_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'youtube_link' )); ?>" type="text" value="<?php if($youtube_link) echo esc_attr( $youtube_link );?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['youtube_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'youtube_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'youtube_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'youtube_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Lindedin Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'linkedin_link' )); ?>"><?php esc_html_e('LinkedIn','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'linkedin_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'linkedin_num' )); ?>" type="text" value="<?php if($linkedin_num) echo esc_attr( $linkedin_num ); ?>" placeholder="<?php echo esc_attr( '55k' ); ?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'linkedin_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'linkedin_follow' )); ?>" type="text" value="<?php if($linkedin_follow) echo esc_attr( $linkedin_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'linkedin_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'linkedin_link' )); ?>" type="text" value="<?php if($linkedin_link) echo esc_attr( $linkedin_link ); ?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['linkedin_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'linkedin_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'linkedin_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'linkedin_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>	

			<!-- Skype Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'skype_link' )); ?>"><?php esc_html_e('Skype','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'skype_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'skype_num' )); ?>" type="text" value="<?php if($skype_num) echo esc_attr( $skype_num ); ?>" placeholder="<?php echo esc_attr( '75k' );?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'skype_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'skype_follow' )); ?>" type="text" value="<?php if($skype_follow) echo esc_attr( $skype_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'skype_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'skype_link' )); ?>" type="text" value="<?php if($skype_link) echo esc_attr( $skype_link );?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['skype_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'skype_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'skype_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'skype_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Vimeo Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'vimeo_link' )); ?>"><?php esc_html_e('Vimeo','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'vimeo_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'vimeo_num' )); ?>" type="text" value="<?php if($vimeo_num) echo esc_attr( $vimeo_num ); ?>" placeholder="<?php echo esc_attr( '85k' );?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'vimeo_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'vimeo_follow' )); ?>" type="text" value="<?php if($vimeo_follow) echo esc_attr( $vimeo_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'vimeo_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'vimeo_link' )); ?>" type="text" value="<?php if($vimeo_link) echo esc_attr( $vimeo_link ); ?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['vimeo_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'vimeo_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'vimeo_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'vimeo_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>

			<!-- Dribble Link -->
			<h4 for="<?php echo esc_attr($this->get_field_id( 'dribble_link' )); ?>"><?php esc_html_e('Dribble','newscrunch'); ?></h4>
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'dribble_num' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'dribble_num' )); ?>" type="text" value="<?php if($dribble_num) echo esc_attr( $dribble_num ); ?>" placeholder="<?php echo esc_attr( '5k' );?>" />
			<input class="widefat newscrunch-widget-social" id="<?php echo esc_attr($this->get_field_id( 'dribble_follow' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'dribble_follow' )); ?>" type="text" value="<?php if($dribble_follow) echo esc_attr( $dribble_follow ); ?>" placeholder="<?php echo esc_attr__( 'Followers','newscrunch' ); ?>"/>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'dribble_link' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'dribble_link' )); ?>" type="text" value="<?php if($dribble_link) echo esc_attr( $dribble_link );?>" />
			<input class="checkbox" type="checkbox" <?php if($instance['dribble_target']==true){ echo 'checked'; } ?> id="<?php echo esc_attr($this->get_field_id( 'dribble_target' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'dribble_target' )); ?>" />
			<label for="<?php echo esc_attr($this->get_field_id( 'dribble_target' )); ?>"><?php esc_html_e( 'Open link in a new tab','newscrunch' ); ?></label>
		<?php
	}

	// Updating widget replacing old instances with new
	public function update( $new_instance, $old_instance ) 
	{
		$instance = $old_instance;
		$instance['title'] 	= ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['facebook_link'] = ( ! empty( $new_instance['facebook_link'] ) ) ? esc_url_raw( $new_instance['facebook_link'] ) : '';
		$instance['facebook_target'] = ( ! empty( $new_instance['facebook_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['facebook_target'] ) : '';
		$instance['facebook_num'] = ( ! empty( $new_instance['facebook_num'] ) ) ? sanitize_text_field( $new_instance['facebook_num'] ) : '';
		$instance['facebook_follow'] = ( ! empty( $new_instance['facebook_follow'] ) ) ? sanitize_text_field( $new_instance['facebook_follow'] ) : '';

		$instance['twitter_link'] = ( ! empty( $new_instance['twitter_link'] ) ) ? esc_url_raw( $new_instance['twitter_link'] ) : '';
		$instance['twitter_target'] = ( ! empty( $new_instance['twitter_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['twitter_target'] ) : '';
		$instance['twitter_num'] = ( ! empty( $new_instance['twitter_num'] ) ) ? sanitize_text_field( $new_instance['twitter_num'] ) : '';
		$instance['twitter_follow'] = ( ! empty( $new_instance['twitter_follow'] ) ) ? sanitize_text_field( $new_instance['twitter_follow'] ) : '';

		$instance['linkedin_link'] = ( ! empty( $new_instance['linkedin_link'] ) ) ? esc_url_raw( $new_instance['linkedin_link'] ) : '';
		$instance['linkedin_target'] = ( ! empty( $new_instance['linkedin_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['linkedin_target'] ) : '';
		$instance['linkedin_num'] = ( ! empty( $new_instance['linkedin_num'] ) ) ? sanitize_text_field( $new_instance['linkedin_num'] ) : '';
		$instance['linkedin_follow'] = ( ! empty( $new_instance['linkedin_follow'] ) ) ? sanitize_text_field( $new_instance['linkedin_follow'] ) : '';

		$instance['instagram_link'] = ( ! empty( $new_instance['instagram_link'] ) ) ? esc_url_raw( $new_instance['instagram_link'] ) : '';
		$instance['instagram_target'] = ( ! empty( $new_instance['instagram_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['instagram_target'] ) : '';
		$instance['instagram_num'] = ( ! empty( $new_instance['instagram_num'] ) ) ? sanitize_text_field( $new_instance['instagram_num'] ) : '';
		$instance['instagram_follow'] = ( ! empty( $new_instance['instagram_follow'] ) ) ? sanitize_text_field( $new_instance['instagram_follow'] ) : '';

		$instance['youtube_link'] = ( ! empty( $new_instance['youtube_link'] ) ) ? esc_url_raw( $new_instance['youtube_link'] ) : '';
		$instance['youtube_target'] = ( ! empty( $new_instance['youtube_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['youtube_target'] ) : '';
		$instance['youtube_num'] = ( ! empty( $new_instance['youtube_num'] ) ) ? sanitize_text_field( $new_instance['youtube_num'] ) : '';
		$instance['youtube_follow'] = ( ! empty( $new_instance['youtube_follow'] ) ) ? sanitize_text_field( $new_instance['youtube_follow'] ) : '';

		$instance['skype_link'] = ( ! empty( $new_instance['skype_link'] ) ) ? esc_url_raw( $new_instance['skype_link'] ) : '';
		$instance['skype_target'] = ( ! empty( $new_instance['skype_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['skype_target'] ) : '';
		$instance['skype_num'] = ( ! empty( $new_instance['skype_num'] ) ) ? sanitize_text_field( $new_instance['skype_num'] ) : '';
		$instance['skype_follow'] = ( ! empty( $new_instance['skype_follow'] ) ) ? sanitize_text_field( $new_instance['skype_follow'] ) : '';

		$instance['vimeo_link'] = ( ! empty( $new_instance['vimeo_link'] ) ) ? esc_url_raw( $new_instance['vimeo_link'] ) : '';
		$instance['vimeo_target'] = ( ! empty( $new_instance['vimeo_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['vimeo_target'] ) : '';
		$instance['vimeo_num'] = ( ! empty( $new_instance['vimeo_num'] ) ) ? sanitize_text_field( $new_instance['vimeo_num'] ) : '';
		$instance['vimeo_follow'] = ( ! empty( $new_instance['vimeo_follow'] ) ) ? sanitize_text_field( $new_instance['vimeo_follow'] ) : '';

		$instance['dribble_link'] = ( ! empty( $new_instance['dribble_link'] ) ) ? esc_url_raw( $new_instance['dribble_link'] ) : '';
		$instance['dribble_target'] = ( ! empty( $new_instance['dribble_target'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['dribble_target'] ) : '';
		$instance['dribble_num'] = ( ! empty( $new_instance['dribble_num'] ) ) ? sanitize_text_field( $new_instance['dribble_num'] ) : '';
		$instance['dribble_follow'] = ( ! empty( $new_instance['dribble_follow'] ) ) ? sanitize_text_field( $new_instance['dribble_follow'] ) : '';

		return $instance;
	}
}