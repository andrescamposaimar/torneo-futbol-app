<?php
/**
 * Template part for displaying blog template content
 *
 * @package Newscrunch Theme
 */
?>  
<article data-wow-delay=".8s" itemscope itemtype="https://schema.org/Article" id="post-<?php the_ID(); ?>" <?php post_class('spnc-grid-catpost spnc-post wow-callback zoomIn '); ?> >
	<div class="spnc-post-overlay"></div>
	<div class="spnc-post-format-wrapper">
    <?php
    if(has_post_format('gallery')) { ?>    
    <a href="<?php the_permalink(); ?>" itemprop="url" title="<?php the_title(); ?>">    
        <span class="spnc-post-btn">
            <i class="fa-regular fa-images"></i>
        </span>
    </a>
    <?php } elseif (has_post_format('link')) { ?>
 	<a href="<?php the_permalink(); ?>" itemprop="url" title="<?php the_title(); ?>">  
     	<span class="spnc-post-btn">
            <i class="fa-solid fa-link"></i>
        </span>
    </a>
    <?php     
     } 
	newscrunch_post_formats();
	 if(get_theme_mod('newscrunch_enable_post_category',true)==true):
        if ( has_category() ) : ?>
			<div class="spnc-entry-meta">
				<span class="spnc-cat-links">
					<?php newscrunch_get_categories(get_the_ID()); ?>
				</span>
			</div>
			<?php endif;
		endif; ?>
	</div> 		
	<div class="spnc-post-content <?php if(!has_post_thumbnail()): ?>featured-img<?php endif; ?>">
		<div class="spnc-content-wrapper">
            <div class="spnc-post-wrapper">
            	<header class="spnc-entry-header">
                    <div class="spnc-entry-meta">
                        <!-- Post Author -->
			 			<?php if ( get_theme_mod('newscrunch_enable_post_author',true) == true ) :?>
							<span itemprop="author" class="spnc-author">
							<i class="fas fa-solid fa-user"></i>
								<a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
				                <?php echo esc_html(get_the_author()); ?></a>
				            </span>				            
						<?php endif; ?>
						<!-- Post Tag -->
						<?php
			        	if(get_theme_mod('newscrunch_enable_post_tag',true)==true): 
			        		if(has_tag()): ?>
							<span class="tag-links"><i class="fa fa-tags"></i>
							 	 <?php echo the_tags('',', '); ?>
						 	</span>
					 	<?php	
						 	endif;
						endif; ?>	
						<!-- Post Comments -->
	                    <?php if(get_theme_mod('newscrunch_enable_post_comment',true)==true):?>
							<span class="comment-links spnc-comment-text"><i class="fas fa-comment-alt"></i>
								<a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php the_permalink(); ?>#respond" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>">
								<?php echo esc_html(get_comments_number()) . ' ' . esc_html(get_theme_mod('newscrunch_blog_archive_comments',__('Comments','newscrunch'))); ?>
						     	</a>
					     	</span>
				     	<?php endif;?>
					</div>
					<?php if(get_theme_mod('newscrunch_enable_post_title',true)==true):?>
						<h3 itemprop="name" class="spnc-entry-title">
	                    	<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" itemprop="url" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
	                    </h3>
	                <?php endif; 

	                if(get_theme_mod('newscrunch_enable_post_description',true)==true): 
                    	  if(has_post_format('quote')) {  ?>
	                        <div class="spnc-quote-wrap">
	                            <i class="fa fa-quote-left" aria-hidden="true"></i>
	                            <p class="spnc-description">
	                             <?php the_content();?>
	                            </p>
	                        </div>
	                        <?php
	                        } else { ?>
	                        <div class="spnc-description"><p><?php newscrunch_excerpt(30); ?></p></div>
	                        <?php }
                     endif; ?>
	            </header>
	            <div class="spnc-entry-content">
	            	<?php 
                    if(get_theme_mod('newscrunch_enable_post_read_more',true)==true):
                    $newscrunch_read_more = get_theme_mod('newscrunch_blog_archive_read_btn', __('Read More','newscrunch'));?>
                    <a itemprop="url" href="<?php echo esc_url(get_the_permalink());?>" class="spnc-more-link" title="<?php echo esc_attr($newscrunch_read_more);?>"><?php echo esc_html($newscrunch_read_more);?></a>
                	<?php endif;?>
	            	<div class="spnc-footer-meta">
	            		<div class="spnc-entry-meta <?php if(get_theme_mod('newscrunch_enable_post_read_more',true)== false):?>no-read<?php endif;?>">
				     	<!-- Post Date -->
				    		<?php
							if ( get_theme_mod('newscrunch_enable_post_date',true) == true ) :?>
					            <span class="blog spnc-date">	
					            	<i class="fas fa-solid fa-clock"></i>
									<?php echo newcrunch_post_date_time(get_the_ID()); ?>
								</span>
							<?php endif; ?>   
				        </div>
				     </div>
                </div>                                                                                    
			</div>
		</div>
	</div>
</article>