<?php 
/**
 * This file includes helper functions used throughout the theme.
 *
 * @package Newscrunch
 */

/*
-------------------------------------------------------------------------------
 Table of contents 
-------------------------------------------------------------------------------*/

	# Header
    # Logo Width
    # Enqueue file for customizer preview
	# Footer Section
	# Footer Widget Layout
	# Footer Bar Menu
    # Scroll to Top
 	# Comment Reply Box
 	# Breadcrumbs Page title hook 
	# Sidebar layout 
	# Page Navigation
	# Wide and Boxed Layout
 	# Added skip link focus
	# Set post views count using post meta
	# Set overlay slider loop
	# Get content with fixed length
	# Get post date
	# Get the post title
	# Get the author datails
	# Get the categories
	# Get the featured image
	# Category color
	# Side Panel widgets
	# Breadcrumb Style
	# Convert dark layout & Search Full Screen
	# Check dark & light icon
	# Check search popup
	# Single Post Layout
	# Post Widgets
	# Single Post Nav
	# Related Post
	# Logo /Dark Logo / Title / Site Title
    # Breadcrumb Variations
	# Retrieve Youtube Video Id
	# Check Youtube URL
	# Retrieve Vimeo URL
	# News Highlight Section Hook
	# News Highlight Section
	# Enabe Disble Sticky Sidebar
	# Edit link button for single posts and pages.
	# Retrieve video from post
	# Retrieve first video from post
	# Retrieve VideoId from embed code
	# Extract the video ID and convert to standard YouTube URL
	# Single Post Author Details
 	# Advertisement Section
	# Post Formats
	# Add WooCommerce Compatibility
	# Number of posts display in blog/archive section
/*
-------------------------------------------------------------------------------
 Header
-------------------------------------------------------------------------------*/

if ( ! function_exists( 'newscrunch_header_template' ) ) {
	function newscrunch_header_template() {
		if(get_theme_mod('header_layout','2') == '2')
		{
			get_template_part( 'partials/header/default-header' );
		}
		else
		{
			get_template_part( 'partials/header/main-header' );
		}
	}
	add_action( 'newscrunch_header', 'newscrunch_header_template' );
}

/*
-------------------------------------------------------------------------------
 Logo Width
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_width_fn')) :
	function newscrunch_width_fn() { ?>
		<style>
			.custom-logo, .dark-custom-logo{
				width: <?php echo intval( get_theme_mod('newscrunch_logo_length',250) );?>px; 
				height: auto;
			}
			@media only screen and (max-width: 992px){
			.custom-logo, .dark-custom-logo{
				width: <?php echo intval( get_theme_mod('newscrunch_logo_tablet_length',200) );?>px; 
				height: auto;
			}}
			@media only screen and (max-width: 500px){
			.custom-logo, .dark-custom-logo{
				width: <?php echo intval( get_theme_mod('newscrunch_logo_mobile_length',150) );?>px; 
				height: auto;
			}}
		</style>
		<?php 
	}
	add_action('wp_head','newscrunch_width_fn');
endif;

/*
-------------------------------------------------------------------------------
 Enqueue file for customizer preview
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_customizer_preview' ) ) {

	function newscrunch_customizer_preview() {
		wp_enqueue_script( 'newscrunch-customizer-preview-js', NEWSCRUNCH_TEMPLATE_DIR_URI .'/inc/customizer/assets/js/customizer-preview.js', array( 'customize-preview', 'jquery' ) );
	}
	add_action('customize_preview_init','newscrunch_customizer_preview');
}

/*
-------------------------------------------------------------------------------
 Footer Section
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_footer_widget_section')) :

	function newscrunch_footer_widget_section() {
		if (str_contains(get_theme_mod('footer_widget_back_image'), 'https://demo-newscrunch.spicethemes.com')) 
        { 
            $exp = explode('wp-content',get_theme_mod('footer_widget_back_image'));
            $footer_img = home_url('/').'wp-content'.$exp[1];
        }
        else
        {
        	$footer_img = get_theme_mod('footer_widget_back_image');
        }
	    ?>
	    <footer itemscope itemtype="http://schema.org/WPFooter" class="site-footer bg-default bg-footer-lite spnc-footer-ribbon-<?php echo esc_attr(get_theme_mod('ribbon_layout',1));?>" <?php if (!empty(get_theme_mod('footer_widget_back_image'))): ?> style="background-image: url(<?php echo esc_url( $footer_img ); ?>);"  <?php endif; ?>>
	    	<?php 
    		if(get_theme_mod('footer_overlay_enable',true) == true): 
	    		$footer_overlay_color = get_theme_mod('footer_image_overlay_color', 'rgb(15,11,31,0.9)'); ?>
				<div class="overlay" style="background-color: <?php echo $footer_overlay_color; ?>;"></div>
	    	<?php endif;
	    	if(get_theme_mod('hide_show_footer_widgets',true)==true): 
	    	// Ribbon Section
			if( function_exists( 'ribbon_section' )): ribbon_section(); endif;?>
				<div class="spnc-container">	
					<?php if(is_active_sidebar('footer-sidebar-1') || is_active_sidebar('footer-sidebar-2') || is_active_sidebar('footer-sidebar-3') || is_active_sidebar('footer-sidebar-4')): 
	                 	get_template_part('partials/footer/footer-sidebar');
		            endif;?>  	
				</div>
			<?php endif; 
			if( (get_theme_mod('hide_show_footer_menus',true)==true) || (get_theme_mod('hide_show_footer_copyright',true)==true) ) { ?>
				<div class="site-info">
					<div data-wow-delay=".5s" class="wow-callback slideInLeft spnc-container">
						<div class="spnc-row">
							<?php if(get_theme_mod('hide_show_footer_menus',true)==true): ?>
								<div class="spnc-col-1 spnc-right">
									<?php echo newscrunch_footer_bar_menu(); ?>
								</div>
							<?php 
							endif;
							if ('NewsBlogger' == wp_get_theme()) {
								$newscrunch_tname = wp_get_theme();
							}
							else {
								$newscrunch_tname = wp_get_theme();
							}
							if(get_theme_mod('hide_show_footer_copyright',true)==true): ?>
								<div class="spnc-col-1 spnc-left">
									<p class="copyright-section">
										<?php $newscrunch_footer_copyright = get_theme_mod('footer_copyright', $newscrunch_tname . ' - ' . __('Magazine & Blog', 'newscrunch') . ' '. '<a href="'. esc_url('https://wordpress.org') .'">' . 'WordPress' . '</a>' . ' ' . __("Theme","newscrunch") . ' ' . '%year%'); 
										echo wp_kses_post( str_replace( '%year%', date('Y'), $newscrunch_footer_copyright ) );
										echo ' | ' . sprintf( esc_html__('Powered By', 'newscrunch') . ' %s', '<a href="'. esc_url('https://spicethemes.com/') . '" rel="nofollow">' . ' ' . 'SpiceThemes'. '</a>');
									?>
									</p>
								</div> 
							<?php endif; ?>  
						</div>
					</div>
				</div>
			<?php } ?>
		</footer>
	<?php }
	add_action('newscrunch_footer_widgets', 'newscrunch_footer_widget_section');

endif;


/*
-------------------------------------------------------------------------------
 Footer Widget Layout
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_footer_layout')) :

	function newscrunch_footer_layout($layout) {
		if( $layout == 2 ) {
			$class = 'spnc-col-8';
		}
		elseif( $layout == 3 ) {
			$class = 'spnc-col-9';
		}
		else {
			$class = 'spnc-col-10';
		}
		for($i=1; $i<=$layout; $i++)
		{
			echo '<div class="' . $class . '">';
			dynamic_sidebar('footer-sidebar-'.$i);
			echo '</div>';
		}
	}

endif;

/*
-------------------------------------------------------------------------------
 Footer Bar Menu
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_footer_bar_menu')) {

    function newscrunch_footer_bar_menu() {
        ob_start();
        if (has_nav_menu('footer_menu')) {
            wp_nav_menu(
                array(
                    'theme_location' 	=> 'footer_menu',
                    'menu_class'		=> 'footer-nav nav-menu',
                    'items_wrap' 		=> '<ul id="%1$s" class="%2$s">%3$s</ul>',
                    'depth' 			=> 1
                )
            );
        } 
        else {
            if (is_user_logged_in() && current_user_can('edit_theme_options')) { ?>
                <a itemprop="url" href="<?php echo esc_url(admin_url('/nav-menus.php?action=locations')); ?>" title="<?php esc_attr_e('Assign Footer Menu','newscrunch');?>"><?php esc_html_e('Assign Footer Menu', 'newscrunch'); ?></a>
            <?php }
        }
        return ob_get_clean();
    }
}


/*
-------------------------------------------------------------------------------
 Scroll to Top
-------------------------------------------------------------------------------*/

if ( ! function_exists( 'newscrunch_scroll_to_top' ) ) {

	function newscrunch_scroll_to_top() {
		$scrolltotop_icon_enable = get_theme_mod('hide_show_scroll_to_top', true);
    	if ($scrolltotop_icon_enable == true) { ?>
        	<div class="scroll-up custom <?php echo esc_attr(get_theme_mod('scroll_to_top_position','right') ); ?>"><a href="#totop" title="<?php esc_attr_e('Back to Top','newscrunch');?>"><i class="<?php echo esc_attr( get_theme_mod('scroll_to_top_icon_class', 'fa fa-arrow-up')); ?>"></i></a></div>
    	<?php } ?>
    	<style type="text/css">
    		.scroll-up {
    			<?php echo esc_attr( get_theme_mod('scroll_to_top_position','right') ); ?>: 3.75rem;
    		}
    		.scroll-up.left { right: auto; }
    		.scroll-up.custom a {
		        border-radius: <?php echo intval( get_theme_mod('scroll_to_top_button_radious', 3) ); ?>px;
		    }
    	</style>
    	<?php if(get_theme_mod('hide_show_scroll_to_top_color',true)==true) { ?>
    		<style type="text/css">
    			.scroll-up.custom a {
				    background: <?php echo esc_attr( get_theme_mod('scroll_to_top_back_color','#'));?>;
				    color: <?php echo esc_attr( get_theme_mod('scroll_to_top_icon_color','#fff'));?>;
    			}
    			.scroll-up.custom a:hover {
				    background: <?php echo esc_attr( get_theme_mod('scroll_to_top_back_hover_color','#') );?>;
				    color: <?php echo esc_attr( get_theme_mod('scroll_to_top_icon_hover_color','#fff'));?>;
    			}
    		</style>
    	<?php }
	}
	add_action('newscrunch_scrolltotop','newscrunch_scroll_to_top');

}

/*
-------------------------------------------------------------------------------
 Comment Reply Box
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_comment_box')) :

	function newscrunch_comment_box($comment, $args, $depth) { ?>

		<div class="comment-box" itemscope itemtype="http://schema.org/UserComments">
			<span class="pull-left-comment">
			   <?php echo get_avatar($comment, 100, null, 'comments user', array('class' => array('img-fluid comment-img'))); ?>
			</span>
			<div class="comment-body">
				<div class="comment-detail">
				 	<h5 class="comment-detail-title"><?php esc_html(comment_author()); ?>
				 		<time itemprop="commentTime" class="comment-date"><?php 
				 			/* translators: %1$s: comment date and %2$s: comment time */
				 			printf('%1$s  %2$s', esc_html(get_comment_date()), esc_html(get_comment_time())); ?></time>
				 	</h5>
				 	<span itemprop="commentText"><?php comment_text(); ?></span>
					<div class="reply">
						<?php comment_reply_link(array_merge($args, array('depth' => $depth, 'max_depth' => $args['max_depth']))); ?> 
				    </div>
				</div>       
			</div>      
		</div>

	<?php }

endif;

/*
-------------------------------------------------------------------------------
 Breadcrumbs Page title hook
-------------------------------------------------------------------------------*/

