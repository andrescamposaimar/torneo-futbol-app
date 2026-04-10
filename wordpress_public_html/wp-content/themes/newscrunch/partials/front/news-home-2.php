<section class="spnc-page-section-space page_widget2 spnc-blog-2">
    <div class="spnc-container">
        <div class="spnc-row">
            <?php if(get_theme_mod('page_widget2_sidebar_layout','left')=='left') { ?>
            <div class="spnc-col-9 <?php echo newscrunch_page_widget2_sidebar_sticky();?>">
                <div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
                    <div class="right-sidebar">
                        <?php
                            if ( is_active_sidebar( 'front-page-side-2' ) ):
                                dynamic_sidebar( 'front-page-side-2' );
                            endif;
                        ?> 
                    </div>
                </div>
            </div>
            <?php
            }

            if(get_theme_mod('page_widget2_sidebar_layout','left')=='right' || get_theme_mod('page_widget2_sidebar_layout','left')=='left'):        
                echo '<div class="spnc-col-7 '.newscrunch_page_widget2_sidebar_stickycontent().'">';
            else:
                echo '<div class="spnc-col-1 '.newscrunch_page_widget2_sidebar_stickycontent().'">';   
            endif; ?>
                <div class="spnc-blog-section">
                    <?php
                        if ( is_active_sidebar( 'front-page-2' ) ):
                            dynamic_sidebar( 'front-page-2' );
                        endif;
                    ?>        
                </div>        
            </div>   

            <?php if(get_theme_mod('page_widget2_sidebar_layout','left')=='right') { ?>
            <div class="spnc-col-9 <?php echo newscrunch_page_widget2_sidebar_sticky();?>">
                <div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
                    <div class="right-sidebar">
                        <?php
                            if ( is_active_sidebar( 'front-page-side-2' ) ):
                                dynamic_sidebar( 'front-page-side-2' );
                            endif;
                        ?> 
                    </div>
                </div>        
            </div>   
            <?php } ?>
        </div>
    </div>
</section>