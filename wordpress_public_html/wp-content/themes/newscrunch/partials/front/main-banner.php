<?php
if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
    $newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_banner', 'front_highlight', 'front_ad') );
}
else {
    $newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_highlight', 'front_banner', 'front_ad') );
}
$newscrunch_hide_show_banner = get_theme_mod('hide_show_banner',true) ;
if($newscrunch_hide_show_banner == true) {
	if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
        $newscrunch_sort=get_theme_mod( 'banner_sort', array('reorder_left', 'reorder_center', 'reorder_right') );
    }
    else{
        $newscrunch_sort=get_theme_mod( 'banner_sort', array('reorder_left', 'reorder_right', 'reorder_center') );
    } 
?>

<!-- Banner section -->
<section class="front-banner spnc-page-section-space blog spnc-bnr-1 default <?php if($newscrunch_banner_sort[1]=='front_banner'):?>below_highlight<?php endif;?>">
	<div class="spnc_column_container">
		<?php 
		$i=1;
		if ( ! empty( $newscrunch_sort ) && is_array( $newscrunch_sort ) ) :
			foreach ( $newscrunch_sort as $newscrunch_sort_key => $newscrunch_sort_val ) :
				if(get_theme_mod('newscrunch_enable_reorder_left',true)==true):
		    		if ( 'reorder_left' === $newscrunch_sort_val ) : ?>
						<ul id="element<?php echo esc_attr($i);?>" data-wow-delay=".5s" class="wow-callback fadeInLeft spnc_column spnc_column-2">
							<?php 
							 $newscrunch_banner_left_category 	= get_theme_mod('banner_left_dropdown_category',0);
							 $newscrunch_banner_left_post_order = get_theme_mod('banner_left_post_order','DESC'); 
							 $newscrunch_hide_show_banner_left_meta = get_theme_mod('hide_show_banner_left_meta',true); 
						
							global $post;
							$query_args1 = array( 'category__in'  => $newscrunch_banner_left_category, 'posts_per_page' =>2,'order' => $newscrunch_banner_left_post_order,'ignore_sticky_posts' => 1);
							$the_query1 = new WP_Query($query_args1);
				            if ( $the_query1->have_posts() ) {
				                while ( $the_query1->have_posts() ) 
				                {
				            	$the_query1->the_post(); 
				            	  $newscrunch_featured_img_url = get_the_post_thumbnail_url(get_the_ID(),'full'); 
				            	?>
							<li class="spnc_grid_item spnc_grid_item-1">
								<div class="spnc_item">
									<article class="spnc-post">
										<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
											<a href="<?php the_permalink();?>" title="<?php the_title();?>">
												<?php if( function_exists( 'spncp_activate' )) { 
											if( (get_theme_mod('spncp_banner_left_overlay_options','custom')=='custom') &&  (get_theme_mod('spncp_banner_left_overlay',true)== true) || (get_theme_mod('spncp_banner_left_overlay_options','custom')=='gradient')) 
												{ echo '<div class="spnc-post-overlay"></div>';}
											}
											else { echo '<div class="spnc-post-overlay lite"></div>'; }?>
												<?php the_post_thumbnail('full'); ?>
											</a>
										</div>
										<div class="spnc-post-content">
												<div class="spnc-content-wrapper">
													<div class="spnc-post-wrapper">
														<header class="entry-header">
														<?php if($newscrunch_hide_show_banner_left_meta == true){?>
															<div class="spnc-entry-meta">
																<span class="spnc-cat-links">
																	<?php newscrunch_get_categories(get_the_ID()); ?>
																</span>
																<?php if(has_tag()): ?>
																<span class="tag-links">
																	<i class="fa fa-tags"></i>
																	<?php the_tags('',', ');?></span>
																<?php endif; ?>	
															</div>
														<?php } ?>
															<h3 class="spnc-entry-title">
				                                        		<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
				                                   			</h3>
														</header>
														<?php if($newscrunch_hide_show_banner_left_meta == true){?>
														<div class="spnc-entry-content">
															<div class="spnc-entry-meta">
																<span class="author"><i class="fas fa-user"></i><a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
								                				<?php echo esc_html(get_the_author()); ?></a></span>
																<span class="date"> <i class="fa fa-solid fa-clock"></i><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
															</div>
														</div>
														<?php } ?>
													</div>
												</div>
											</div>
									</article>
								</div>
							</li>
							<?php 
							}
				        wp_reset_query();
				    	} ?>
						</ul>
						<?php 
					endif; 
				endif; 
				if(get_theme_mod('newscrunch_enable_reorder_center',true)==true):
		    		if ( 'reorder_center' === $newscrunch_sort_val ) : ?>
						<ul id="element<?php echo esc_attr($i);?>" data-wow-delay=".5s" class="wow-callback zoomIn spnc_column spnc_column-1">
							<li class="spnc_grid_item spnc_grid_item-1">
								<div id="spnc<?php if( function_exists( 'spncp_activate' )): echo'p'; endif;?>-banner-carousel-1" class="owl-carousel spnc-blog1-carousel">
									<?php
									$newscrunch_banner_center_dropdown_category = get_theme_mod('banner_center_dropdown_category',1);
									$newscrunch_banner_center_num_posts 	  = get_theme_mod('banner_center_num_posts',3);
									$newscrunch_banner_center_post_order     = get_theme_mod('banner_center_post_order','DESC'); 
									$newscrunch_hide_show_banner_center_meta = get_theme_mod('hide_show_banner_center_meta',true); 

									global $post;

									$query_args2 = array( 'cat'  => $newscrunch_banner_center_dropdown_category, 'posts_per_page' =>$newscrunch_banner_center_num_posts,'order' => $newscrunch_banner_center_post_order,'ignore_sticky_posts' => 1);
									$the_query2 = new WP_Query($query_args2);

						            if ( $the_query2->have_posts() ) {
						                while ( $the_query2->have_posts() ) 
						                {
						            	$the_query2->the_post(); 
						            	  $newscrunch_featured_img_url = get_the_post_thumbnail_url(get_the_ID(),'full'); 
						            	?>
											<div class="spnc_item">
												<article class="spnc-post">
													<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
														<a href="<?php the_permalink();?>" title="<?php the_title();?>">
																<?php if( function_exists( 'spncp_activate' )) { 
														if( (get_theme_mod('spncp_banner_center_overlay_options','custom')=='custom') &&  (get_theme_mod('spncp_slider_overlay',true)== true) || (get_theme_mod('spncp_banner_center_overlay_options','custom')=='gradient')) 
															{ echo '<div class="spnc-post-overlay"></div>';}
														}
														else { echo '<div class="spnc-post-overlay lite"></div>'; } ?>
														<?php the_post_thumbnail('full'); ?>
														</a>
													</div>
													<div class="spnc-post-content">
															<div class="spnc-content-wrapper">
																<div class="spnc-post-wrapper">
																	<header class="entry-header">
																		<?php if($newscrunch_hide_show_banner_center_meta == true){?>
																			<div class="spnc-entry-meta">
																				<span class="spnc-cat-links">
																					<?php newscrunch_get_categories(get_the_ID()); ?>
																				</span>
																				<?php if(has_tag()): ?>
																				<span class="tag-links">
																					<i class="fa fa-tags"></i>
																					<?php the_tags('',', ');?></span>
																				<?php endif; ?>	
																			</div>
																		<?php } ?>
																		<h3 class="spnc-entry-title">
							                                        		<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
							                                   			</h3>
							                                   			<?php if( get_theme_mod('hide_show_banner_center_read_more',true) == true): 
																			$read_btn=get_theme_mod('spncp_banner_center_read_btn',__('Read More','newscrunch')); 

																		?>
																		<a href="<?php the_permalink();?>" class="spnc-info-link" title="<?php echo esc_attr($read_btn); ?>"><?php echo esc_html($read_btn); ?></a>
																	<?php
																	endif; ?>
																	</header>
																	<?php 
																	if($newscrunch_hide_show_banner_center_meta == true) { ?>
																		<div class="spnc-entry-content">
																			<div class="spnc-entry-meta">
																				<span class="author"><i class="fas fa-user"></i><a <?php if (is_rtl()) { echo 'dir="rtl"'; } ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
												                				<?php echo esc_html(get_the_author()); ?></a></span>
																				<span class="date"> <i class="fa fa-solid fa-clock"></i><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
																			</div>
																		</div>
																	<?php } ?>
																</div>
															</div>
														</div>
												</article>
											</div>
										<?php 
										}
						        		wp_reset_query();
						    		} ?>
				    			</div>
							</li>
						</ul>
						<?php 
					endif; 
				endif;
				if(get_theme_mod('newscrunch_enable_reorder_right',true)==true):
		    		if ( 'reorder_right' === $newscrunch_sort_val ) : ?> 
						<ul id="element<?php echo esc_attr($i);?>" data-wow-delay=".5s" class="wow-callback fadeInRight spnc_column spnc_column-3">
							<?php 
							 $newscrunch_banner_right_category 	  = get_theme_mod('banner_right_dropdown_category',0);
							 $newscrunch_banner_right_post_order     = get_theme_mod('banner_right_post_order','ASC'); 
							 $newscrunch_hide_show_banner_right_meta = get_theme_mod('hide_show_banner_right_meta',true); 
						
							global $post;

							$query_args3 = array( 'category__in'  => $newscrunch_banner_right_category, 'posts_per_page' =>2,'order' => $newscrunch_banner_right_post_order,'ignore_sticky_posts' => 1);
							$the_query3 = new WP_Query($query_args3);
				            if ( $the_query3->have_posts() ) {
				                while ( $the_query3->have_posts() ) 
				                {
				            	$the_query3->the_post(); 
				            	  $newscrunch_featured_img_url = get_the_post_thumbnail_url(get_the_ID(),'full'); 
				            	?>
							<li class="spnc_grid_item spnc_grid_item-1">
								<div class="spnc_item">
									<article class="spnc-post">			
										<div class="spnc-post-img <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>" >
											<a href="<?php the_permalink();?>" title="<?php the_title();?>">
												<?php if( function_exists( 'spncp_activate' )) { 
											if( (get_theme_mod('spncp_banner_right_overlay_options','custom')=='custom') &&  (get_theme_mod('spncp_banner_right_overlay',true)== true) || (get_theme_mod('spncp_banner_right_overlay_options','custom')=='gradient')) 
												{ echo '<div class="spnc-post-overlay"></div>';}
											}
											else { echo '<div class="spnc-post-overlay lite"></div>'; }?>
												<?php the_post_thumbnail('full'); ?>
											</a>
										</div>
										<div class="spnc-post-content">
												<div class="spnc-content-wrapper">
													<div class="spnc-post-wrapper">
														<header class="entry-header">
														<?php if($newscrunch_hide_show_banner_right_meta == true){?>
															<div class="spnc-entry-meta">
																<span class="spnc-cat-links">
																	<?php newscrunch_get_categories(get_the_ID()); ?>
																</span>
																<?php if(has_tag()): ?>
																<span class="tag-links">
																	<i class="fa fa-tags"></i>
																	<?php the_tags('',', ');?></span>
																<?php endif; ?>	
															</div>
														<?php } ?>
															<h3 class="spnc-entry-title">
				                                        		<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
				                                   			</h3>
														</header>
														<?php if($newscrunch_hide_show_banner_right_meta == true){?>
														<div class="spnc-entry-content">
															<div class="spnc-entry-meta">
																<span class="author"><i class="fas fa-user"></i><a <?php if (is_rtl()) { echo 'dir="rtl"'; } ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
								                				<?php echo esc_html(get_the_author()); ?></a></span>
																<span class="date"> <i class="fa fa-solid fa-clock"></i><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
															</div>
														</div>
														<?php } ?>
													</div>
												</div>
											</div>
									</article>
								</div>
							</li>
							<?php 
							}
				        wp_reset_query();
					    } ?>
						</ul>
						<?php 
				 endif; endif;
				 $i++;
			endforeach;
		endif;?>
	</div>
</section>
<!-- Banner section -->
<?php } ?>