if ( ! function_exists( 'newscrunch_breadcrumbs_page_title_fn' ) ) {
	function newscrunch_breadcrumbs_page_title_fn(){
		if(get_theme_mod('enable_page_title',true) == true ){		
			if(get_theme_mod('bredcrumb_position','page_header')=='content_area'):
				$content_class='content-area-title';
			else:
				$content_class='';
			endif;
			$breadcrumbs_markup=get_theme_mod('bredcrumb_markup','h1');
		  	$page_title_markup_before='<' . $breadcrumbs_markup . '>';
		  	$page_title_markup_after='</' . $breadcrumbs_markup . '>';
		  	if (is_home() || is_front_page()) { 
		    	if( get_option('show_on_front') =='page') {
		        	if(is_front_page()) { ?>
		        		<div class="page-title <?php echo $content_class;?>">
							<?php echo $page_title_markup_before . esc_html(get_the_title( get_option('page_on_front', true) )) . $page_title_markup_after; ?>
						</div>
		        	<?php }
		        	elseif(is_home()) { ?>
		                <div class="page-title  <?php echo $content_class;?>">
		                    <?php echo $page_title_markup_before . esc_html(get_the_title( get_option('page_for_posts', true) )) . $page_title_markup_after; ?>
		                </div>          
		            <?php
		            }
		        }
		        else if(get_option('show_on_front')=='posts') { ?>
		            <div class="page-title  <?php echo $content_class;?>">
		                <?php echo $page_title_markup_before . wp_kses_post(get_theme_mod('blog_page_title_option', __('Home', 'newscrunch' ))) . $page_title_markup_after; ?>
		            </div>
		    	<?php
		    	} 
		    }
		    else { ?>
		    	<div class="page-title  <?php echo $content_class;?>">
		    		<?php if (is_search()){
		    			 echo $page_title_markup_before . get_theme_mod('search_prefix', esc_html__('Search', 'newscrunch') . ': ').get_search_query() . $page_title_markup_after;
		            }
		            else if(is_404()) {
		                echo $page_title_markup_before . esc_html__('Error 404','newscrunch' ) . $page_title_markup_after; 
		            }
		            else if(is_category()) {
		                echo $page_title_markup_before . ( get_theme_mod('category_prefix', esc_html__('Category', 'newscrunch') . ': ') . single_cat_title( '', false ) ) . $page_title_markup_after;   
		            } 
		            else if( is_tag() ) {
		                echo $page_title_markup_before . ( get_theme_mod('tag_prefix', esc_html__('Tag', 'newscrunch') . ': '). single_tag_title( '', false ) ) . $page_title_markup_after;
		            }
		            else if( is_author() ) {
		                echo $page_title_markup_before . ( get_theme_mod('author_prefix', esc_html__('Author', 'newscrunch') . ': '). get_the_author() ) . $page_title_markup_after;
		            }
		            else if( is_day() ) {
		                echo $page_title_markup_before . ( get_theme_mod('month_prefix', esc_html__('Month', 'newscrunch') . ': '). get_the_date() ) . $page_title_markup_after;
		            }
		            else if( is_month() ) {
		                echo $page_title_markup_before . ( get_theme_mod('month_prefix', esc_html__('Month', 'newscrunch') . ': '). get_the_date('F Y') ) . $page_title_markup_after;
		            }
		            else if( is_year() ) {
		                echo $page_title_markup_before . ( get_theme_mod('month_prefix', esc_html__('Month', 'newscrunch') . ': ').get_the_date('Y') ) . $page_title_markup_after;
		            }
		            else if( class_exists( 'WooCommerce' ) && is_shop()) {
		            	echo "<h1>";
		                echo esc_html_e( 'Shop', 'newscrunch' );
		                echo "</h1>";
		            }
		            else if(is_archive()) {   
		            	the_archive_title( $page_title_markup_before, $page_title_markup_after );
		            }
		            else { ?>
		        		<?php echo $page_title_markup_before . esc_html(get_the_title('')) . $page_title_markup_after; ?>
		    		<?php }
		        ?>
		        </div>
		    <?php }
		}
	}
	add_action('newscrunch_breadcrumbs_page_title_hook','newscrunch_breadcrumbs_page_title_fn');
}


/*
-------------------------------------------------------------------------------
 Sidebar layout  
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_sidebar_layout_fn')) :

	function newscrunch_sidebar_layout_fn() { ?>
		
		<?php
		if(((get_theme_mod('blog_sidebar_layout','right')=='stretched')  && get_post_meta(get_option('page_for_posts', true),'newscrunch_site_layout', true ) == '') || (get_post_meta(get_option('page_for_posts', true),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_stretched'))
			{
			?>
			<style>
				@media (min-width: 1100px){
				body.blog .page-section-space.blog .spnc-container{
					width: 100%;
					max-width: 100%;
				}}
			</style>
		<?php }
		if(get_theme_mod('blog_sidebar_layout','right')=='stretched' )
		{?>
			<style>
				@media (min-width: 1100px){
				body.archive .page-section-space.blog .spnc-container,
				body.search .page-section-space.blog .spnc-container {
					width: 100%;
					max-width: 100%;
				}
			}
			</style>
		<?php }
		if(((get_theme_mod('single_blog_sidebar_layout','right')=='stretched')  && get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == '') || ( get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) =='newscrunch_site_layout_stretched'))	
		{?>
			<style>
				@media (min-width: 1100px){
				body.single-post .spnc-container.spnc-single-post{
					width: 100%;
					max-width: 100%;
				}}
			</style>
		<?php }
		if(((get_theme_mod('page_sidebar_layout','right')=='stretched')  && get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='') || ( get_post_meta(get_the_ID(),'newscrunch_site_layout', true ) == 'newscrunch_site_layout_stretched')){?>
			<style>
				@media (min-width: 1100px){
				body .page-section-space.stretched .spnc-container {
					width: 100%;
					max-width: 100%;
				}}
			</style>
		<?php } 
		if(get_theme_mod('page_widget1_sidebar_layout','right')=='stretched')
		{
			?>
			<style>
				@media (min-width: 1100px){
				body.blog .spnc-page-section-space.page_widget1 .spnc-container{
					width: 100%;
					max-width: 100%;
				}}
			</style>
			<?php
		}
		if(get_theme_mod('page_widget2_sidebar_layout','left')=='stretched')
		{
			?>
			<style>
				@media (min-width: 1100px){
				body.blog .spnc-page-section-space.page_widget2 .spnc-container{
					width: 100%;
					max-width: 100%;
				}}
			</style>
			<?php
		}

		//Load sidebar widgets after the main content on mobile devices for single/blog post 
		if(get_theme_mod('single_blog_sidebar_layout','right')=='left' || get_theme_mod('single_blog_sidebar_layout','right')=='both' || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_left' || get_post_meta(get_the_ID(),'newscrunch_site_layout', true )=='newscrunch_site_layout_both' || get_theme_mod('blog_sidebar_layout','right')=='left' || get_theme_mod('blog_sidebar_layout','right')=='both') {  
			?>
			<style>
				@media (max-width: 1023px) {
					body .spnc-single-post .spnc-col-9, body .spnc-single-post .spnc-col-10, body .spnc-index-blog .spnc-col-9, body .spnc-index-blog .spnc-col-10, body .spnc-blog-archive .spnc-col-9, body .spnc-blog-archive .spnc-col-10, body .spnc-main-page .spnc-col-9, body .spnc-main-page .spnc-col-10 {
						order: 2;
					}
					body .spnc-single-post .spnc-col-7, body .spnc-single-post .spnc-col-8, body .spnc-index-blog .spnc-col-7, body .spnc-index-blog .spnc-col-8, body .spnc-blog-archive .spnc-col-7, body .spnc-blog-archive .spnc-col-8, body .spnc-main-page .spnc-col-7, body .spnc-main-page .spnc-col-8 {
						order: 1;
					}
				}
			</style>
			<?php
		}

		//Load sidebar widgets after the main content on mobile devices for pages
		if(get_theme_mod('page_sidebar_layout','right')=='left' || get_theme_mod('page_sidebar_layout','right')=='both' ) { 
			?>
			<style>
				@media (max-width: 1023px) {
					body.page .page-section-space .spnc-col-9 {
						order: 2;
					}
					body.page .page-section-space .spnc-col-7 {
						order: 1;
					}
					body.page .page-section-space .spnc-col-8 {
						order: -1;
					}
				}
			</style>
			<?php
		}

		if( get_theme_mod('single_blog_sidebar_layout','full')=='full' || get_theme_mod('single_blog_sidebar_layout','full')=='stretched') { ?>
			<style>
			@media (min-width:993px) and (max-width:1024px){
				  .spnc-related-posts.spnc-grid .spnc-related-post-wrapper .spnc-post{
				  flex: 0 1 calc(33.33% - 27px / 2);
				  max-width: calc(33.33% - 27px / 2);
				}
			}
			</style>
		<?php
		}
		
	}
	add_action('wp_head','newscrunch_sidebar_layout_fn');
endif;


/*
-------------------------------------------------------------------------------
 Preloader
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_preloader_feature' ) ) {

	function newscrunch_preloader_feature() {
		if(get_theme_mod('preloader_enable',false)==true):?>
			<div id="preloader1" class="newscrunch-loader">
		        <div class="spnc_wrap">
                   <div class="spnc_loading1">
                      <div class="spnc_bounceball"></div>
                      <div class="spnc_preloader_text"><?php esc_html_e('Loading Now','newscrunch'); ?></div>
                    </div>
                </div>
		    </div>
		  <?php endif;
	}
	add_action('newscrunch_preloader','newscrunch_preloader_feature');

}


/*
-------------------------------------------------------------------------------
 Page Navigation
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_custom_navigation')) :

    function newscrunch_custom_navigation() {
    	
    	if (!is_rtl()) {
            the_posts_pagination(array(
                'prev_text' => '<i class="fas fa-chevron-left"></i>',
                'next_text' => '<i class="fas fa-chevron-right"></i>',
            ));
        } else {
            the_posts_pagination(array(
                'prev_text' => '<i class="fas fa-chevron-right"></i>',
                'next_text' => '<i class="fas fa-chevron-left"></i>',
            ));
        }
    }
    add_action('newscrunch_page_navigation', 'newscrunch_custom_navigation');

endif;

/*
-------------------------------------------------------------------------------
 Wide and Boxed Layout & Highlight View
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_theme_layout')) :

	function newscrunch_theme_layout() {
		$newscrunch_highlight_view =get_theme_mod('newscrunch_highlight_view','front');
		$newscrunch_theme_layout = get_theme_mod('theme_layout', 'wide');
		$newscrunch_pstlayout_class="";
		if(get_theme_mod('newscrunch_single_post_layout','1') == '8'){
			$newscrunch_pstlayout_class = " spnc-blog-single-seven";
		}
		//for advertisement img
		$newscrunch_advertisement_item = get_theme_mod('advertisement_items');
		$newscrunch_advertisement_item = json_decode($newscrunch_advertisement_item);
		$newscrunch_ads = '';
		if (!empty($newscrunch_advertisement_item))
        { 
        	$newscrunch_before_header 		= []; // Initialize an empty before header array
        	$newscrunch_inside_header 		= []; // Initialize an empty inside header array
        	foreach($newscrunch_advertisement_item as $newscrunch_advertisement_items)
          	{ 
               $newscrunch_before_header_val = $newscrunch_inside_header_val =  '';

               //before header
                $newscrunch_before_header_val  		.=  $newscrunch_advertisement_items->before_header;
				$newscrunch_before_header[]      	 =  $newscrunch_before_header_val;

				//inside header
                $newscrunch_inside_header_val  		.=  $newscrunch_advertisement_items->inside_header;
				$newscrunch_inside_header[]     	 =  $newscrunch_inside_header_val;
			}

			$newscrunch_filtered_before_header_array = array_filter($newscrunch_before_header, function($value) {
			    return $value === "yes";
			});
			$newscrunch_filter_inside_header_array = array_filter($newscrunch_inside_header, function($value) {
			    return $value === "yes";
			});

			if ((!empty($newscrunch_filtered_before_header_array)) || (!empty($newscrunch_filter_inside_header_array)))
			{
				$newscrunch_ads = 'ads-img';
			}
			else
			{
				$newscrunch_ads = '';
			}
		}

		if ( class_exists( 'WooCommerce' ) ) 
	    {
	    	$newscrunch_wc_active="spnc-wc-active";
	    }
	    else
	    {
	    	$newscrunch_wc_active='';
	    }
		
	    if ($newscrunch_theme_layout == "boxed") {
	        $newscrunch_layout_type = "boxed ".$newscrunch_highlight_view. " ".$newscrunch_ads .$newscrunch_pstlayout_class. " ".$newscrunch_wc_active;
	    } 
	    else {
	        $newscrunch_layout_type = "wide ".$newscrunch_highlight_view. " ".$newscrunch_ads .$newscrunch_pstlayout_class. " ".$newscrunch_wc_active;
	    }?>
	    <body <?php body_class($newscrunch_layout_type); ?> <?php newcrunch_schema_attributes(); ?>>
	<?php }
	add_action('newscrunch_wide_boxed_layout','newscrunch_theme_layout');

endif;

/*
-------------------------------------------------------------------------------
 Added skip link focus
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_skip_link_fn')) :

	function newscrunch_skip_link_fn() { ?>
		<script>
		/(trident|msie)/i.test(navigator.userAgent)&&document.getElementById&&window.addEventListener&&window.addEventListener("hashchange",function(){var t,e=location.hash.substring(1);/^[A-z0-9_-]+$/.test(e)&&(t=document.getElementById(e))&&(/^(?:a|select|input|button|textarea)$/i.test(t.tagName)||(t.tabIndex=-1),t.focus())},!1);
		</script>
	<?php
	}
	add_action( 'wp_print_footer_scripts', 'newscrunch_skip_link_fn' );

endif;

/*
-------------------------------------------------------------------------------
 Set post views count using post meta
-------------------------------------------------------------------------------*/
if( ! function_exists( 'newscrunch_view' ) ) :
function newscrunch_view($postID) {
    $countKey = 'newscrunch_views';
    $count = get_post_meta($postID, $countKey, true);
    if($count==''){
        $count = 1;
        delete_post_meta($postID, $countKey);
        add_post_meta($postID, $countKey, $count);
    }else{
        $count++;
        update_post_meta($postID, $countKey, $count);
    }
}
endif;

