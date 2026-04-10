<section class="spnc-page-section-space page_widget1 spnc-blog-1">
    <div class="spnc-container">
        <div class="spnc-row">
           <?php
           if(get_theme_mod('page_widget1_sidebar_layout','right')=='left')
           {
            ?>
            <div class="spnc-col-9 <?php echo newscrunch_page_widget1_sidebar_sticky();?>">
                <div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
                    <div class="right-sidebar">
                        <?php
                            if ( is_active_sidebar( 'front-page-side-1' ) ):
                                dynamic_sidebar( 'front-page-side-1' );
                            endif;
                        ?>
                    </div>
                </div>        
            </div>
            <?php
           }

            if(get_theme_mod('page_widget1_sidebar_layout','right')=='right' || get_theme_mod('page_widget1_sidebar_layout','right')=='left'):        
                echo '<div class="spnc-col-7 '.newscrunch_page_widget1_sidebar_stickycontent().'">';
            else:
                echo '<div class="spnc-col-1 '.newscrunch_page_widget1_sidebar_stickycontent().'">';   
            endif; ?>
                <div class="spnc-blog-section">
                    <?php
                        if ( is_active_sidebar( 'front-page-1' ) ):
                            dynamic_sidebar( 'front-page-1' );
                        endif;
                    ?>        
                </div>        
            </div>  
            <?php
            if(get_theme_mod('page_widget1_sidebar_layout','right')=='right')
            { ?>
            <div class="spnc-col-9 <?php echo newscrunch_page_widget1_sidebar_sticky();?>">
                <div itemscope itemtype="https://schema.org/WPSideBar" class="spnc-sidebar spnc-sticky-sidebar" id="spnc-sidebar-fixed">
                    <div class="right-sidebar">
                        <?php
                            if ( is_active_sidebar( 'front-page-side-1' ) ):
                                dynamic_sidebar( 'front-page-side-1' );
                            endif;
                        ?>
                    </div>
                </div>        
            </div>   
            <?php } ?>
        </div>
    </div>
</section>