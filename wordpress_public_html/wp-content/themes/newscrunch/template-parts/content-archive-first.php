<?php
/**
 * Template part for displaying archive first post
 *
 * @package Newscrunch Theme
 */
if (is_category()) {
    $newscrunch_sticky_posts = get_option( 'sticky_posts' );
    if ( $newscrunch_sticky_posts ) {
        if (count($newscrunch_sticky_posts) >= 1) {
            $newscrunch_args = array(
                'post__in' => $newscrunch_sticky_posts,
                'posts_per_page' => -1,
                'cat' => get_queried_object_id(),
            );
            $newscrunch_sticky_query = new WP_Query( $newscrunch_args ); 
            $newscrunch_post_ids = array();
            while ( $newscrunch_sticky_query->have_posts() ) : $newscrunch_sticky_query->the_post();  $newscrunch_post_ids[] = get_the_ID(); endwhile;
            wp_reset_postdata();             
            if(!empty($newscrunch_post_ids)) {
                if (in_array($newscrunch_sticky_posts[0], $newscrunch_post_ids)) {  $newscrunch_cat_sticky_posts= $newscrunch_sticky_posts[0];  }
                else { $newscrunch_cat_sticky_posts= $newscrunch_post_ids[0]; } ?>
                <section class="spnc-page-section-space arc<?php do_action( 'spncp_check_bread_hook' ); ?>">
                    <div class="spnc-container">
                        <div class="spnc-row">
                            <div class="spnc-col-1">
                                <div class="spnc-blog-section">
                                    <div class="spnc-cat-first-post">
                                        <?php 
                                        $newscrunch_cat_args = array(
                                            'post__in' => array($newscrunch_cat_sticky_posts),
                                            'posts_per_page' => 1,
                                            'cat' => get_queried_object_id(),
                                        );
                                        $newscrunch_cat_sticky_query = new WP_Query( $newscrunch_cat_args );
                                        while ( $newscrunch_cat_sticky_query->have_posts() ) : $newscrunch_cat_sticky_query->the_post(); ?>
                                            <article itemscope itemtype="https://schema.org/Article" id="post-<?php the_ID(); ?>" <?php post_class('spnc-post '); ?>>
                                                <div class="spnc-post-wrapper">
                                                    <a href="<?php the_permalink();?>" itemprop="url"  title="<?php the_title(); ?>" >
                                                        <div class="spnc-post-overlay"></div>
                                                        <figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>"> 
                                                            <?php the_post_thumbnail('full', array('class'=>'img-fluid sp-thumb-img', 'loading' => false, 'itemprop'=>'image' )); ?>
                                                        </figure>
                                                    </a>
                                                    <div class="spnc-post-content">
                                                        <?php if(get_theme_mod('newscrunch_enable_post_category',true)==true):
                                                            if ( has_category() ) :
                                                                echo '<span itemprop="about" class="spnc-cat-links">';
                                                                    newscrunch_get_categories(get_the_ID());
                                                                echo '</span>';
                                                            endif; 
                                                        endif;
                                                        if(get_theme_mod('newscrunch_enable_post_title',true)==true): ?>
                                                            <header class="entry-header">
                                                                <h4 class="spnc-entry-title">
                                                                    <a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" itemprop="url" href="<?php the_permalink();?>" title="<?php the_title(); ?>"><?php the_title();?></a>
                                                                </h4>
                                                            </header>
                                                        <?php endif; ?>
                                                        <div class="spnc-entry-content">
                                                            <div class="spnc-footer-meta">
                                                                <div class=" spnc-entry-meta">
                                                                    <?php if ( get_theme_mod('newscrunch_enable_post_author',true) == true ) :?>
                                                                        <span itemprop="author" class="spnc-author">
                                                                            <i class="fa-solid fa-circle-user"></i>
                                                                                <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
                                                                                    <?php echo esc_html(get_the_author()); ?></a>
                                                                        </span>                         
                                                                    <?php endif; ?>
                                                                    <?php if ( get_theme_mod('newscrunch_enable_post_date',true) == true ) : ?>
                                                                        <span class="spnc-date">
                                                                            <i class="fa-solid fa-clock"></i>    
                                                                            <?php echo newcrunch_post_date_time(get_the_ID()); ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <?php
                                                                    if(get_theme_mod('newscrunch_enable_post_tag',true)==true):
                                                                        if(has_tag()):
                                                                            echo '<span class="spnc-tag-links"><i class="fa fa-tags"></i>';
                                                                            the_tags('',', ');
                                                                                echo'</span>';  
                                                                        endif;
                                                                    endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php if(get_theme_mod('newscrunch_enable_post_description',true)==true): ?>
                                                                <p class="spnc-description"><?php newscrunch_excerpt(15); ?></p>
                                                            <?php 
                                                            endif; 
                                                            $newscrunch_read_more = get_theme_mod('newscrunch_blog_archive_read_btn', __('Read More','newscrunch'));
                                                            if( get_theme_mod('newscrunch_enable_post_read_more', true ) == true ): ?>
                                                                <a itemprop="url" href="<?php echo esc_url(get_the_permalink());?>" class="spnc-more-link" title="<?php echo esc_attr($newscrunch_read_more);?>"><?php echo esc_html($newscrunch_read_more);?></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </article>
                                        <?php endwhile;
                                        wp_reset_postdata(); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            <?php }
        }
    } 
}?>