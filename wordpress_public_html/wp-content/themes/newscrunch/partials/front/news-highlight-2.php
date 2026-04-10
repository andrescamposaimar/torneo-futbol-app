<?php
if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
    $newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_banner', 'front_highlight', 'front_ad') );
}
else {
	$newscrunch_banner_sort=get_theme_mod( 'front_banner_highlight_sort', array('front_highlight', 'front_banner', 'front_ad') );
}

$newscrunch_news_higlight_title = get_theme_mod('news_highlight_title', esc_html__('Highlight', 'newscrunch' ) );

	$newscrunch_news_highlight_category 	= get_theme_mod('news_highlight_dropdown_category',0);
	$newscrunch_news_highlight_post_title	= get_theme_mod('news_highlight_dropdown_post_title',0);
	$newscrunch_news_highlight_num_posts	= get_theme_mod('news_highlight_num_posts',8);
	$newscrunch_highlight_date_cat_option	= get_theme_mod('newscrunch_highlight_date_cat_option', 'post_date');

	global $post;

	if((get_theme_mod('newscrunch_highlight_search_option','category')=='category')) {
		$args = array( 'category__in'  => $newscrunch_news_highlight_category, 'posts_per_page' =>$newscrunch_news_highlight_num_posts,'order' =>'DESC','ignore_sticky_posts' => 1);
	}
	if((get_theme_mod('newscrunch_highlight_search_option','category')=='title')) {
		$args = array( 'post__in'  => $newscrunch_news_highlight_post_title, 'order' => 'DESC','ignore_sticky_posts' => 1);
	}
	$the_query = new WP_Query($args); ?>

	<!-- Highlight section -->
	<section data-wow-delay=".1s" class="wow-callback slideInLeft spnc-highlights-1 <?php if($newscrunch_banner_sort[0]=='front_highlight'):?>front_highlight<?php endif; if(get_theme_mod('hide_show_banner',true)==false):?> banner-disable<?php endif;?>">
		<div class="spnc-container">
			<div class="spnc-row">
				<?php if(!empty($newscrunch_news_higlight_title)): ?>
					<div class="spnc-col-13">
						<div class="spnc-highlights-title">
							<h3><?php echo esc_html($newscrunch_news_higlight_title);?></h3>
						</div>	
					</div>
				<?php endif; ?>
				<div class="spnc-col-4">			
					<div class="spnc-marquee-wrapper">
			  			<div class="spnc_highlights" style="animation-duration: 57s;">
							<?php
				            if ( $the_query->have_posts() ) {
				                while ( $the_query->have_posts() ) 
				                {
				            		$the_query->the_post(); ?>
							   		<article class="spnc-post">
							            <h6 class="spnc-entry-title">
											<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
										</h6>
										<div class="spnc-entry-meta">
											<?php if($newscrunch_highlight_date_cat_option=="post_date") : ?>
												<span class="date">
											     	<?php echo newcrunch_post_date_time(get_the_ID()); ?>
												</span>
												<?php
												else : ?>
													<span class="date">
												     	<?php if ( has_category() ) :
															newscrunch_get_categories(get_the_ID());
														endif; ?>
													</span>
											<?php endif; ?>
										</div>
			                 		</article>
								<?php }
					        	wp_reset_query();
				    		} ?>
		      			</div>
		      			<div class="spnc_highlights" style="animation-duration: 57s;">
							<?php
				            if ( $the_query->have_posts() ) {
				                while ( $the_query->have_posts() ) 
				                {
				            		$the_query->the_post(); ?>
							   		<article class="spnc-post">
							            <h6 class="spnc-entry-title">
											<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
										</h6>
										<div class="spnc-entry-meta">
											<?php if($newscrunch_highlight_date_cat_option=="post_date") : ?>
												<span class="date"><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
												<?php
												else : ?>
													<span class="date">
												     	<?php if ( has_category() ) :
															newscrunch_get_categories(get_the_ID());
														endif; ?>
													</span>
											<?php endif; ?>
										</div>
			                 		</article>
								<?php }
					        	wp_reset_query();
				    		} ?>
		      			</div>	
		         	</div>
		      	</div>
	       </div>
		</div>				
	</section>