/*
-------------------------------------------------------------------------------
 Set overlay slider loop
-------------------------------------------------------------------------------*/
function newscrunch_overlay_item($input,$bool=true){
    switch($input) {
        case in_array($input, range(0,4,1)):{
            $prnt = "1";
            break;
        }
        case in_array($input, range(5,8,1)):{
            $prnt = "2";
            break;
        }
        case in_array($input, range(9,12,1)):{
            $prnt = "3";
            break;
        }
        case in_array($input, range(13,16,1)):{
            $prnt = "4";
            break;
        }
        case in_array($input, range(17,20,1)):{
            $prnt = "5";
            break;
        }
        case in_array($input, range(21,24,1)):{
            $prnt = "6";
            break;
        }
        case in_array($input, range(25,28,1)):{
            $prnt = "7";
            break;
        }
        case in_array($input, range(29,32,1)):{
            $prnt = "8";
            break;
        }
        case in_array($input, range(33,36,1)):{
            $prnt = "9";
            break;
        }
        case in_array($input, range(37,40,1)):{
            $prnt = "10";
            break;
        }
        default: {
            $prnt = "5";
        }
    }
    if($bool){
        return $prnt;
    }else{
        return $prnt;
    }   
}


/*
-------------------------------------------------------------------------------
 Get content with fixed length
-------------------------------------------------------------------------------*/
function newscrunch_excerpt($word_limit) {
    $content = wp_strip_all_tags(get_the_content() , true );
    echo wp_trim_words($content, $word_limit);
}

/*
-------------------------------------------------------------------------------
 Get the post title
-------------------------------------------------------------------------------*/
function newscrunch_get_the_title($post_id) {
     echo '<a class="'.esc_attr(get_theme_mod('link_animate','a_effect1')).'" href="'.esc_url(get_permalink($post_id)).'" title="'.esc_attr(get_the_title($post_id)).'">'.esc_html(get_the_title($post_id)).'</a>';
}

/*
-------------------------------------------------------------------------------
 Get the author datails
-------------------------------------------------------------------------------*/
function newscrunch_get_author($post_id) {
	if (is_rtl()) { $dir='dir="rtl"';}else{ $dir='';}
	$author_id = get_post_field ('post_author', $post_id);
    $author_name = get_the_author_meta( 'display_name' , $author_id );
    echo '<a '.$dir.' href="'.esc_url(get_author_posts_url(get_post_field ('post_author', $post_id))).'" title="'.esc_attr__('Posts by', 'newscrunch') . ' ' . esc_attr($author_name) .'">';
    echo esc_html($author_name).'</a>';
}

/*
-------------------------------------------------------------------------------
 Get the categories
-------------------------------------------------------------------------------*/
function newscrunch_get_catgories($post_id) {
	if (is_rtl()) { $dir='dir="rtl"';}else{ $dir='';}
    $category_detail=get_the_category($post_id);
	foreach($category_detail as $cd)
	{
		echo '<a '.$dir.' href="' . esc_url( get_category_link( $cd->term_id ) ) . '" title="'.esc_attr($cd->cat_name).'">'.esc_html($cd->cat_name).'  '.'</a>';
	}
}

/*
-------------------------------------------------------------------------------
 Get the featured image
-------------------------------------------------------------------------------*/
function newscrunch_get_the_featured_image ($post_id) {
    $image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full', false, '' );
    if(isset($image[0]))
    {
    echo '<img src="'.esc_url($image[0]).'" class="img-fluid sp-thumb-img" alt="'.get_the_title($post_id).'">';
	}
	else
	{
		echo '<img class="img-fluid sp-thumb-img" src="'.esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ).'/assets/images/no-preview.jpg" alt="'.get_the_title($post_id).'">';
	}
}

/*
-------------------------------------------------------------------------------
 Category color
-------------------------------------------------------------------------------*/
if( ! function_exists( 'newscrunch_get_categories' ) ) :
   
    function newscrunch_get_categories( $post_id ) {
    	$categories = get_the_category();
		if ( ! empty( $categories ) ) {
			foreach ( $categories as $cat ) { 
		  		$category_id = get_cat_ID( $cat->name );
		  		// old user
		  		if(!empty(get_theme_mod('newscrunch_category_'.$cat->slug))) { 
		  			if (preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $cat->slug)) { $spnc_cid=$cat->term_id; }
		  			else { $spnc_cid=$cat->slug; }?>
		  			<a href="<?php echo esc_url(get_category_link( $category_id ));?>" class="newscrunch_category_<?php echo esc_attr($spnc_cid);?>" title="<?php echo esc_attr($cat->name); ?>"><?php echo esc_html($cat->name);?></a>
		  		<?php }
		  		//new user
		  		else { ?>
		  			<a href="<?php echo esc_url(get_category_link( $category_id ));?>" class="newscrunch_category_<?php echo esc_attr($cat->term_id);?>" title="<?php echo esc_attr($cat->name); ?>"><?php echo esc_html($cat->name);?></a>
		  		<?php }
		  	}
		}
    }
endif;


/*
-------------------------------------------------------------------------------
Header Toggle Sidebar Widgets
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_side_panel_widget_area' ) ) {

  /**
   * Header Toggle Sidebar Widgets
   *
   */
  function newscrunch_side_panel_widget_area( $sidebar_id ) {

    if ( is_active_sidebar( $sidebar_id ) ) {
      dynamic_sidebar( $sidebar_id );
    } elseif ( current_user_can( 'edit_theme_options' ) ) {

      global $wp_registered_sidebars;
      $sidebar_name = '';
      if ( isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
        $sidebar_name = $wp_registered_sidebars[ $sidebar_id ]['name'];
      }
      ?>
      <div class="widget spnc-no-widget-row">
        <h2 class='widget-title'><?php echo esc_html( $sidebar_name ); ?></h2>

        <p class='no-widget-text'>
          <a href='<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>' title='<?php esc_attr_e('Add Header Toggle Sidebar Widgets Area','newscrunch'); ?>'>
            <?php esc_html_e( 'Add Header Toggle Sidebar Widgets Area.', 'newscrunch' ); ?>
          </a>
        </p>
      </div>
      <?php
    }
  }
}


/*
-------------------------------------------------------------------------------
Breadcrumb Styling
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_breadcrumb_css')) :

	function newscrunch_breadcrumb_css() {
		if( get_theme_mod('breadcrumb_banner_enable', true) == false): ?> 
            <style type="text/css">
                .header-sidebar{
                   position: relative;
                }
            </style>
	    <?php endif;
	    if(is_home() && !is_front_page()): ?>
		    <style type="text/css">
		        .header-sidebar{
		           position: relative;
		        }
		    </style>
	    <?php endif;
		if(is_front_page() &&  !is_home() ): ?>
		    <style type="text/css">
		        body.home.newscrunch .header-sidebar{
		           position: absolute;
		        }
		    </style>
	    <?php endif;
    	if (get_theme_mod('breadcrumb_section_padding', false) == true): ?>
        	<style type="text/css">
        		@media (min-width:1100px){
        		.page-title-section{
        			padding-top:<?php echo intval(get_theme_mod('breadcrumb_top_padding',260))?>px !important;
      				padding-right:<?php echo intval(get_theme_mod('breadcrumb_right_padding',0))?>px;
  					padding-bottom:<?php echo intval(get_theme_mod('breadcrumb_bottom_padding',30))?>px;
      				padding-left:<?php echo intval(get_theme_mod('breadcrumb_left_padding',0))?>px;
        		}
        	}
        	</style>
    	<?php endif;
    	if (get_theme_mod('breadcrumb_overlay_enable', false) == true):
    		if( get_header_image() ){ ?>
	    		<style type="text/css">
	        		.breadcrumb-overlay{
						  position: absolute;
						  top: 0;
						  bottom: 0;
						  left: 0;
						  right: 0;
						  height: 100%;
						  width: 100%;
						  background-color: rgba(0, 0, 0, 0.3); 
					}
	        	</style>
    		<?php } 
    	endif;
	}
	add_action('wp_head','newscrunch_breadcrumb_css');
endif;

/* ---------------------------------------------- /*
 * Convert dark layout & Search Full Screen
/* ---------------------------------------------- */
function newscrunch_footerscript() {
	if ( class_exists('Newscrunch_Plus') ){
		if(get_theme_mod('spncp_skin_mode','spnc_light') == "spnc_light")
		{
			$spncp_skin_mode='spnc_light';
		}
		else
		{
			$spncp_skin_mode='spnc_dark';
		}
	}
	else
	{
		$spncp_skin_mode='spnc_light';
	}	
?>
<script>     
        let spncStore = 'spnc-theme';
        var spncp_skin_mode = '<?php echo $spncp_skin_mode;?>';
        let spncGetColor = function () {
            if (localStorage.getItem(spncStore)) {
                return localStorage.getItem(spncStore)
            }
            else {
            	if(spncp_skin_mode == 'spnc_light')
            	{
                return window.matchMedia('(spnc-color-scheme: spnc_dark)').matches ? 'spnc_dark' : 'spnc_light';
                }
                else
                {
                return window.matchMedia('(spnc-color-scheme: spnc_dark)').matches ? 'spnc_light' : 'spnc_dark';	
                }    
            }
        }


        let theme = {
            value: spncGetColor()
        };


        let setPreference = function () {
            localStorage.setItem(spncStore, theme.value);
            reflectPreference();
        }


        if(jQuery('.custom-logo')[0] ){
        var img1 = document.querySelector('.custom-logo').src;
        var img2 = document.querySelector('.dark-custom-logo').src;
        }


        let reflectPreference = function () {
            document.firstElementChild.setAttribute("data-theme", theme.value);
            document.querySelector("#spnc-layout-icon")?.setAttribute("aria-label", theme.value);
            document.querySelector("#spnc-layout-icon-2")?.setAttribute("aria-label", theme.value);
            if(jQuery('.custom-logo')[0] ){
            let logoImageUrl = document.querySelector('img.custom-logo');
            let logoImageUrl1 = document.querySelector('#spnc-menu-open img.custom-logo');
            logoImageUrl.src = theme.value === 'spnc_light' ? img1 : img2;
            logoImageUrl.srcset=theme.value === 'spnc_light' ? img1 : img2;
            logoImageUrl1.src = theme.value === 'spnc_light' ? img1 : img2;
            logoImageUrl1.srcset=theme.value === 'spnc_light' ? img1 : img2;
           }
            let toggleBtn1 = document.querySelector("#spnc-layout-icon");
            let iconCode = toggleBtn1.querySelector(".fa-solid");
            iconCode.className = theme.value === 'spnc_light' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
        }


        // set early so no page flashes / CSS is made aware
        reflectPreference();
        window.addEventListener('load', function () {
            reflectPreference();
            let toggleBtn = document.querySelector("#spnc-layout-icon");
            if (toggleBtn) {
                toggleBtn.addEventListener("click", function (event) {
                	event.preventDefault();
                	if(spncp_skin_mode == 'spnc_light')
                	{
                	theme.value = theme.value === 'spnc_light' ? 'spnc_dark' : 'spnc_light';			
                	}
                	else
                	{
                		theme.value = theme.value === 'spnc_dark' ? 'spnc_light' : 'spnc_dark';
                	}
                    
                    setPreference();
                });
            }

            let toggleBtn2 = document.querySelector("#spnc-layout-icon-2");
	        if (toggleBtn2) {
	            toggleBtn2.addEventListener("click", function () {
	                event.preventDefault();
	                	if(spncp_skin_mode == 'spnc_light')
	                	{
	                	theme.value = theme.value === 'spnc_light' ? 'spnc_dark' : 'spnc_light';			
	                	}
	                	else
	                	{
	                		theme.value = theme.value === 'spnc_dark' ? 'spnc_light' : 'spnc_dark';
	                	}
	                    
	                    setPreference();

	            });
	        }
        });

        
        // sync with system changes
        window.matchMedia('(spnc-color-scheme: spnc_light)')
            .addEventListener('change', ({matches: isDark}) => {
                theme.value = isDark ? 'spnc_light' : 'spnc_dark';
                setPreference();
        });     
</script>
<?php
if( get_theme_mod('hide_show_search_icon',true ) == true ) { ?>
			<script>
				// search   
		        const search_elm = document.getElementById('searchbar_fullscreen');
		            search_elm.addEventListener('focusout', (event) => {
		                if(search_elm.contains(event.relatedTarget)==false){
		                    jQuery("#searchbar_fullscreen").removeClass('open');
		            }
		        });
			</script>
	<?php }
 }
