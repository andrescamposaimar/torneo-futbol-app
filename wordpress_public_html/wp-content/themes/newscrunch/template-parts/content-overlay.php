<?php
/**
 * Template part for displaying content
 *
 * @package NewsCrunch Theme
 */
?>
<article itemscope itemtype="https://schema.org/Article" id="post-<?php the_ID(); ?>" <?php post_class('spnc-overlay-catpost spnc-post  '); ?> >
    <div class="spnc-post-wrap">
        <?php if(get_theme_mod('newscrunch_enable_post_category',true)==true):
                if ( has_category() ) : ?>
                    <div class="entry-header-cat">
                        <div class="spnc-entry-meta">
                            <span class="spnc-cat-links">
                                <?php newscrunch_get_categories(get_the_ID()); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif;
                endif; ?>

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
            if ( is_sticky() ) : ?>
                <span class="sticky-post-icon">
                    <svg class="newscrunch-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 32 32"><path d="M1.599 32c-.407 0-.813-.16-1.125-.472a1.579 1.579 0 0 1-.16-2.073l6.654-9.01-5.214-5.218a1.607 1.607 0 0 1-.245-1.937c.249-.394 2.395-3.526 7.166-2.344.08.01.165.016.256.024.196.017.415.037.652.075 1.01.164 2.796-.647 4.346-1.97 1.485-1.265 2.445-2.742 2.445-3.76 0-.237-.006-.489-.012-.736-.04-.957-.1-2.428.999-3.527a3.564 3.564 0 0 1 4.787-.245.902.902 0 0 1 .08.074c.767.751 8.637 8.62 8.72 8.702A3.574 3.574 0 0 1 32 12.12c0 .959-.372 1.858-1.047 2.534-1.094 1.096-2.572 1.033-3.547.992-.23-.005-.483-.01-.719-.01-.963 0-2.114.674-3.239 1.898-1.587 1.727-2.564 3.958-2.48 4.957.035.32.1.887.113.966 1.152 4.676-1.971 6.808-2.33 7.035a1.596 1.596 0 0 1-1.968-.226l-5.571-5.575a.799.799 0 1 1 1.13-1.13l5.57 5.575c.246-.16 2.502-1.708 1.603-5.368a32.445 32.445 0 0 1-.137-1.124c-.134-1.598 1.112-4.251 2.895-6.192 1.013-1.101 2.6-2.415 4.414-2.415.247 0 .512.006.77.011 1.012.043 1.813.03 2.367-.524a1.986 1.986 0 0 0-.005-2.81c-.082-.082-8.463-8.456-8.71-8.694-.758-.616-1.896-.559-2.617.163-.559.56-.572 1.36-.531 2.347.006.275.012.539.012.786 0 1.511-1.124 3.373-3.006 4.977-1.546 1.317-3.84 2.62-5.637 2.332a6.768 6.768 0 0 0-.537-.061 7.63 7.63 0 0 1-.29-.029.794.794 0 0 1-.225-.04c-3.678-.908-5.234 1.357-5.4 1.618l5.707 5.689a.8.8 0 0 1 .078 1.04L1.598 30.41l7.452-5.454a.8.8 0 0 1 .941 1.291l-7.458 5.441c-.28.209-.607.312-.934.312Z"></path></svg>
                </span>
            <?php endif; ?>

        </div>
        <div class="spnc-post-content <?php if(!has_post_thumbnail()): ?>featured-img<?php endif; ?>">
            <div class="spnc-content-wrapper">
                <div class="spnc-post-wrapper">
                    <header class="spnc-entry-header">
                        <div class="spnc-entry-meta">
                            <!-- Post Author -->
                            <?php if ( get_theme_mod('newscrunch_enable_post_author',true) == true ) :?>
                                <span itemprop="author" class="spnc-author">
                                  
                                        <?php echo wp_kses_post(get_avatar( $post->post_author )); ?>
                                    
                                    <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
                                    <?php echo esc_html(get_the_author()); ?></a>
                                </span>                         
                            <?php endif; ?>
                            <!-- Post Date -->
                            <?php
                                if ( get_theme_mod('newscrunch_enable_post_date',true) == true ) :?>
                                    <span class="spnc-date">    
                                        <i class="fa-solid fa-calendar-days"></i>
                                        <?php echo newcrunch_post_date_time(get_the_ID()); ?>
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
                                    <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php the_permalink(); ?>#respond" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>"><?php echo esc_html(get_comments_number()) . ' ' . esc_html(get_theme_mod('newscrunch_blog_archive_comments',__('Comments','newscrunch'))); ?></a>
                                </span>
                            <?php endif;

                            /* Read Time */
                            if ( class_exists('Newscrunch_Plus') ):
                                if(get_theme_mod('reading_time_enable',false) === true): ?>
                                    <span class="spnc-read-time"><i class="fa fa-eye"></i> <?php spncp_reading_time();?></span>
                            <?php endif; endif;?>
                        </div>
                        <?php if(get_theme_mod('newscrunch_enable_post_title',true)==true):?>
                            <h3 itemprop="name" class="spnc-entry-title">
                                <a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" itemprop="url" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
                            </h3>
                        <?php endif; 
                         ?>
                    </header>
                    <div class="spnc-entry-content">
                        <div class="spnc-footer-meta">
                            <?php if(get_theme_mod('newscrunch_enable_post_description',true)==true):
                            if(has_post_format('quote')) {  ?>
                                <div class="spnc-quote-wrap">
                                    <i class="fa fa-quote-left" aria-hidden="true"></i>
                                     <?php the_content();?>
                                </div>
                                <?php
                                } else { ?>
                                <p class="spnc-description">
                                    <?php newscrunch_excerpt(15); ?>
                                </p>
                                <?php }
                        endif; 

                        $newscrunch_read_more = get_theme_mod('newscrunch_blog_archive_read_btn', __('Read More','newscrunch'));
                        if( get_theme_mod('newscrunch_enable_post_read_more', true ) == true ): ?>
                            <a itemprop="url" href="<?php echo esc_url(get_the_permalink());?>" class="spnc-more-link" title="<?php echo esc_attr($newscrunch_read_more);?>"><?php echo esc_html($newscrunch_read_more);?></a>
                        <?php endif;

                        ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</article>