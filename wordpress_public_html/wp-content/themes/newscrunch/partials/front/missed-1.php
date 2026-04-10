<section class="spnc-page-section-space spnc-missed-section spncmc-1 spncmc-selective">
    <div class="spnc-container">
        <div class="spnc-row">
            <div class="spnc-col-1">
            <div class="spnc-missedcarousel spnc-common-widget-area">
                <?php 
                $spnc_missed_section_title = get_theme_mod('missed_section_title',__('You May Have Missed','newscrunch'));
                if(!empty($spnc_missed_section_title)):?>
                <div class="spnc-main-wrapper">
                    <div class="spnc-main-wrapper-heading">
                        <h2 class="widget-title spncmc-head"><?php echo esc_html($spnc_missed_section_title);?></h2>
                    </div>
                </div>
                <?php endif;?>
                <!--main carousel element-->
                <div id="spnc-missedcarousel" class="owl-carousel spnc-missed-wrap">
                    <?php
                    $spnc_missed_category = get_theme_mod('missed_section_dropdown_category',1);
                    $spnc_missed_post_order     = get_theme_mod('missed_section_post_order','DESC'); 
                    $newscrunch_hide_show_banner_center_meta = get_theme_mod('hide_show_missed_section_meta',true); 

                    global $post;

                    $query_args2 = array( 'cat'  => $spnc_missed_category, 'order' => $spnc_missed_post_order,'ignore_sticky_posts' => 1);
                    $the_query2 = new WP_Query($query_args2);

                    if ( $the_query2->have_posts() ) {
                        $post_count = $the_query2->found_posts;
                        while ( $the_query2->have_posts() ) 
                        {
                        $the_query2->the_post(); 
                          $newscrunch_featured_img_url = get_the_post_thumbnail_url(get_the_ID(),'full'); 
                        ?>
                        <div class="spnc-missed-post post-<?php echo esc_attr($post_count);?>">
                            <article class="spnc-post">
                                <div class="spnc-missed-overlay">
                                    <figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
                                        <?php 
                                        if ( has_post_thumbnail() )
                                            { the_post_thumbnail('medium'); } 
                                        else {
                                             ?>
                                                <img class="img-fluid sp-thumb-img" src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
                                            <?php 
                                        } ?>
                                    </figure>
                                    <?php if($newscrunch_hide_show_banner_center_meta == true){?>
                                        <div class="spnc-entry-meta">
                                            <span class="spnc-cat-links">
                                                <?php newscrunch_get_categories(get_the_ID()); ?>
                                            </span>
                                        </div>
                                        <?php } ?>
                                </div>
                                <div class="spnc-post-content">
                                    <header class="entry-header">
                                        <h4 class="spnc-entry-title">
                                            <a class="<?php echo esc_attr(get_theme_mod('link_animate','ancher_effact_1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
                                        </h4>
                                    </header>
                                    <?php if($newscrunch_hide_show_banner_center_meta == true){?>
                                    <div class="spnc-entry-content">
                                        <div class="spnc-footer-meta spnc-entry-meta">
                                            <span class="spnc-date"> <?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
                                            <span class="spnc-author"><a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>"> <?php echo esc_html(get_the_author());?></a></span>
                                        </div>
                                    </div>
                                <?php } ?>
                                </div>
                            </article>
                        </div>
                        <?php 
                        }
                        wp_reset_query();
                    } ?>
                </div>
                <!--element to hold filtered out items-->
                <div id="spnc-blog-hidden" class="hide"></div>
            </div>
        </div>    
    </div>
</div>
</section>