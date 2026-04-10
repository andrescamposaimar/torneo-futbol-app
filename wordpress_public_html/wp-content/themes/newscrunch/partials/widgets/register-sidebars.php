<?php
/**
 * Register widget area.
 *
*/
function newscrunch_widgets_init() {

    /**
    * frontpage widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Front Left Content', 'newscrunch' ),
            'id'            => 'front-page-1',
            'description'   => esc_html__( 'Add widgets in front page', 'newscrunch') . ' 1 ' . esc_html__('widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c spnc-common-widget-area front-page-1 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-main-wrapper"><div class="spnc-main-wrapper-heading"><h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2></div></div>',
        )
    );
    
    /**
    * frontpage sidebar 1
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Middle Right Sidebar', 'newscrunch' ),
            'id'            => 'front-page-side-1',
            'description'   => esc_html__( 'Add widgets in front sidebar', 'newscrunch' ) . ' 1 ' . esc_html__('widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c side-bar-widget front-page-side-1 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="spnc-widget-title" itemprop="name">',
            'after_title'   => '</h2></div>',
        )
    );

    /**
    * frontpage widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Front Right Content', 'newscrunch' ),
            'id'            => 'front-page-2',
            'description'   => esc_html__( 'Add widgets in front page', 'newscrunch' ) . ' 2 ' . esc_html__( 'widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c spnc-common-widget-area front-page-2 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-main-wrapper"><div class="spnc-main-wrapper-heading"><h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2></div></div>',
        )
    );
    
    /**
    * frontpage sidebar 2
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Middle Left Sidebar', 'newscrunch' ),
            'id'            => 'front-page-side-2',
            'description'   => esc_html__( 'Add widgets in front sidebar', 'newscrunch' ) . ' 2 ' . esc_html__( 'widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c side-bar-widget front-page-side-2 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="spnc-widget-title" itemprop="name">',
            'after_title'   => '</h2></div>',
        )
    );
    
    /**
    * Right Sidebar widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Primary Right', 'newscrunch' ),
            'id'            => 'sidebar-1',
            'description'   => esc_html__( 'Add widgets in right sidebar widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c side-bar-widget sidebar-1 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="spnc-widget-title" itemprop="name">',
            'after_title'   => '</h2></div>',
        )
    );

    /**
    * Left Sidebar widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__( 'Primary Left', 'newscrunch' ),
            'id'            => 'left-sidebar',
            'description'   => esc_html__( 'Add widgets in left sidebar widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" data-wow-delay=".6s" class="wow-callback zoomIn widget w-c side-bar-widget sidebar-1 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="spnc-widget-title" itemprop="name">',
            'after_title'   => '</h2></div>',
        )
    );

    //Side Panel widget area
    register_sidebar( 
        array(
            'name' => esc_html__('Header Toggle Sidebar', 'newscrunch' ),
            'id' => 'menu-widget-area',
            'description' => esc_html__( 'Add widgets in header toggle sidebar widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" class="widget w-c spnc-common-widget-area spnc-side-panel %2$s">',
            'after_widget' => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="widget-title" itemprop="name">',
            'after_title' => '</h3></div>',
        ) 
    );

    /**
    * Footer1 widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__('Footer', 'newscrunch' ) . ' 1',
            'id'            => 'footer-sidebar-1',
            'description'   => esc_html__('Add widgets in footer widget area', 'newscrunch' ) . ' 1',
            'before_widget' => '<aside id="%1$s" class=" widget f-w-c footer-sidebar-1 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2>',
        )
    );

    /**
    * Footer2 widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__('Footer', 'newscrunch' ) . ' 2',
            'id'            => 'footer-sidebar-2',
            'description'   => esc_html__('Add widgets in footer widget area', 'newscrunch' ) . ' 2',
            'before_widget' => '<aside id="%1$s" class="widget f-w-c footer-sidebar-2 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2>',
        )
    );

    /**
    * Footer3 widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__('Footer', 'newscrunch' ) . ' 3',
            'id'            => 'footer-sidebar-3',
            'description'   => esc_html__('Add widgets in footer widget area', 'newscrunch' ) . ' 3',
            'before_widget' => '<aside id="%1$s" class="widget f-w-c footer-sidebar-3 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2>',
        )
    );

    /**
    * Footer4 widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__('Footer', 'newscrunch' ) . ' 4',
            'id'            => 'footer-sidebar-4',
            'description'   => esc_html__('Add widgets in footer widget area', 'newscrunch' ) . ' 4',
            'before_widget' => '<aside id="%1$s" class="widget f-w-c footer-sidebar-4 %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h2 class="widget-title" itemprop="name">',
            'after_title'   => '</h2>',
        )
    );

    /**
    * Woocommerce widget area
    */
    register_sidebar(
        array(
            'name'          => esc_html__('WooCommerce Sidebar', 'newscrunch' ),
            'id'            => 'woocommerce',
            'description'   => esc_html__('Add widgets in woocommerce widget area', 'newscrunch' ),
            'before_widget' => '<aside id="%1$s" class="wow-callback zoomIn widget side-bar-widget w-c f-w-c wc-sidebar %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<div class="spnc-widget-heading"><h2 class="spnc-widget-title" itemprop="name">',
            'after_title'   => '</h2></div>',
        )
    );

    // Register custom widgets
    register_widget( 'Newscrunch_Advertisement_Widget_Controller' );   //Widget API: Advertisement Widget Class
    register_widget( 'Newscrunch_Featured_Post_Widget_Controller' );   //Widget API: Featured Post Widget Class
    register_widget( 'Newscrunch_Grid_Slider_Two_Column_Widget_Controller' ); //Widget API: Grid Slider Two Column Widget Class
    register_widget( 'Newscrunch_Post_Grid_Two_Column_Widget_Controller' ); //Widget API: Post Grid Two Column Widget class
    register_widget( 'Newscrunch_Grid_Slider_Three_Column_Widget_Controller' ); //Widget API: Grid Slider Three Column Widget Class
    register_widget( 'Newscrunch_List_Grid_View_Widget_Controller' );  //Widget API: List Grid View Widget Class
    register_widget( 'Newscrunch_List_View_Slider_Widget_Controller' );//Widget API: List View Slider Widget Class
    register_widget( 'Newscrunch_Overlay_Slider_Widget_Controller' ); //Widget API: Overlay Slider Widget Class
    register_widget( 'Newscrunch_Single_Column_Widget_Controller' );   //Widget API: Single Column Widget Class
    register_widget( 'Newscrunch_Social_Icon_Widget_Controller' );     //Widget API: Social Icon Widget Class
    register_widget( 'Newscrunch_Tabs_Widget_Controller' );            //Widget API: Post Tabs Widget Class  
    
}

add_action('widgets_init', 'newscrunch_widgets_init');


// includes widget files
get_template_part('partials/widgets/advertisement');
get_template_part('partials/widgets/featured-post');
get_template_part('partials/widgets/post-grid-2-column-slider');
get_template_part('partials/widgets/post-grid-2-column');
get_template_part('partials/widgets/post-grid-3-column-slider');
get_template_part('partials/widgets/post-list-grid-view');
get_template_part('partials/widgets/post-list-view-slider');
get_template_part('partials/widgets/post-overlay-slider');
get_template_part('partials/widgets/single-column');
get_template_part('partials/widgets/social-icons');
get_template_part('partials/widgets/tabs');

function newscrunch_widget_register_scripts($hook) {
    if( $hook !== "widgets.php" ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style( 'newscrunch-widget', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/css/widgets.css', array(),'', 'all'  );
    wp_enqueue_script( 'newscrunch-media-upload', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/widgets.js', array( 'jquery' ),'',true);
    wp_enqueue_script( 'owl_carousel', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/owl.carousel.js', array( 'jquery' ),'',true);
}
add_action( 'admin_enqueue_scripts', 'newscrunch_widget_register_scripts' );