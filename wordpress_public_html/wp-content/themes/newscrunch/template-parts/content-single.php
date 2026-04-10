<?php
if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() )
{
    $newscrunch_sp_sort=get_theme_mod( 'single_post_sort', array('reorder_sp_img', 'reorder_sp_meta', 'reorder_sp_title', 'reorder_sp_content', 'reorder_sp_tag') );
}
else
{
    $newscrunch_sp_sort=get_theme_mod( 'single_post_sort', array('reorder_sp_meta', 'reorder_sp_title', 'reorder_sp_img', 'reorder_sp_content', 'reorder_sp_tag') );
}?>
<article data-wow-delay=".8s" itemscope itemtype="https://schema.org/Article" id="post-<?php the_ID(); ?>" <?php post_class('spnc-post wow-callback zoomIn')?> >
	<?php
	if((get_theme_mod('ad_type','banner')=='banner')): do_action('newscrunch_before_post_content_ads','before post content'); endif;
	if ( ! empty( $newscrunch_sp_sort ) && is_array( $newscrunch_sp_sort ) ) :
		foreach ( $newscrunch_sp_sort as $newscrunch_sp_sort_key => $newscrunch_sp_sort_val ) :
			if(get_theme_mod('newscrunch_enable_reorder_sp_img',true)==true):
				if ( 'reorder_sp_img' === $newscrunch_sp_sort_val ) : 
				
					if(get_theme_mod('newscrunch_single_post_layout','1') == '1'):?>
						<div class="spnc-post-overlay">
							<?php 
							if(has_post_thumbnail()): ?>
								<figure class="spnc-post-thumbnail">
								<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'loading' => false, 'itemprop'=>'image' )); ?>
								</figure>
							<?php endif; ?>
						</div>
					<?php endif;
				endif;
			endif;?>
		    <div class="spnc-post-content">
		    	<?php
		    	if(get_theme_mod('newscrunch_enable_reorder_sp_meta',true)==true):
					if ( 'reorder_sp_meta' === $newscrunch_sp_sort_val ) : ?>
				        <div class="spnc-entry-meta">
				        	<!-- Post Category -->
				        	<?php
				        	if(get_theme_mod('newscrunch_enable_single_post_categories',true)==true):
					        	if ( has_category() ) :
									echo '<span itemprop="about" class="spnc-cat-links">';
									 newscrunch_get_categories(get_the_ID());
									echo '</span>';
								endif; 
							endif; ?>
							<!-- Post Tag -->
							<?php
				        	if(get_theme_mod('newscrunch_enable_single_post_tag',true)==true):
								if(has_tag()):
									echo '<span class="spnc-tag-links"><i class="fas fa fa-tags"></i>';
								 	the_tags('',', ');
								 	echo'</span>';	
							 	endif;
							endif; ?> 			
				    		<!-- Post Author -->
				    		<?php
							if ( get_theme_mod('newscrunch_enable_single_post_author',true) == true ) :?>
								<span itemprop="author" class="spnc-author">
								<i class="fas fa-solid fa-user"></i>
									<a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
					                <?php echo esc_html(get_the_author()); ?></a>
					            </span>				            
							<?php endif; ?>
				      		<!-- Post Date -->
				    		<?php
							if ( get_theme_mod('newscrunch_enable_single_post_date',true) == true ) :?>
					            <span class="single spnc-date">	
					            	<i class="fas fa-solid fa-clock"></i>
									<?php echo newcrunch_post_date_time(get_the_ID()); ?>
								</span>
							<?php endif; ?>
							<!-- Post Comments -->
							<?php
							if ( get_theme_mod('newscrunch_enable_single_post_comment',true) == true ) :?>
							<span class="spnc-comment-links"> 
								<i class="fas fa-comment-alt"></i>                  
		                        <a itemprop="url" href="<?php the_permalink(); ?>#respond" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>"><?php echo esc_html(get_comments_number()) . ' ' . esc_html(get_theme_mod('newscrunch_blog_archive_comments',__('Comments','newscrunch'))); ?></a>
		                    </span>
		                    <?php endif; ?>
				    		<?php if ( class_exists('Newscrunch_Plus') ): do_action( 'spncp_post_count_hook' ); endif; ?>
				        </div>
				    <?php endif;
				endif; 
				if(get_theme_mod('newscrunch_enable_reorder_sp_title',true)==true):
					if ( 'reorder_sp_title' === $newscrunch_sp_sort_val ) :?>        
				        <header class="entry-header">
				        	<?php
				        	if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
				        	 $newscrunch_post_title_markup= esc_html(get_theme_mod('single_post_title_markup', 'h2')); 
				        	} else {
				        	 $newscrunch_post_title_markup= esc_html(get_theme_mod('single_post_title_markup', 'h1')); 
				        	}

				        	$newscrunch_post_title_markup_before='<' . $newscrunch_post_title_markup . ' itemprop="name" class="spnc-entry-title">';
		  					$newscrunch_post_title_markup_after='</' . $newscrunch_post_title_markup . '>';

							echo wp_kses_post($newscrunch_post_title_markup_before);
								 the_title();
						    echo wp_kses_post($newscrunch_post_title_markup_after);
						    ?>                                               
						</header>
						<?php endif;
				endif;
				if(get_theme_mod('newscrunch_enable_reorder_sp_content',true)==true):
					if ( 'reorder_sp_content' === $newscrunch_sp_sort_val ) :?>
				        <div itemprop="articleBody" class="spnc-entry-content">
				        	<?php if(get_post_format() === 'quote'): ?>
				        	<div class="spnc-quote-wrap">
	                            <i class="fa fa-quote-left" aria-hidden="true"></i>
	                        <?php endif; ?>
				            <?php the_content();
				            	wp_link_pages();
				            	//video post format
				            	if(get_post_format() === 'video'):
				            		$newscrunch_video_url = get_post_meta( get_the_ID(), 'newscrunch_post_video_embed', true );
				            		if(newscrunch_is_youtube_url($newscrunch_video_url) === 'youtube')
				            		{
				            			$newscrunch_videoId = 	newscrunch_get_youtube_videoId($newscrunch_video_url);
				            			if(!empty($newscrunch_videoId)):
				            				$youtube_url = esc_url( 'https://www.youtube.com/embed/' . esc_attr( $newscrunch_videoId ) );
											echo wp_oembed_get( $youtube_url, array( 'width' => '100%', 'height' => '600' ) );
				            			endif;
				            		}
				            		else
				            		{
				            			$newscrunch_videoId = 	newscrunch_get_vimeoid($newscrunch_video_url);
				            			if(!empty($newscrunch_videoId)):
				            				$vimeo_url = esc_url( 'https://www.vimeo.com/embed/' . esc_attr( $newscrunch_videoId ) );
											echo wp_oembed_get( $vimeo_url, array( 'width' => '100%', 'height' => '600' ) );
				            			endif;
				            		}
				            	endif;	
				            	?>
				            	<?php if(get_post_format() === 'quote'): ?>
						    </div>
						<?php endif;?>
				        </div>
				        <?php endif;
				endif; ?>
		    </div>
		    <?php
		    if(get_theme_mod('newscrunch_enable_reorder_sp_tag',true)==true):
				if ( 'reorder_sp_tag' === $newscrunch_sp_sort_val ) :
				    if(get_theme_mod('newscrunch_enable_single_post_tag',true)==true):
						if(has_tag()): ?>
							<div class="spnc-post-footer-content">
								<div class="spnc-footer-meta spnc-entry-meta">
									<span class="spnc-text-black"><?php echo esc_html(get_theme_mod('newscrunch_tag_text',__('Tag','newscrunch'))); ?></span>
									<span class="spnc-tag-links"><?php  the_tags('',' ');?></span>
								</div>
							</div>
						<?php endif;
					endif;
				endif;
			endif;
		endforeach;
	endif;
	if((get_theme_mod('ad_type','banner')=='banner')): do_action('newscrunch_after_post_content_ads','after post content'); endif;
	newscrunch_edit_link_button();
	?>	
</article>