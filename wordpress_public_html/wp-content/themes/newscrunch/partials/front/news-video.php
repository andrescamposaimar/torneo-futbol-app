<?php 
$newscrunch_hide_show_featured_video = get_theme_mod('hide_show_featured_video',false) ;
$newscrunch_hide_show_featured_video_meta = get_theme_mod('hide_show_featured_video_meta',true);
$newscrunch_video_url_id='';
for ($i=1; $i <=5 ; $i++) { 
	$newscrunch_video_url_id.= str_replace("","",get_theme_mod('featured_video_url_'.$i)). ',';
	$newscrunch_array_video_url = explode (",", $newscrunch_video_url_id);
	array_pop($newscrunch_array_video_url);
}

if($newscrunch_hide_show_featured_video == true) {

	$newscrunch_featured_video_category 	= get_theme_mod('featured_video_dropdown_category',0);
	global $post;
	$newscrunch_featured_video_id='';
	$query_args = array( 'category__in'  => $newscrunch_featured_video_category, 'posts_per_page' => 5, 'order' => 'DESC', 'ignore_sticky_posts' => 1);
    $newscrunch_featured_video_arg = new WP_Query($query_args);
    if ( $newscrunch_featured_video_arg->have_posts() ) 
    {			
		$i=0;
        while ( $newscrunch_featured_video_arg->have_posts() ) 
        {	
    		$newscrunch_featured_video_arg->the_post(); 
            $newscrunch_featured_video_id.= get_the_ID() . ',';
            $array = explode (",", $newscrunch_featured_video_id);
            array_pop($array);
        }
?>
<!-- Video section -->
<section class="spnc-page-section-space spnc-video video-layout-1">
	<div class="spnc-container <?php esc_attr_e(get_theme_mod('featured_video_section_width','default'));?>">
		<div class="spnc-video-row <?php if( class_exists('Newscrunch_Plus') && (!empty(get_theme_mod('video_background_img')))):?>video-img<?php endif;?>">
		<?php if(get_theme_mod('video_img_overlay',true)== true):?><div class="news-video-overlay"></div><?php endif;
		$newscrunch_fvideo_title = get_theme_mod('featured_video_title', __('Featured Video', 'newscrunch' ));
		if(!empty($newscrunch_fvideo_title)): ?>
			<div data-wow-delay=".5s" class="wow-callback bounce spnc-blog-1-wrapper">
				<div class="spnc-blog-1-heading">
				    <h4><?php echo esc_html($newscrunch_fvideo_title); ?></h4>
				</div>
		    </div>
		<?php endif;
		if ( class_exists('Newscrunch_Plus') ) {
			if(!empty(get_theme_mod('video_view_btn_title',''))): ?>
            	<a target="<?php if(get_theme_mod('video_view_btn_redirect',false)==true) { echo "_blank" ;} else { echo "_self"; } ?>" href="<?php echo esc_url(get_theme_mod('video_view_btn_url','#'));?>" class="spncp-view-btn-link"><?php echo esc_html(get_theme_mod('video_view_btn_title', __('View All','newscrunch')));?></a>
            <?php endif;
		} ?>
		<div class="spnc-container-box">
			<ul data-wow-delay=".5s" class="wow-callback slideInLeft spnc_column spnc_column-1">
				<?php
				//thumbnail img url
				$newscrunch_featured_video_img_url = get_the_post_thumbnail_url($array[$i],'full');
				//vimeo/youtube url from metabox ( old user )
				$newscrunch_featured_video_url = get_post_meta( $array[$i], 'newscrunch_post_video_embed', true );
				//vimeo/youtube url from post content (new user @since 1.6.6)
				$newscrunch_content_featured_video_url=newscrunch_get_first_video_url($array[$i]);

				//@since 1.6.6
				if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
				{
					if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
					{
						$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_content_featured_video_url) ;
						$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
					}
					else
					{
						$newscrunch_vimeo_videoId=newscrunch_embed_videoId($newscrunch_content_featured_video_url);
						$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
					}
				}
				//old user
				else if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
				{
					if(newscrunch_is_youtube_url($newscrunch_featured_video_url)=="youtube")
					{
						$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_featured_video_url) ;
						$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
					}
					else
					{
						$newscrunch_vimeo_videoId=newscrunch_get_vimeo_videoId($newscrunch_featured_video_url);
						$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
					}
				}

				//icon url @since 1.6.6
				if(!empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
				{
					if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
					{
						$newscrunch_icon_url=newscrunch_yconvertEmbedUrlToStandardUrl($newscrunch_content_featured_video_url);
					}
					else
					{
						$newscrunch_icon_url=$newscrunch_content_featured_video_url;
					}
				}
            	?>
				<li class="spnc_grid_item spnc_grid_item-1">
					<div class="spnc_item">
						<article class="spnc-post">
							<div class="spnc-post-overlay"></div>
							<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>"
								style="background-image:url(<?php echo esc_url($newscrunch_featured_video_img_url) ?>);">
								<div class="spnc-post-content">
									<div class="spnc-content-wrapper">
										<div class="spnc-post-wrapper">
											<div class="spnc-video-popup">
												<?php
												if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_content_featured_video_url))) { ?>
													<a href="<?php echo esc_url($newscrunch_icon_url);?>" class="spncOpenVideo popup-youtube1" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
												<?php }
												else if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_featured_video_url))) { ?>
													<a href="<?php if(str_contains($newscrunch_featured_video_url, '&')) { echo esc_url(strstr($newscrunch_featured_video_url, '&', true)); } else { echo esc_url($newscrunch_featured_video_url); } ?>" class="spncOpenVideo popup-youtube1" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
												<?php } 
												else
												{ 
													//old user compatibility
													if($newscrunch_array_video_url[$i] != ''): ?>
													<a href="<?php if(str_contains($newscrunch_array_video_url[$i], '&')) { echo esc_url(strstr($newscrunch_array_video_url[$i], '&', true)); } else { echo esc_url($newscrunch_array_video_url[$i]); } ?>" class="spncOpenVideo popup-youtube1" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
												<?php endif; 
												} ?>
	                                        </div>
											<header class="entry-header">
												<?php if($newscrunch_hide_show_featured_video_meta == true) { ?>
													<div class="spnc-entry-meta">
														<span class="spnc-cat-links">
															<?php newscrunch_get_categories(get_the_ID($newscrunch_featured_video_arg->the_post())); ?>
														</span>
													</div>
												<?php } ?>
												<h3 class="spnc-entry-title">
													<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php echo esc_url(get_permalink($array[$i])); ?>" title="<?php echo esc_attr(get_the_title($array[$i]));?>"><?php echo esc_html(get_the_title($array[$i]));?></a>
												</h3>
											</header>
											<?php if($newscrunch_hide_show_featured_video_meta == true) { ?>
												<div class="spnc-entry-content">
													<div class="spnc-entry-meta">
														<span class="author"><i class="fas fa-user"></i><a <?php if (is_rtl()) { echo 'dir="rtl"'; } ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>"><?php echo esc_html(get_the_author()); ?></a>
							                			</span>
							                			<span class="date"> 
							                				<i class="fa fa-solid fa-clock"></i>
							                				<?php echo newcrunch_post_date_time(get_the_ID()); ?>
														</span>
														<?php if ( class_exists('Newscrunch_Plus') ):
                                                			if(get_theme_mod('reading_time_enable',false) === true):?>
                                                				<span class="spnc-read-time"><i class="fa fa-eye"></i><?php spncp_reading_time();?></span>
                                            			<?php endif; endif;?>
													</div>
												</div>
											<?php } ?>
										</div>
									</div>
								</div>
							</div>
						</article>
					</div>
				</li>
			</ul>
			<ul data-wow-delay=".5s" class="wow-callback slideInDown spnc_column spnc_column-2">
				<?php
				for ( $i = 1; $i <= 2; $i++ ) :
					if (array_key_exists($i, $array)):
						//thumbnail img url
						$newscrunch_featured_video_img_url = get_the_post_thumbnail_url($array[$i],'full');
						//vimeo/youtube url from metabox ( old user )
						$newscrunch_featured_video_url = get_post_meta( $array[$i], 'newscrunch_post_video_embed', true );
						//vimeo/youtube url from post content (new user @since 1.6.6)
						$newscrunch_content_featured_video_url=newscrunch_get_first_video_url($array[$i]);
						
						//@since 1.6.6
						if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
							{
								$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_content_featured_video_url) ;
								$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
							}
							else
							{
								$newscrunch_vimeo_videoId=newscrunch_embed_videoId($newscrunch_content_featured_video_url);
								$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
							}
						}
						//old user
						else if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_featured_video_url)=="youtube")
							{
								$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_featured_video_url) ;
								$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
							}
							else
							{
								$newscrunch_vimeo_videoId=newscrunch_get_vimeo_videoId($newscrunch_featured_video_url);
								$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
							}
						}

						//icon url @since 1.6.6
						if(!empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
							{
								$newscrunch_icon_url=newscrunch_yconvertEmbedUrlToStandardUrl($newscrunch_content_featured_video_url);
							}
							else
							{
								$newscrunch_icon_url=$newscrunch_content_featured_video_url;
							}
						}
		            	?>
						<li class="spnc_grid_item spnc_grid_item-1">
							<div class="spnc_item">
								<article class="spnc-post">
									<div class="spnc-post-overlay"></div>
									<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>"
										style="background-image:url(<?php echo esc_url($newscrunch_featured_video_img_url) ?>);">
										<div class="spnc-post-content">
											<div class="spnc-content-wrapper">
												<div class="spnc-post-wrapper">
													<div class="spnc-video-popup">
													<?php 
													if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_content_featured_video_url))){ ?>
														<a href="<?php echo esc_url($newscrunch_icon_url);?>" class="spncOpenVideo popup-youtube1" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
													<?php }
													else if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_featured_video_url))) { ?>
														<a href="<?php if(str_contains($newscrunch_featured_video_url, '&')) { echo esc_url(strstr($newscrunch_featured_video_url, '&', true)); } else { echo esc_url($newscrunch_featured_video_url); } ?>" class="spncOpenVideo popup-youtube2" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
													<?php }
													else 
													{ if($newscrunch_array_video_url[$i] != ''): ?>
														<a href="<?php if(str_contains($newscrunch_array_video_url[$i], '&')) { echo esc_url(strstr($newscrunch_array_video_url[$i], '&', true)); } else { echo esc_url($newscrunch_array_video_url[$i]); } ?>" class="spncOpenVideo popup-youtube2" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
													<?php endif; 
												    } ?>
	                                              	</div>
													<header class="entry-header">
														<?php if($newscrunch_hide_show_featured_video_meta == true) { ?>
															<div class="spnc-entry-meta">
																<span class="spnc-cat-links">
																	<?php newscrunch_get_categories(get_the_ID($newscrunch_featured_video_arg->the_post())); ?>
																</span>
															</div>
														<?php } ?>
														<h3 class="spnc-entry-title">
															<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php echo esc_url(get_permalink($array[$i])); ?>" title="<?php echo esc_attr(get_the_title($array[$i]));?>"><?php echo esc_html(get_the_title($array[$i]));?></a>
														</h3>
													</header>
												</div>
											</div>
										</div>
									</div>
								</article>
							</div>
						</li>
					<?php endif; 
				endfor;?>
			</ul>
			<ul data-wow-delay=".5s" class="wow-callback slideInRight spnc_column spnc_column-3">
				<?php
				for ( $i = 3; $i <= 4; $i++ ) :
					if (array_key_exists($i, $array)):
						//thumbnail img url
						$newscrunch_featured_video_img_url = get_the_post_thumbnail_url($array[$i],'full');
						//vimeo/youtube url from metabox ( old user )
						$newscrunch_featured_video_url = get_post_meta( $array[$i], 'newscrunch_post_video_embed', true );
						//vimeo/youtube url from post content (new user @since 1.6.6)
						$newscrunch_content_featured_video_url=newscrunch_get_first_video_url($array[$i]);

						//@since 1.6.6
						if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
							{
								$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_content_featured_video_url) ;
								$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
							}
							else
							{
								$newscrunch_vimeo_videoId=newscrunch_embed_videoId($newscrunch_content_featured_video_url);
								$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
							}
						}
						//old user
						else if(empty($newscrunch_featured_video_img_url) && !empty($newscrunch_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_featured_video_url)=="youtube")
							{
								$newscrunch_vid=newscrunch_get_youtube_videoId($newscrunch_featured_video_url) ;
								$newscrunch_featured_video_img_url='https://img.youtube.com/vi/'.$newscrunch_vid.'/sddefault.jpg';
							}
							else
							{
								$newscrunch_vimeo_videoId=newscrunch_get_vimeo_videoId($newscrunch_featured_video_url);
								$newscrunch_featured_video_img_url='https://vumbnail.com/'.$newscrunch_vimeo_videoId.'_max.jpg';
							}
						}

						//icon url @since 1.6.6
						if(!empty($newscrunch_content_featured_video_url ) && ( get_post_format($array[$i]) === 'video'))
						{
							if(newscrunch_is_youtube_url($newscrunch_content_featured_video_url)=="youtube")
							{
								$newscrunch_icon_url=newscrunch_yconvertEmbedUrlToStandardUrl($newscrunch_content_featured_video_url);
							}
							else
							{
								$newscrunch_icon_url=$newscrunch_content_featured_video_url;
							}
						} ?>
						<li class="spnc_grid_item spnc_grid_item-1">
							<div class="spnc_item">
								<article class="spnc-post">
									<div class="spnc-post-overlay"></div>
									<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>"
										style="background-image:url(<?php echo esc_url($newscrunch_featured_video_img_url) ?>);">
										<div class="spnc-post-content">
											<div class="spnc-content-wrapper">
												<div class="spnc-post-wrapper">
													<div class="spnc-video-popup">
														<?php 
														if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_content_featured_video_url))) { ?>
															<a href="<?php echo esc_url($newscrunch_icon_url);?>" class="spncOpenVideo popup-youtube1" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
														<?php }
														else if(( get_post_format($array[$i]) === 'video') && (!empty($newscrunch_featured_video_url))) { ?>
															<a href="<?php if(str_contains($newscrunch_featured_video_url, '&')) { echo esc_url(strstr($newscrunch_featured_video_url, '&', true)); } else { echo esc_url($newscrunch_featured_video_url); } ?>" class="spncOpenVideo popup-youtube3" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
														<?php }
														else 
														{	if($newscrunch_array_video_url[$i] != ''): ?>
																<a href="<?php if(str_contains($newscrunch_array_video_url[$i], '&')) { echo esc_url(strstr($newscrunch_array_video_url[$i], '&', true)); } else { echo esc_url($newscrunch_array_video_url[$i]); } ?>" class="spncOpenVideo popup-youtube3" title="<?php esc_attr_e('Video Popup','newscrunch'); ?>"><i class="fas fa-solid fa-play"></i></a>
														<?php endif; 
														} ?>
		                                          	</div>
													<header class="entry-header">
														<?php if($newscrunch_hide_show_featured_video_meta == true) { ?>
															<div class="spnc-entry-meta">
																<span class="spnc-cat-links">
																	<?php newscrunch_get_categories(get_the_ID($newscrunch_featured_video_arg->the_post())); ?>
																</span>
															</div>
														<?php } ?>
														<h3 class="spnc-entry-title">
															<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php echo esc_url(get_permalink($array[$i])); ?>" title="<?php echo esc_attr(get_the_title($array[$i]));?>"><?php echo esc_html(get_the_title($array[$i]));?></a>
														</h3>
													</header>
												</div>
											</div>
										</div>
									</div>
								</article>
							</div>
						</li>
					<?php endif; 
				endfor;?>
			</ul>
		</div>
	    </div>
	</div>
</section>
<!-- Video section -->
<?php } } ?>