add_action('newscrunch_script_footer', 'newscrunch_footerscript');


/* ---------------------------------------------- /*
 * Check dark & light icon
/* ---------------------------------------------- */
if (!function_exists('newscrunch_hide_show_dark_light_icon')) :
	function newscrunch_hide_show_dark_light_icon() {
		if( get_theme_mod('hide_show_dark_light_icon',true ) == false ) { ?>
			<style>
				.menu-item.spnc-dark-layout{ display: none;}
			</style>
	<?php }
	}
	add_action('wp_head','newscrunch_hide_show_dark_light_icon');
endif;



/* ---------------------------------------------- /*
 * Single Post Layout
/* ---------------------------------------------- */

if ( ! function_exists( 'newscrunch_single_post_layout' ) ) {
	function newscrunch_single_post_layout() {
		if(get_theme_mod('newscrunch_single_post_layout','1') == '2')
		{ ?>
			<div class="spnc-row">
				<div class="spnc-col-1">
	                <div class="spnc-post-overlay">
	                <?php 
	                if(has_post_thumbnail()): ?>
	                    <figure class="spnc-post-thumbnail">
	                    <?php the_post_thumbnail('full', array('class'=>'spnc-full-img img-fluid', 'loading' => false, 'itemprop'=>'image' )); ?>
	                    </figure>
	                <?php endif; ?>
	                </div>
	            </div>
	        </div>
		<?php }	

		if(get_theme_mod('newscrunch_single_post_layout','1') == '5')
			{ ?>
			<div class="spnc-row">
					<div class="spnc-col-1 ">
						<div class="spnc-blog-wrapper">
							<div class="spnc-post-overlay">
								<?php 
				                if(has_post_thumbnail()): ?>
				                    <figure class="spnc-post-thumbnail">
				                    <?php the_post_thumbnail('full', array('class'=>'spnc-full-img img-fluid', 'loading' => false, 'itemprop'=>'image' )); ?>
				                    </figure>
				                <?php endif; ?>
								<header class="entry-header">
								<?php
					        	if(get_theme_mod('newscrunch_enable_single_post_categories',true)==true):
						        	if ( has_category() ) :
										echo '<span itemprop="about" class="spnc-cat-links spnc-entry-meta">';
										 newscrunch_get_categories(get_the_ID());
										echo '</span>';
									endif; 
								endif; ?>

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
									<div class="spnc-entry-meta">
										<?php if ( get_theme_mod('newscrunch_enable_single_post_author',true) == true ) :?>
										<div>
											<span class="spnc-author">
											    <figure>
											        <?php
											        // Get the author's ID and avatar URL
											        $newscrunch_author_id = get_the_author_meta('ID');
											        $newscrunch_avatar_url = get_avatar_url($newscrunch_author_id);

											        // Output the avatar image
											        ?>
											        <img src="<?php echo esc_url($newscrunch_avatar_url); ?>" class="img-fluid sp-thumb-img" alt="<?php esc_attr_e('author-image','newscrunch'); ?>">
											    </figure>
											</span>	
										</div>
										<?php endif; ?>
										<div>
											<?php if ( get_theme_mod('newscrunch_enable_single_post_author',true) == true ) :?>
											<span class="spnc-author">
												<?php
											    // Get the author's display name and author page URL
											    $newscrunch_author_name = get_the_author();
											    $newscrunch_author_url = get_author_posts_url($newscrunch_author_id);

											    // Output the author's name as a link to their author page
											    ?>
											    <a itemprop="url" href="<?php echo esc_url($newscrunch_author_url); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>"><?php echo esc_html($newscrunch_author_name); ?></a>
											</span>
											<?php endif; ?>
											<!-- Post Tag -->
											<?php
								        	if(get_theme_mod('newscrunch_enable_single_post_tag',true)==true):
												if(has_tag()):
													echo '<span class="spnc-tag-links"><i class="fas fa fa-tags"></i>';
												 	the_tags('',', ');
												 	echo'</span>';	
											 	endif;
											endif; ?> 
											<!-- Post Comments -->
											<?php
											if ( get_theme_mod('newscrunch_enable_single_post_comment',true) == true ) :?>
											<span class="spnc-comment-links">
				                                <i class="fa-solid fa-comment"></i>
				                                <a itemprop="url" href="<?php the_permalink(); ?>#respond" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>"><?php echo esc_html(get_comments_number()) . ' ' . esc_html(get_theme_mod('newscrunch_blog_archive_comments',__('Comments','newscrunch'))); ?></a>
				                            </span>
				                            <?php endif; ?> 
											<!-- Post Date -->
								    		<?php
											if ( get_theme_mod('newscrunch_enable_single_post_date',true) == true ) :?>
									            <span class="single spnc-date">	
									            	<i class="fas fa-solid fa-clock"></i>
													<?php echo newcrunch_post_date_time(get_the_ID()); ?>
												</span>
											<?php endif; 
											do_action( 'spncp_post_count_hook' );
											?>
											<!-- Post Date -->
										</div>
									</div>
								</header>
							</div>
						</div>
					</div>
				</div>
		<?php }	

		if(get_theme_mod('newscrunch_single_post_layout','1') == '7')
			{ ?>
			<div class="spnc-row">
					<div class="spnc-col-1 ">
						<div class="spnc-blog-wrapper">
							<div class="spnc-post-overlay">
								<header class="entry-header">
								  	<!-- Post Date -->
						    		<?php
									if ( get_theme_mod('newscrunch_enable_single_post_date',true) == true ) :?>
							            <span class="single spnc-date">	
											<?php echo newcrunch_post_date_time(get_the_ID()); ?>
										</span>
									<?php endif; ?>
									<!-- Post Date -->

									<?php
						        	if(get_theme_mod('newscrunch_enable_single_post_categories',true)==true):
							        	if ( has_category() ) :
											echo '<span itemprop="about" class="spnc-cat-links spnc-entry-meta">';
											 newscrunch_get_categories(get_the_ID());
											echo '</span>';
										endif; 
									endif; ?>
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
									<div class="spnc-entry-meta">
										<?php if ( get_theme_mod('newscrunch_enable_single_post_author',true) == true ) :?>
										<div>
											<span class="spnc-author">
											    <figure>
											        <?php
											        // Get the author's ID and avatar URL
											        $newscrunch_author_id = get_the_author_meta('ID');
											        $newscrunch_avatar_url = get_avatar_url($newscrunch_author_id);

											        // Output the avatar image
											        ?>
											        <img src="<?php echo esc_url($newscrunch_avatar_url); ?>" class="img-fluid sp-thumb-img" alt="<?php esc_attr_e('author-image','newscrunch'); ?>">
											    </figure>
											</span>	
										</div>
										<?php endif; ?>
										<div>
											<?php if ( get_theme_mod('newscrunch_enable_single_post_author',true) == true ) :?>
											<span class="spnc-author">
												<?php
											    // Get the author's display name and author page URL
											    $newscrunch_author_name = get_the_author();
											    $newscrunch_author_url = get_author_posts_url($newscrunch_author_id);

											    // Output the author's name as a link to their author page
											    ?>
											    <a itemprop="url" href="<?php echo esc_url($newscrunch_author_url); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>"><?php echo esc_html($newscrunch_author_name); ?></a>
											</span>
											<?php endif; ?>
											<!-- Post Tag -->
				                            <?php	
								        	if(get_theme_mod('newscrunch_enable_single_post_tag',true)==true):
												if(has_tag()):
													echo '<span class="spnc-tag-links">';
												 	the_tags('',', ');
												 	echo'</span>';	
											 	endif;
											endif;   
											if ( get_theme_mod('newscrunch_enable_single_post_comment',true) == true ) :?>
											<span class="spnc-comment-links">
				                                <a itemprop="url" href="<?php the_permalink(); ?>#respond" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>"><?php echo esc_html(get_comments_number()) . ' ' . esc_html(get_theme_mod('newscrunch_blog_archive_comments',__('Comments','newscrunch'))); ?></a>
				                            </span>
											<?php endif; 
											do_action( 'spncp_post_count_hook' );
											?>	
										</div>
									</div>
								</header>
								<?php 
				                if(has_post_thumbnail()): ?>
				                    <figure class="spnc-post-thumbnail">
				                    <?php the_post_thumbnail('full', array('class'=>'spnc-full-img img-fluid', 'loading' => false, 'itemprop'=>'image' )); ?>
				                    </figure>
				                <?php endif; ?>

				              

							</div>
						</div>
					</div>
				</div>
		<?php }
	}
	add_action( 'newscrunch_single_layout', 'newscrunch_single_post_layout' );
}

/*
-------------------------------------------------------------------------------
 Post Widgets
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_sleft_widget_area' ) ) {
	function newscrunch_sleft_widget_area( $sidebar_id ) {
	if ( is_active_sidebar( $sidebar_id ) ) {
		dynamic_sidebar( $sidebar_id );
    } 
    elseif ( current_user_can( 'edit_theme_options' ) ) {
		global $wp_registered_sidebars;
		$sidebar_name = '';
		if ( isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			$sidebar_name = $wp_registered_sidebars[ $sidebar_id ]['name'];
		}
  		?>
		<div class="widget spnc-no-widget-row">
			<p class='no-widget-text'>
				<a target="_blank" href='<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>' title='<?php esc_attr_e('Add widgets in Primary Left Area','newscrunch'); ?>'>
					<?php esc_html_e( 'Add widgets in Primary Left Area.', 'newscrunch' ); ?>
				</a>
			</p>
		</div>
      	<?php }
	}
}


/* ---------------------------------------------- /*
 * Single Post Navigation
/* ---------------------------------------------- */

function newscrunch_single_posts_nav(){
    $next_post = get_next_post();
    $prev_post = get_previous_post();
  
    if ( $next_post || $prev_post ) : ?>
     
		<article class="spnc-pagination-single paginatn_desgn-1">
			<?php if ( ! empty( $prev_post ) ) : ?>
			<div class="spnc-post-previous">
				<div class="spnc-post-content">
					<a href="<?php echo esc_url(get_permalink( $prev_post )); ?>" title="<?php esc_attr_e('Previous post','newscrunch'); ?>"><?php esc_html_e('Previous post','newscrunch');?></a>
					<h4 class="spnc-entry-title">
						<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php echo esc_url(get_permalink( $prev_post )); ?>" title="<?php echo esc_attr(get_the_title( $prev_post )); ?>"><?php echo esc_html(get_the_title( $prev_post )); ?></a>
					</h4>
				</div>
				<a href="<?php echo esc_url(get_permalink( $prev_post )); ?>" class="spnc_prvs_arrow" title="<?php esc_attr_e('Previous post arrow','newscrunch'); ?>"><i class="fa-solid fa-angle-left"></i></a>
			</div>
			<?php endif; 
			if ( ! empty( $next_post ) ) : ?>
			<div class="spnc-post-next">
				<div class="spnc-post-content">
					<a href="<?php echo esc_url(get_permalink( $next_post )); ?>" title="<?php esc_attr_e('Next post','newscrunch'); ?>"><?php esc_html_e('Next post','newscrunch');?></a>
					<h4 class="spnc-entry-title">
						<a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php echo esc_url(get_permalink( $next_post )); ?>" title="<?php echo esc_attr(get_the_title( $next_post )); ?>"><?php echo esc_html(get_the_title( $next_post )); ?></a>
					</h4>
				</div>
				<a href="<?php echo esc_url(get_permalink( $next_post )); ?>" class="spnc_nxt_arrow" title="<?php esc_attr_e('Next post arrow','newscrunch'); ?>"><i class="fa-solid fa-angle-right"></i></a>
			</div>
			<?php endif; ?>
		</article>
                          
    <?php endif;
}

/* =============================================================
    *                    Related Post
  ================================================================ */

function newscrunch_single_post_related() {

   newscrunch_single_posts_nav(); 

   $newscrunch_releted_posts_title = get_theme_mod('newscrunch_related_post_title', __('Related Posts', 'newscrunch' ) );
   
    if(get_theme_mod('newscrunch_enable_related_post',true) == true):

    // Get the current post's ID
	$current_post_id = get_the_ID();
	// Get the categories of the current post
	$categories = get_the_category($current_post_id);
	if ($categories!=null) {
		$category_ids = array();
		foreach ($categories as $category) {
			$category_ids[] = $category->term_id;
		}
	}
	$args = array(
		'ignore_sticky_posts' => 1,
		'post__not_in' => array($current_post_id), // Exclude the current post
		'category__in' => $category_ids, // Include posts from the same categories
		'posts_per_page' => 3,
	);

	$query_args = new WP_Query($args); 
      
	if ($query_args->have_posts()) : ?>
	<div class="spnc-related-posts spnc-grid">
	    <div class="spnc-main-wrapper">
	        <div class="spnc-main-wrapper-heading">
	            <h3 class="widget-title"><?php echo esc_html($newscrunch_releted_posts_title);?></h3>
	        </div>
	    </div>
	    <div class="spnc-related-post-wrapper">
	        <?php while ($query_args->have_posts()) : $query_args->the_post(); ?>
	        <article class="spnc-post">
	        <?php if(has_post_thumbnail()):?>
	            <figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
	                <a href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_post_thumbnail('full',array('class'=>'img-fluid sp-thumb-img'));?></a>
	            </figure>
	        <?php endif; ?>       
	            <div class="spnc-post-content">
		            <div class="spnc-content-wrapper">
		                <div class="spnc-post-wrapper">
		                    <header class="spnc-entry-header">
		                        <div class="spnc-entry-meta">
		                            <span class="spnc-author"><i
		                                    class="fa-solid fa-circle-user"></i>
		                                    <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>">
		        								<?php echo esc_html(get_the_author()); ?>
		        							</a>	
		                            </span>
		                            <span class="comment-links"><i class="fa-solid fa-message"></i>
		                                <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> itemprop="url" href="<?php the_permalink(); ?>" title="<?php esc_attr_e('Number of Comments','newscrunch'); ?>">
		                            <?php echo esc_html(get_comments_number()); ?>
		                            	</a>
		                            </span>
		                        </div>
		                        <h3 class="spnc-entry-title">
		                            <a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" itemprop="url" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
		                        </h3>
		                    </header>
		                    <div class="spnc-entry-content">
		                        <div class="spnc-footer-meta">
		                            <div class="spnc-entry-meta">
		                                <span class="spnc-date"><i class="fa-solid fa-clock"></i>
		                                    <?php echo newcrunch_post_date_time(get_the_ID()); ?>
		                                </span>
		                            </div>
		                        </div>
		                    </div>
	                	</div>
	            	</div>
	        	</div>
	        </article>
	    <?php 
	        endwhile;
	     	wp_reset_postdata(); ?>
	    </div>
	</div>
<?php  
    endif; 
endif;
}
add_action('newscrunch_single_post_hook','newscrunch_single_post_related');


/*
-------------------------------------------------------------------------------
 Logo /Dark Logo / Title / Site Title
-------------------------------------------------------------------------------*/

if ( ! function_exists( 'newscrunch_add_logo' ) ) {
	function newscrunch_add_logo() {
	?>
		<div class="spnc-header">
			<?php if(has_custom_logo()){ the_custom_logo(); }
		 	if(get_theme_mod('dark_header_logo')){ ?>
				<!-- Dark Layout logo -->
				<a href="<?php echo esc_url(home_url('/'));?>" class="dark-custom-logo-link " rel="home" aria-current="page" itemprop="url" title="<?php bloginfo('name'); ?>">
					<img width="220" height="120" src="<?php echo esc_url( get_theme_mod('dark_header_logo')); ?>" class="dark-custom-logo" alt="<?php bloginfo( 'name' ); ?>" style="display: none;" itemprop="image">
				</a>
		 	<?php
			} else{ 
				if(has_custom_logo()){
					$newscrunch_logo_id = get_theme_mod( 'custom_logo' );
					$newscrunch_logo_image = wp_get_attachment_image_src( $newscrunch_logo_id , 'full' );
					?>
					<a href="<?php echo esc_url(home_url('/'));?>" class="dark-custom-logo-link " rel="home" aria-current="page" itemprop="url" title="<?php bloginfo('name'); ?>">
						<img width="220" height="120" src="<?php echo esc_url($newscrunch_logo_image[0]); ?>" class="dark-custom-logo" alt="<?php bloginfo( 'name' ); ?>" style="display: none;" itemprop="image">
					</a>
			<?php
				}
			}
			if( (get_theme_mod('header_text') == '') || (get_theme_mod('header_text') == true) ):
			$newscrunch_site_title 	= get_theme_mod('hide_show_site_title',true); 
			$newscrunch_site_tagline 	= get_theme_mod('hide_show_site_tagline',true);
			if( ((get_option('blogname')!='') || (get_option('blogdescription')!='' )) && ( ($newscrunch_site_title == true) || ($newscrunch_site_tagline == true )) ) {
			?>
			<div class="custom-logo-link-url">
				<?php if(get_option('blogname')!='' && ( $newscrunch_site_title == true ) ) { ?>
					<h2 class="site-title" itemprop="name">
						<a class="site-title-name" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" itemprop="url" title="<?php bloginfo('name'); ?>"><?php bloginfo( 'name' ); ?></a>
					</h2>
				<?php
				}
				if(get_option('blogdescription')!='' && ( $newscrunch_site_tagline == true ) ) {
					$newscrunch_description = get_bloginfo( 'description', 'display' );
					if ( $newscrunch_description || is_customize_preview() ) : ?>
						<p class="site-description" itemprop="description"><?php echo $newscrunch_description; ?></p>
					<?php endif;
				} ?>
			</div>
			<?php } endif; ?>
		</div>	
	<?php 
	}
	add_action( 'newscrunch_header_logo', 'newscrunch_add_logo' );
}

/*
-------------------------------------------------------------------------------
 Breadcrumb Variations
-------------------------------------------------------------------------------*/

if ( ! function_exists( 'newscrunch_breadcrumbs_fn' ) ) {
	function newscrunch_breadcrumbs_fn() {
		if(class_exists('Newscrunch_Plus'))
		{
			if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
				if(get_theme_mod('bredcrumb_style',1) == 1)
				{
					//style 1 
					do_action( 'newscrunch_breadcrumbs_hook' );
				}
				else
				{
					//style 2
					do_action( 'newscrunch_breadcrumbs_hook2' );
				}
			}
			else
			{
				if(get_theme_mod('bredcrumb_style',2) == 2)
				{
					//style 2
					do_action( 'newscrunch_breadcrumbs_hook2' );
				}
				else
				{
					//style 1
					do_action( 'newscrunch_breadcrumbs_hook' );
				}
			}
		}
		else
		{
			if ('Newscrunch' == wp_get_theme() || 'Newscrunch Child' == wp_get_theme() || 'Newscrunch child' == wp_get_theme() ) {
				//style 1 
				do_action( 'newscrunch_breadcrumbs_hook' );
			}
			else {
				//style 2
			 	do_action( 'newscrunch_breadcrumbs_hook2' );
			}	
		}
		
	}
	add_action( 'newscrunch_breadcrumbs_filter', 'newscrunch_breadcrumbs_fn' );
}

/*
-------------------------------------------------------------------------------
 Retrieve Youtube Video Id
-------------------------------------------------------------------------------*/
function newscrunch_get_youtube_videoId($url) {
    $pattern = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i';
    preg_match($pattern, $url, $matches);
    return isset($matches[1]) ? $matches[1] : false;
}

/*
-------------------------------------------------------------------------------
 Retrieve Vimeo Video Id
-------------------------------------------------------------------------------*/
function newscrunch_get_vimeo_videoId($url) {
    $pattern = '/https?:\/\/(www\.)?vimeo\.com\/(\d+)(?:$|\/|\?)/';
    if (preg_match($pattern, $url, $matches)) {
        return $matches[2];
    }
    return null;
}

/*
-------------------------------------------------------------------------------
 Check Youtube URL 
-------------------------------------------------------------------------------*/
function newscrunch_is_youtube_url($url) {
    // Regular expression to match YouTube URLs
    $pattern = '/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/';

    // Use preg_match to check if the URL matches the pattern
    if (preg_match($pattern, $url)) {
        return 'youtube';
    } else {
        return 'vimeo';
    }
}

/*
-------------------------------------------------------------------------------
 Retrieve Vimeo URL 
-------------------------------------------------------------------------------*/
function newscrunch_get_vimeoid($url) {
    $regex = '/(?:https?:\/\/)?(?:www\.)?(?:vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/(?:[^\/]*)\/videos\/|album\/(?:\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?))/';
    if (preg_match($regex, $url, $matches)) {
        return $matches[1];
    }
    return false; // Return false if no match found
}


/*
-------------------------------------------------------------------------------
 News Highlight Section Hook
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_highlight_views_hook' ) ) {
	function newscrunch_highlight_views_hook() {
		if(get_theme_mod('highlight_layout','1') =='1')
		{
		    get_template_part( 'partials/front/news-highlight-1');
		}
		else
		{
		   get_template_part( 'partials/front/news-highlight-2'); 
		}
	}
	add_action( 'newscrunch_highlight_views_hook', 'newscrunch_highlight_views_hook' );
}

/*
-------------------------------------------------------------------------------
 News Highlight Section
-------------------------------------------------------------------------------*/
function newscrunch_highlight_views($file = '') {
	//Display in all pages
	if(get_theme_mod('newscrunch_highlight_view','front')=="all")
	{	
		do_action('newscrunch_highlight_views_hook');
	}
	else
	{
		//Display in front page only
		if((get_theme_mod('newscrunch_highlight_view','front')=="front") && (($file=="front")))
		{
			do_action('newscrunch_highlight_views_hook');
		}
		//Display in index page only
		else if(($file=="index") && (get_theme_mod('newscrunch_highlight_view','front')=="front"))
		{
			do_action('newscrunch_highlight_views_hook');
		}
		//Display in index page only
		else if(($file=="index") && (get_theme_mod('newscrunch_highlight_view','front')=="inner"))
		{
			do_action('newscrunch_highlight_views_hook');
		}
		//Display in inner page only
		else if(($file=="inner") && (get_theme_mod('newscrunch_highlight_view','front')=="inner"))
		{
			do_action('newscrunch_highlight_views_hook');
		}
		else if(get_theme_mod('newscrunch_highlight_view','front')=="none")
		{
			return null;
		}
	}
	
}

/*
-------------------------------------------------------------------------------
 Enabe Disble Sticky Sidebar
-------------------------------------------------------------------------------*/
/*  Blog/Archives Sticky Sidbear */
function newscrunch_blog_stickysidebar()
{
	if(get_theme_mod('blog_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

function newscrunch_blog_stickycontent()
{
	if(get_theme_mod('blog_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

/*  Page Sticky Sidbear */
function newscrunch_single_post_stickysidebar()
{
	if(get_theme_mod('single_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}
function newscrunch_single_post_stickycontent()
{
	if(get_theme_mod('blog_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

/*  Page Sticky Sidbear */
function newscrunch_single_page_stickysidebar()
{
	if(get_theme_mod('page_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}
function newscrunch_single_page_stickycontent()
{
	if(get_theme_mod('page_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

/*  Front Left Content  */
function newscrunch_page_widget1_sidebar_sticky()
{
	if(get_theme_mod('page_widget1_sidebar_sticky',false) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}
function newscrunch_page_widget1_sidebar_stickycontent()
{
	if(get_theme_mod('page_widget1_sidebar_sticky',false) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

 /* Front Right Content */
 function newscrunch_page_widget2_sidebar_sticky()
{
	if(get_theme_mod('page_widget2_sidebar_sticky',false) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}
 function newscrunch_page_widget2_sidebar_stickycontent()
{
	if(get_theme_mod('page_widget2_sidebar_sticky',false) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

/* Woocommerce Content */
 function newscrunch_wc_sidebar_sticky()
{
	if(get_theme_mod('wc_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-sidebar';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}
function newscrunch_wc_stickycontent()
{
	if(get_theme_mod('wc_sidebar_sticky',true) == true)
	{
		$sticky ='spnc-sticky-content';
	}
	else
	{
		$sticky='';
	}
	return $sticky;
}

/*
-------------------------------------------------------------------------------
 Edit link button for single posts and pages
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_edit_link_button')) :
    function newscrunch_edit_link_button($view = 'default')
    {
        global $post;
            edit_post_link(
                sprintf(
                    wp_kses(
                    /* translators: %s: Name of current post. Only visible to screen readers */
                        __('Edit <span class="screen-reader-text">%s</span>', 'newscrunch'),
                        array(
                            'span' => array(
                                'class' => array(),
                            ),
                        )
                    ),
                    get_the_title()
                ),
                '<span class="spnc-edit-link"><i class="fas fa-edit"></i>',
                '</span>'
            );
    } 
endif;


/*
-------------------------------------------------------------------------------
 Retrieve video from post
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_get_video_from_post' ) ) :
	function newscrunch_get_video_from_post( $post_id = null ) {

		$post    = get_post( $post_id );
		$content = do_shortcode( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$embeds  = apply_filters( 'newscrunch_get_post_video', get_media_embedded_in_content( $content ) );

		if ( empty( $embeds ) ) {
			return '';
		}

		// Return first embedded item that is a video format.
		foreach ( $embeds as $embed ) {
			if ( strpos( $embed, 'video' ) || strpos( $embed, 'youtube' ) || strpos( $embed, 'vimeo' ) ) {
				return $embed;
			}
		}
	}
endif;

/*
-------------------------------------------------------------------------------
 Retrieve first video from post
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_get_first_video_url' ) ) :
	function newscrunch_get_first_video_url( $post_id = null ) {

		if(empty(newscrunch_get_video_from_post( $post_id )))
		{
			return '';
		}
		$embed = newscrunch_get_video_from_post( $post_id );
		// Find the first video URL in the content
		preg_match( '/https?:\/\/\S+(\.mp4|\.webm|\.ogv|youtube\.com\/embed\/\S+|vimeo\.com\/video\/\S+)/', $embed, $matches );

		$video_link = false;

		if ( ! empty( $matches[1] ) ) {
			$video_link = $matches[1];
		} elseif ( ! empty( $matches[2] ) ) {
			$video_link = $matches[2];
		}

		return $video_link;
	}
endif;


/*
-------------------------------------------------------------------------------
 Retrieve VideoId from embed code
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_embed_videoId' ) ) :

	function newscrunch_embed_videoId( $url = null ) {
		

		// Use regular expression to extract the video ID
		preg_match('/\/video\/(\d+)/', $url, $matches);

		// Check if a match was found
		if (isset($matches[1])) {
		    $video_id = $matches[1];
		    return $video_id;
		} 

	}
endif;

/*
-------------------------------------------------------------------------------
 Extract the video ID and convert to standard YouTube URL
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_yconvertEmbedUrlToStandardUrl' ) ) :
	function newscrunch_yconvertEmbedUrlToStandardUrl($embed_url) {
	    // Use regular expression to extract the video ID from the embed URL
	    if (preg_match('/\/embed\/([a-zA-Z0-9_-]+)/', $embed_url, $matches)) {
	        $video_id = $matches[1];
	        // Construct the standard YouTube URL
	        return 'https://www.youtube.com/watch?v=' . $video_id;
	    } else {
	        // Return an error or empty if no video ID is found
	        return null;
	    }
	}
endif;


/*Post Author*/

function newscrunch_get_author_name($post) {

    $user_id = $post->post_author;
    if (empty($user_id)) {
        return;
    }
    $user_info = get_userdata($user_id);
    echo esc_html($user_info->display_name);
}

/*
-------------------------------------------------------------------------------
 Single Post Author Details
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_single_post_auth')) :

	function newscrunch_single_post_auth() {
		if (get_theme_mod('newscrunch_enable_single_post_admin_details', true) === true):
		global $post;
         ?>
			<article class="spnc-author-box spnc-author-box-two">
				<div class="spnc-author-box-wrapper">
					<div>
						<span class="spnc-author">
							<figure>
								<?php echo wp_kses_post(get_avatar( $post->post_author, 250 )); ?>
							</figure>
						</span>
					</div>
					<div>
						<div class="spnc-author-socials-wrap">
							<span class="spnc-author">
								<a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>">
									<?php newscrunch_get_author_name( $post ); ?>
								</a>
							</span>
						</div>
						<?php
				      	$newscrunch_user_data        = get_user_meta( $post->post_author );
						$newscrunch_user_description = $newscrunch_user_data['description'][0]; 
				      	if($newscrunch_user_description != '') : ?>
						<p class="spnc-author-desc">
							<?php echo wp_kses_post($newscrunch_user_description); ?>
						</p>
						<?php else: ?>
						<p class="spnc-author-desc">
							<?php
							$user_id = get_the_author_meta('ID');
							// Translators: %1$s: <a> tag. %2$s: </a>.
							printf( wp_kses_post( __( 'Add your Biographical Information. %1$sEdit your Profile%2$s now.', 'newscrunch' ) ), '<a href="' . esc_url( get_edit_user_link( $user_id ) ) . '">', '</a>' ); 
							?>
						</p>
						<?php endif; ?>
						<div class="spnc-author-footer">
							<a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" class="spnc-author-posts-link"><?php esc_html_e('view all posts', 'newscrunch');?></a>
						</div>
					</div>
				</div>
			</article>
		<?php 
	endif;
    }
    add_action('newscrunch_single_post_auth_detail','newscrunch_single_post_auth');

endif;

/*
-------------------------------------------------------------------------------
 Advertisement Section
-------------------------------------------------------------------------------*/
if ( ! function_exists( 'newscrunch_advertisement_area' ) ) {
	function newscrunch_advertisement_area($arg) {
		$newscrunch_advertisement_item = get_theme_mod('advertisement_items');
		$newscrunch_advertisement_item = json_decode($newscrunch_advertisement_item);
        if (!empty($newscrunch_advertisement_item))
        { 
        	$newscrunch_img					= []; // Initialize an empty img array
        	$newscrunch_img_link  			= []; // Initialize an empty img link array
        	$newscrunch_img_target 			= []; // Initialize an empty img link target array
        	$newscrunch_before_header 		= []; // Initialize an empty before header array
        	$newscrunch_after_header 		= []; // Initialize an empty after header array
        	$newscrunch_inside_header 		= []; // Initialize an empty inside header array
        	$newscrunch_befor_post_archive  = []; // Initialize an empty before post archive array
        	$newscrunch_random_post_archive = []; // Initialize an empty random post archive array
        	$newscrunch_befor_post_content 	= []; // Initialize an empty before post content array
        	$newscrunch_after_post_content 	= []; // Initialize an empty after post content array
        	$newscrunch_before_footer  		= []; // Initialize an empty before footer array
        	$newscrunch_after_footer  		= []; // Initialize an empty after footer array
        	$newscrunch_visible  			= []; // Initialize an empty ads visibility array
			foreach($newscrunch_advertisement_item as $newscrunch_advertisement_items)
          	{ 
                
               $newscrunch_img_val = $newscrunch_img_link_val = $newscrunch_img_target_val =  
               $newscrunch_before_header_val = $newscrunch_after_header_val = $newscrunch_inside_header_val = 
               $newscrunch_befor_post_archive_val = $newscrunch_random_post_archive_val = $newscrunch_befor_post_content_val = 
               $newscrunch_after_post_content_val = $newscrunch_before_footer_val = $newscrunch_after_footer_val = 
               $newscrunch_visible_val = '';

            	//img
                $newscrunch_img_val 				.=  $newscrunch_advertisement_items->image_url;
				$newscrunch_img[] 				 	 =  $newscrunch_img_val;

				//img link
                $newscrunch_img_link_val  			.=  $newscrunch_advertisement_items->link;
				$newscrunch_img_link[]     		 	 =  $newscrunch_img_link_val;

				//img link target
                $newscrunch_img_target_val  		.=  $newscrunch_advertisement_items->open_new_tab;
				$newscrunch_img_target[]     	 	 =  $newscrunch_img_target_val;

				//before header
                $newscrunch_before_header_val  		.=  $newscrunch_advertisement_items->before_header;
				$newscrunch_before_header[]      	 =  $newscrunch_before_header_val;

				//after header
                $newscrunch_after_header_val  		.=  $newscrunch_advertisement_items->after_header;
				$newscrunch_after_header[]       	 =  $newscrunch_after_header_val;

				//inside header
                $newscrunch_inside_header_val  		.=  $newscrunch_advertisement_items->inside_header;
				$newscrunch_inside_header[]     	 =  $newscrunch_inside_header_val;

				//before post archive
                $newscrunch_befor_post_archive_val 	.=  $newscrunch_advertisement_items->before_post_archive;
				$newscrunch_befor_post_archive[]     =  $newscrunch_befor_post_archive_val;

				//random post archive
                $newscrunch_random_post_archive_val .=  $newscrunch_advertisement_items->random_post_archive;
				$newscrunch_random_post_archive[]    =  $newscrunch_random_post_archive_val;

				//before post content
                $newscrunch_befor_post_content_val  .=  $newscrunch_advertisement_items->before_post_content;
				$newscrunch_befor_post_content[]     =  $newscrunch_befor_post_content_val;

				//after post content
                $newscrunch_after_post_content_val  .=  $newscrunch_advertisement_items->after_content;
				$newscrunch_after_post_content[]     =  $newscrunch_after_post_content_val;

				//before footer
                $newscrunch_before_footer_val  		.=  $newscrunch_advertisement_items->before_footer;
				$newscrunch_before_footer[]     	 =  $newscrunch_before_footer_val;

				//after footer
                $newscrunch_after_footer_val  		.=  $newscrunch_advertisement_items->after_footer;
				$newscrunch_after_footer[]     		 =  $newscrunch_after_footer_val;

				//visibility
                $newscrunch_visible_val  			.=  $newscrunch_advertisement_items->ad_visibility;
				$newscrunch_visible[]     			 =  $newscrunch_visible_val;
	    	}

	    	// before header
	    	if($arg == 'before header') 
	    	{
				$newscrunch_filtered_before_header_array = array_filter($newscrunch_before_header);
				if (!empty($newscrunch_filtered_before_header_array)) 
				{
					for($i=0; $i<count($newscrunch_before_header); $i++)
					{
						if($newscrunch_before_header[$i] =="yes")
						{
							echo newscrunch_advertisement_content($i);
						}
					}
				}
			}

			// After Header
			if($arg == 'after header')
	    	{
				$newscrunch_filter_after_header_array = array_filter($newscrunch_after_header);
				if (!empty($newscrunch_filter_after_header_array)) 
				{
					for($i=0; $i<count($newscrunch_after_header); $i++)
					{
						if($newscrunch_after_header[$i] =="yes")
						{
							echo newscrunch_advertisement_content($i);
						}
					}
				}
			}

			// Inside Header
			if($arg == 'inside header')
	    	{
				$newscrunch_filter_inside_header_array = array_filter($newscrunch_inside_header);
				if (!empty($newscrunch_filter_inside_header_array)) 
				{
					for($i=0; $i<count($newscrunch_inside_header); $i++)
					{
						if($newscrunch_inside_header[$i] =="yes")
						{
							echo newscrunch_advertisement_content($i);
						}
					}
				}
			}

			// Before Post Archive
			if($arg == 'before post archive')
	    	{
				$newscrunch_filter_befor_post_archive_array = array_filter($newscrunch_befor_post_archive);
				if (!empty($newscrunch_filter_befor_post_archive_array)) 
				{
					for($i=0; $i<count($newscrunch_befor_post_archive); $i++)
					{
						if($newscrunch_befor_post_archive[$i] =="yes")
						{
							echo '<section class="ads" id="content"><div class="spnc-container"><div class="spnc-row">';
							echo newscrunch_advertisement_content($i);
							echo '</div></div></section>';
						}
					}
				}
			}

			// Random Post
			if($arg == 'random post archive')
	    	{
		    	if((get_theme_mod('ad_type','banner')=='banner')): 	
					$yesArray = array_filter($newscrunch_random_post_archive, function($value) {
					    return $value === "yes";
					});
					$keys = array_keys($yesArray); // retrieve key from associative array
					return $keys;
				endif;
			}

			// Before Post Content
			if($arg == 'before post content')
	    	{
				$newscrunch_filter_befor_post_content_array = array_filter($newscrunch_befor_post_content);
				if (!empty($newscrunch_filter_befor_post_content_array)) 
				{
					for($i=0; $i<count($newscrunch_befor_post_content); $i++)
					{
						if($newscrunch_befor_post_content[$i] =="yes")
						{
							echo '<section class="ads" id="content"><div class="spnc-container"><div class="spnc-row">';
							echo newscrunch_advertisement_content($i);
							echo '</div></div></section>';
						}
					}
				}
			}

			// After Post Content
			if($arg == 'after post content')
	    	{
				$newscrunch_filter_after_post_content_array = array_filter($newscrunch_after_post_content);
				if (!empty($newscrunch_filter_after_post_content_array)) 
				{
					for($i=0; $i<count($newscrunch_after_post_content); $i++)
					{
						if($newscrunch_after_post_content[$i] =="yes")
						{
							echo '<section class="ads" id="content"><div class="spnc-container"><div class="spnc-row">';
							echo newscrunch_advertisement_content($i);
							echo '</div></div></section>';
						}
					}
				}
			}

			// Befor Footer
			if($arg == 'before footer')
	    	{
				$newscrunch_filter_before_footer_array = array_filter($newscrunch_before_footer);
				if (!empty($newscrunch_filter_before_footer_array)) 
				{
					for($i=0; $i<count($newscrunch_before_footer); $i++)
					{
						if($newscrunch_before_footer[$i] =="yes")
						{
							echo newscrunch_advertisement_content($i);
						}
					}
				}
			}

			// After Footer
			if($arg == 'after footer')
	    	{
				$newscrunch_filter_after_footer_array = array_filter($newscrunch_after_footer);
				if (!empty($newscrunch_filter_after_footer_array)) 
				{
					for($i=0; $i<count($newscrunch_after_footer); $i++)
					{
						if($newscrunch_after_footer[$i] =="yes")
						{
							echo newscrunch_advertisement_content($i);
						}
					}
				}
			}
	    }    
	}
	
	add_action( 'newscrunch_before_header_ads'  	, 'newscrunch_advertisement_area' , 10, 2 );
	add_action( 'newscrunch_after_header_ads'   	, 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_before_footer_ads'  	, 'newscrunch_advertisement_area' , 10, 2 );
	add_action( 'newscrunch_after_footer_ads'   	, 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_random_post_ads'    	, 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_inside_header_ads'  	, 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_before_post_arc_ads'	, 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_before_post_content_ads', 'newscrunch_advertisement_area', 10, 2);
	add_action( 'newscrunch_after_post_content_ads' , 'newscrunch_advertisement_area', 10, 2);
}

function newscrunch_advertisement_content($id)
{
    $newscrunch_advertisement_item = get_theme_mod('advertisement_items');
	$newscrunch_advertisement_item = json_decode($newscrunch_advertisement_item);
    if (!empty($newscrunch_advertisement_item))
    { 
    	$newscrunch_img					= []; // Initialize an empty img array
    	$newscrunch_img_link  			= []; // Initialize an empty img link array
    	$newscrunch_img_target 			= []; // Initialize an empty img link target array
    	$newscrunch_visible  			= []; // Initialize an empty ads visibility array

		foreach($newscrunch_advertisement_item as $newscrunch_advertisement_items)
      	{            
           $newscrunch_img_val = $newscrunch_img_link_val = $newscrunch_img_target_val = $newscrunch_visible_val =  '';

        	//img
            $newscrunch_img_val 				.=  $newscrunch_advertisement_items->image_url;
			$newscrunch_img[] 				 	 =  $newscrunch_img_val;

			//img link
            $newscrunch_img_link_val  			.=  $newscrunch_advertisement_items->link;
			$newscrunch_img_link[]     		 	 =  $newscrunch_img_link_val;

			//img link target
            $newscrunch_img_target_val  		.=  $newscrunch_advertisement_items->open_new_tab;
			$newscrunch_img_target[]     	 	 =  $newscrunch_img_target_val;

			//visibility
            $newscrunch_visible_val  			.=  $newscrunch_advertisement_items->ad_visibility;
			$newscrunch_visible[]     			 =  $newscrunch_visible_val;
    	}

    	$newscrunch_img_target = ($newscrunch_img_target[$id] == "yes") ? "_blank" : "_self";
    	
    	if(!empty($newscrunch_img[$id]))
    	{
    		$ad_img = '<div data-wow-delay=".5s" class="wow-callback zoomIn"><a class="spnc-advertisement '.$newscrunch_visible[$id].'" target="'.$newscrunch_img_target.'" href="'.$newscrunch_img_link[$id].'"><div class="adv_overlay"></div><img class="repeat-advertisement" src="'.$newscrunch_img[$id].'"/></a></div>';
			return $ad_img;
    	}
    	else
    	{
    		return null;
    	}
    	
    }    		
}

/*
-------------------------------------------------------------------------------
 Post Formats
-------------------------------------------------------------------------------*/

// Get audio from post

if ( ! function_exists( 'newscrunch_get_audio_from_post' ) ) :

	/**
	 * Get audio HTML markup from post content.
	 *
	 * @since 1.0.0
	 * @param  number $post_id Post id.
	 * @return mixed
	 */
	function newscrunch_get_audio_from_post( $post_id = null ) {

		$post    = get_post( $post_id );
		$content = do_shortcode( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$embeds  = apply_filters( 'newscrunch_get_post_audio', get_media_embedded_in_content( $content ) );

		if ( empty( $embeds ) ) {
			return '';
		}

		// check what is the first embed containg audio tag, or soundlcoud.
		foreach ( $embeds as $embed ) {
			if ( strpos( $embed, 'audio' ) || strpos( $embed, 'soundcloud' ) || strpos( $embed, 'spotify' )  ) {
				return $embed ;
			}
		}
	}
endif;

// Get galley form post


if ( ! function_exists( 'newscrunch_get_post_gallery' ) ) :
	/**
	 * A get_post_gallery() polyfill for Gutenberg.
	 *
	 * @since 1.0.0
	 * @param object|int|null $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
	 * @param boolean         $html Return gallery HTML or array of gallery items.
	 * @return string|array   The gallery html or array of gallery items.
	 */
	function newscrunch_get_post_gallery( $post = 0, $html = false ) {

		// Get gallery shortcode.
		$gallery = get_post_gallery( $post, $html );

		// Already found a gallery so lets quit.
		if ( $gallery ) {
			return $gallery;
		}

		// Check the post exists.
		$post = get_post( $post );
		if ( ! $post ) {
			return;
		}

		// Not using Gutenberg so let's quit.
		if ( ! function_exists( 'has_blocks' ) ) {
			return;
		}

		// Not using blocks so let's quit.
		if ( ! has_blocks( $post->post_content ) ) {
			return;
		}

		/**
		 * Search for gallery blocks and then, if found, return the
		 * first gallery block.
		 */
		$pattern = '/<!--\ wp:gallery.*-->([\s\S]*?)<!--\ \/wp:gallery -->/i';
		preg_match_all( $pattern, $post->post_content, $the_galleries );

		// Check a gallery was found and if so change the gallery html.
		if ( ! empty( $the_galleries[1] ) ) {
			$gallery_html = reset( $the_galleries[1] );

			if ( $html ) {
				$gallery = $gallery_html;
			} else {
				$srcs = array();
				$ids  = array();

				preg_match_all( '#src=([\'"])(.+?)\1#is', $gallery_html, $src, PREG_SET_ORDER );
				if ( ! empty( $src ) ) {
					foreach ( $src as $s ) {
						$srcs[] = $s[2];
					}
				}

				preg_match_all( '#data-id=([\'"])(.+?)\1#is', $gallery_html, $id, PREG_SET_ORDER );
				if ( ! empty( $id ) ) {
					foreach ( $id as $i ) {
						$ids[] = $i[2];
					}
				}

				$gallery = array(
					'ids' => implode( ',', $ids ),
					'src' => $srcs,
				);
			}
		}

		return $gallery;
	}
endif;

// Get the media form post

function newscrunch_get_post_media( $post_format = false, $post = null ) {

		if ( false === $post_format ) {
			$post_format = get_post_format( $post );
		}

		$return = '';

		switch ( $post_format ) {

			case 'video':
				$return = newscrunch_get_video_from_post( $post );
				break;

			case 'audio':
			/*$return = do_shortcode( newscrunch_get_audio_from_post( $post ) );*/
				$return =  newscrunch_get_audio_from_post( $post ) ;
				break;


			case 'gallery':
			$gallery = newscrunch_get_post_gallery( $post );

			if ( isset( $gallery['ids'] ) ) {

				$img_ids = explode( ',', $gallery['ids'] );

				if ( is_array( $img_ids ) && ! empty( $img_ids ) ) {
					foreach ( $img_ids as $img_id ) {

						$image_alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
						$image_url = wp_get_attachment_url( $img_id );

						$return .= '<figure class="spnc-post-thumbnail">';
						$return .= '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $image_alt ) . '" >';
						$return .= '</figure>';
					}
				}
			}
			break;
			
		}

	return apply_filters( 'newscrunch_get_post_media', $return, $post_format, $post );
}


// Post format section

if ( ! function_exists( 'newscrunch_post_formats' ) ) :
	function newscrunch_post_formats() 
	{
	    if ( has_post_format('video') ) {

	    		if(has_post_thumbnail()): ?>
					<!-- Post Featured Image -->
					<figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
						<a itemprop="url" href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
							<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'itemprop'=>'image' )); ?>
						</a>				
					</figure>
					<?php if ( class_exists('Newscrunch_Plus') ){ ?>
					<span class="spnc-post-btn format-video-btn" style="cursor: pointer;">
					    <i class="fa-solid fa-video"></i>
					</span>
					<?php } else { ?>
					<a href="<?php the_permalink(); ?>" itemprop="url" title="<?php the_title(); ?>">
						<span class="spnc-post-btn format-video-btn">
						    <i class="fa-solid fa-video"></i>
						</span>
					</a>
					<?php } ?>
                	<!-- Video Post PopUp Model -->
                	<?php if ( class_exists('Newscrunch_Plus') ){
                	$newscrunch_media = newscrunch_get_post_media( 'video' );
                	if ( $newscrunch_media ) : ?>
                	<!-- Video Post PopUp Model -->
				    <div class="format-video-model">
				        <div class="spnc-news-media">
				            <?php echo $newscrunch_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				            <span class="format-video-close">&times;</span>
				        </div>
				    </div>
				    <?php
				    endif; 
					}
				    ?>
				  
					<?php
					else :
						$newscrunch_media = newscrunch_get_post_media( 'video' );
						if ( $newscrunch_media ) : ?>
							<figure class="spnc-news-media spnc-post-thumbnail">
									<?php echo $newscrunch_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</figure>
					<?php
				endif;
		 endif;

     }
    else if(has_post_format('gallery')){

    	if ( class_exists('Newscrunch_Plus') ){

    	$newscrunch_media = newscrunch_get_post_media( 'gallery' );

		if ( $newscrunch_media ) : ?>
	     <div class="spnc-post-gallery-carousel owl-carousel spnc-post-carousel">
           <?php echo $newscrunch_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
         </div>
		<?php
	    else: 
		if(has_post_thumbnail()){ ?>
			<!-- Post Featured Image -->
			<figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
				<a itemprop="url" href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
					<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'itemprop'=>'image' )); ?>
				</a>				
			</figure>
		<?php };
		endif;

		}else{
		if(has_post_thumbnail()): ?>
			<!-- Post Featured Image -->
			<figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
				<a itemprop="url" href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
					<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'itemprop'=>'image' )); ?>
				</a>				
			</figure>
		<?php endif;
		}
    }
    else if(has_post_format('audio')){
    	$newscrunch_media = newscrunch_get_post_media( 'audio' );
		if ( $newscrunch_media ) : ?>
				<figure class="spnc-post-thumbnail">
						<?php echo $newscrunch_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</figure>
				<?php
			else:
				if(has_post_thumbnail()){ ?>
					<!-- Post Featured Image -->
					<figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
						<a itemprop="url" href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
							<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'itemprop'=>'image' )); ?>
						</a>				
					</figure>
					<span class="spnc-post-btn">
			            <i class="fa-solid fa-headphones"></i>
			        </span>
					<?php }
			endif;
    }
    else {           
		if(has_post_thumbnail()): ?>
			<!-- Post Featured Image -->
			<figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
				<a itemprop="url" href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
					<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'itemprop'=>'image' )); ?>
				</a>				
			</figure>
		<?php endif;
	}
}
endif;


/*
-------------------------------------------------------------------------------
Woocommerce Styling
-------------------------------------------------------------------------------*/
if (!function_exists('newscrunch_woocommerce_css')) :

	function newscrunch_woocommerce_css() { ?> 
            <style type="text/css">
            	/* Position secondary image on top */
				.products .product .secondary-image {position: absolute;top: 0;left: 0;width: 100%;height: 100%;opacity: 0;transition: opacity 0.3s ease-in-out;}
            <?php if( get_theme_mod('newscrunch_wc_gallery_zoom', true) == false):?>
                .woocommerce-product-gallery__wrapper .zoomImg {display: none !important;}
				.woocommerce-product-gallery__wrapper a{cursor: unset;}
            <?php endif; if( get_theme_mod('newscrunch_wc_gallery_lightbox', true) == false):?>
            	.woocommerce-product-gallery__trigger{display: none;}
            <?php endif; if( get_theme_mod('newscrunch_wc_gallery_zoom', true) == false):?>
            	.slider-prev, .slider-next {position: absolute;top: 50%;transform: translateY(-50%);font-size: 24px;color: #333;cursor: pointer;z-index: 10;}
				.slider-prev {left: 10px;}
				.slider-next {right: 10px;}
				.slider-prev:hover, .slider-next:hover {color: #000;}
            <?php endif; if( get_theme_mod('newscrunch_wc_related_product', true) == false):?>
            	.related.products{display: none;}
        	<?php endif; if(get_theme_mod('newscrunch_wc_upsell_product',true) == false):?>
        		.upsells.products{display: none;}
        	<?php endif; if(get_theme_mod('newscrunch_wc_product_hover','none') =='image-swap'):?>
        		.products .product:hover .secondary-image {opacity: 1;}
        	<?php endif; if(get_theme_mod('spncp_wc_product_hover','none') =='image-swap'):?>
        		#wrapper .spnc-wc-product-section .product:hover .secondary-image {opacity: 1;}
        	<?php endif; if(get_theme_mod('newscrunch_wc_cross_sell_product',true) == false):?>
        		.spnc-cart-product-wrap .cart-collaterals .cross-sells{display: none;}
        	<?php endif; ?>
            </style>
	    <?php
	}
	add_action('wp_head','newscrunch_woocommerce_css');
endif;


/*
-------------------------------------------------------------------------------
 Add WooCommerce Compatibility
-------------------------------------------------------------------------------*/
if ( class_exists( 'WooCommerce' ) ) :	

	//Set a Higher Priority
	function newscrunch_wc_panel_js() { ?>
    <script type="text/javascript">
        wp.customize.bind('ready', function() {
            // Check if WooCommerce panel exists
            var wooPanel = wp.customize.panel('woocommerce');
            if ( wooPanel ) {
                // Change the priority to make it appear higher in the Customizer
                wooPanel.priority(41);
            }
        });
    </script>
    <?php
	}
	add_action( 'customize_controls_print_footer_scripts', 'newscrunch_wc_panel_js', 100 );


	//Add Product Slider Arrow At Single Product 
	if(get_theme_mod('newscrunch_wc_gallery_slide_arrow',true)==true):
		function newscrunch_wc_product_arrows() {
		    if (is_product()) {
		        // Get the global $post object
		        global $post;

		        // Get the product object using the post ID
		        $product = wc_get_product($post->ID);

		        // Get the gallery image IDs (excluding the featured image)
		        $gallery_image_ids = $product->get_gallery_image_ids();

		        // Check if the product has more than one image
		        if (count($gallery_image_ids) > 0) {
		            wp_enqueue_script('newscrunch-wc-slider-arrows', NEWSCRUNCH_TEMPLATE_DIR_URI . '/assets/js/wc-arrow.js', array('jquery'), '', true);
		        }
		    }
		}
		add_action('wp_enqueue_scripts', 'newscrunch_wc_product_arrows');
	endif;


	// Customize Related Products Output on Single Product
	function newscrunch_wc_related_products_args($args) {
		$row = get_theme_mod('newscrunch_wc_related_product_row',4);
		$col = get_theme_mod('newscrunch_wc_related_product_col',4);
	    $args['posts_per_page'] = $row*$col; // Total number of related products 
	    $args['columns'] = $col;            // Number of columns
	    return $args;
	}
	add_filter('woocommerce_output_related_products_args', 'newscrunch_wc_related_products_args', 20);


	// Upsell Prodcut
	if(get_theme_mod('newscrunch_wc_upsell_product',true) == true):
		// Adjust the number of columns for upsell products
		add_filter('woocommerce_upsells_columns', function($columns) {
		    return get_theme_mod('newscrunch_wc_upsell_col',4); // Set the number of columns
		});

		// Adjust the number of rows for upsell products
		add_filter('woocommerce_upsells_total', function($rows) {
		    return get_theme_mod('newscrunch_wc_upsell_col',4) * get_theme_mod('newscrunch_wc_upsell_row',4); // Set the number of rows
		});
	endif;

	// Cross Sell Prodcut
	function newscrunch_wc_set_cross_sell_rows( $rows ) {
	    // Get the row count from the Customizer.
	    $cross_sell_rows = get_theme_mod( 'newscrunch_wc_cross_sell_row', 4 );

	    // Ensure the value is valid and sanitized.
	    $cross_sell_rows = absint( $cross_sell_rows );

	    // Each row typically contains 4 items. Adjust the total based on rows.
	    return $cross_sell_rows * 4; // 4 columns per row
	}
	add_filter( 'woocommerce_cross_sells_total', 'newscrunch_wc_set_cross_sell_rows' );


	// Add Prodcut Secondary Img Hover
	if ( ! function_exists( 'newscrunch_wc_secondary_image_on_hover' ) ) {
	    function newscrunch_wc_secondary_image_on_hover() {
	        global $product;

	        // Get gallery images for the product
	        $attachment_ids = $product->get_gallery_image_ids();

	        // Check if there's at least one gallery image
	        if ($attachment_ids && isset($attachment_ids[0])) {
	            // Get the secondary image
	            $secondary_image_id = $attachment_ids[0];
	            $secondary_image_url = wp_get_attachment_image_url($secondary_image_id, 'woocommerce_thumbnail');

	            // Display the secondary image with a custom class for CSS targeting
	            echo '<img class="secondary-image" src="' . esc_url($secondary_image_url) . '" alt="' . esc_attr__('Product hover image', 'newscrunch') . '" />';
	        }
	    }
	}
	add_action('wp', function() {
		if (get_theme_mod('newscrunch_wc_product_hover', 'none') === 'image-swap')
		{
	        add_action('woocommerce_before_shop_loop_item_title', 'newscrunch_wc_secondary_image_on_hover', 10);
	    }
	});


	//Product Sale Badege
	function newscrunch_wc_percentage_sale_badge() {
	    global $product;

	    // Check if the product is on sale
	    if ( $product->is_on_sale() ) {
	        $discount = 0; // Initialize discount variable

	        if ( $product->is_type( 'variable' ) ) {
	            // Handle variable products
	            $available_variations = $product->get_available_variations();

	            foreach ( $available_variations as $variation ) {
	                $regular_price = (float) $variation['display_regular_price'];
	                $sale_price = (float) $variation['display_price'];

	                if ( $regular_price > 0 ) {
	                    // Calculate the discount for this variation
	                    $variation_discount = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );

	                    // Keep the highest discount
	                    if ( $variation_discount > $discount ) {
	                        $discount = $variation_discount;
	                    }
	                }
	            }
	        } else {
	            // Handle simple products
	            $regular_price = (float) $product->get_regular_price();
	            $sale_price = (float) $product->get_sale_price();

	            if ( $regular_price > 0 ) {
	                $discount = round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
	            }
	        }

	        // Display the discount badge if there's a valid discount
	        if(get_theme_mod('newscrunch_wc_product_sale_badge','percentage') === 'percentage')
	        {
	        	if ( $discount > 0 ) {
	            echo '<span class="onsale">' . esc_html( $discount . '%' ) . '</span>';
	        }
	        }
	        else if(get_theme_mod('newscrunch_wc_product_sale_badge','percentage') === 'text')
	        {
	        	echo '<span class="onsale">' . esc_html(get_theme_mod('newscrunch_wc_product_sale_badge_text','Sale')) . '</span>';
	        }
	        
	    }
	}
	// Remove default sale badge
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );

	// Add custom sale badge
	add_action( 'woocommerce_before_shop_loop_item_title', 'newscrunch_wc_percentage_sale_badge', 9 );


	//Product Items Reorder
	function newscrunch_wc_reorder_product_details() {
	    // Get the reorder options from the Customizer
	    $order = get_theme_mod('newscrunch_wc_sort', array('newscrunch_wc_title_reorder', 'newscrunch_wc_price_reorder', 'newscrunch_wc_rating_reorder'));

	    if (empty($order) || !is_array($order)) {
	        return;
	    }

	    // Loop through the Customizer order and display corresponding content
	    foreach ($order as $element) {
	        switch ($element) {
	            case 'newscrunch_wc_title_reorder':
	                // Manually render the product title
	                echo '<h2 class="woocommerce-loop-product__title">' . esc_html(get_the_title()) . '</h2>';
	                break;

	            case 'newscrunch_wc_price_reorder':
	                // Display the price
	                woocommerce_template_loop_price();
	                break;

	            case 'newscrunch_wc_rating_reorder':
	                // Display the rating
	                woocommerce_template_loop_rating();
	                break;
	        }
	    }
	}
	add_action('woocommerce_before_shop_loop_item_title', 'newscrunch_wc_reorder_product_details', 20);
	// Remove default actions to avoid duplicate output
	remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
	remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
	remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);

	//Cart Icon
	function newscrunch_wc_update_cart_fragments( $fragments ) {
	    // Update the cart count
	    ob_start();
	    ?>
	    <span class="cart-count"><?php echo esc_html( WC()->cart->get_cart_contents_count() ); ?></span>

	    <?php
	    $fragments['span.cart-count'] = ob_get_clean();

	    // Update the mini cart dropdown
	    ob_start();
	    ?>
	    <div class="cart-dropdown">
	        <?php woocommerce_mini_cart(); ?>
	    </div>
	    <?php
	    $fragments['div.cart-dropdown'] = ob_get_clean();

	    return $fragments;
	}
	add_filter( 'woocommerce_add_to_cart_fragments', 'newscrunch_wc_update_cart_fragments' );


	// Add Custom Div In Product Page
	function newscrunch_wc_wrap_product_image_start() { echo '<div class="spnc-wc-overlay">';}
	function newscrunch_wc_wrap_product_image_end() { echo '</div>';}
	add_action('woocommerce_before_shop_loop_item_title', 'newscrunch_wc_wrap_product_image_start', 5);
	add_action('woocommerce_before_shop_loop_item_title', 'newscrunch_wc_wrap_product_image_end', 15);


	// Add Custom Div In Single Product
	function newscrunch_wc_start_parent_div() { echo '<div class="spnc-wc-single-product-wrap">';}
	function newscrunch_wc_end_parent_div() { echo '</div>'; }
	add_action( 'woocommerce_before_single_product_summary', 'newscrunch_wc_start_parent_div', 5 );
	add_action( 'woocommerce_after_single_product_summary', 'newscrunch_wc_end_parent_div', 5 );


	// Redirect the default cart page to the custom cart page
	function newscrunch_wc_redirect_to_custom_cart_page() {
	    // Check if we're on the cart page
	    if ( is_cart() ) {
	        // Change 'custom-cart-page.php' to the path of your custom cart page template
	        get_template_part( 'woocommerce/cart/cart' );
	        exit; // Stop the default WooCommerce cart page from loading
	    }
	}
	add_action( 'template_redirect', 'newscrunch_wc_redirect_to_custom_cart_page' );


	//Remove Item From Cart Page
	function newscrunch_wc_remove_cart_item_ajax() {
	    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'woocommerce-cart' ) ) {
	        die();  // If nonce check fails, stop the request
	    }

	    if ( isset( $_POST['cart_item_key'] ) ) {
	        $cart_item_key = sanitize_text_field( $_POST['cart_item_key'] );
	        WC()->cart->remove_cart_item( $cart_item_key );  // Remove the item from the cart
	        echo 'success';  // Return success after item removal
	    }

	    wp_die();  // End the AJAX request
	}
	add_action( 'wp_ajax_remove_cart_item', 'newscrunch_wc_remove_cart_item_ajax' );
	add_action( 'wp_ajax_nopriv_remove_cart_item', 'newscrunch_wc_remove_cart_item_ajax' );

//woocommerce endif 
endif;
 
/*
-------------------------------------------------------------------------------
 Number of posts display in blog/archive section
-------------------------------------------------------------------------------*/

function newscrunch_modify_posts_per_page($query) {
    if (!is_admin() && $query->is_main_query() && (is_home() || is_archive())) {
        $posts_per_page = get_theme_mod('newscrunch_blog_posts_per_page', 10);
        $query->set('posts_per_page', absint($posts_per_page));
    }
}
add_action('pre_get_posts', 'newscrunch_modify_posts_per_page');