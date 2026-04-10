<?php
/**
 * theme breadcurmbs section
 *
 * @package Newscrunch Theme
*/
if (!function_exists('newscrunch_breadcrumbs2')):

	function newscrunch_breadcrumbs2() {
		function newscrunch_breadcrumb_trail() {
	        if ( ! function_exists( 'breadcrumb_trail' ) ) {
	            // load class file
	            require_once get_template_directory() . '/inc/breadcrumbs/breadcrumb-trail/breadcrumb-trail.php';
	        }

	        $breadcrumb_args = array(
	            'container' => 'div',
	            'show_browse' => false,
	        );
	        breadcrumb_trail( $breadcrumb_args );
	    }
        add_action( 'newscrunch_breadcrumb_trail_content', 'newscrunch_breadcrumb_trail' );	 
		$enable_disable_banner = get_theme_mod('breadcrumb_banner_enable', true); 
		$breadcrumb='';
		$newscrunch_breadcrumb_type = get_theme_mod('newscrunch_breadcrumb_type','default');
		if($enable_disable_banner == true) {
			if( ($newscrunch_breadcrumb_type=='default') ||
					(($newscrunch_breadcrumb_type == 'yoast') &&  function_exists('yoast_breadcrumb')) ||
					(($newscrunch_breadcrumb_type == 'rankmath') &&  class_exists('RankMath')) ||
					(($newscrunch_breadcrumb_type == 'navxt') && class_exists('breadcrumb_navxt')) ) { ?>

					<?php if(get_theme_mod('newscrunch_single_post_layout','1') == '8'){ ?>
					<section class="page-title-section breadcrumb-2">
								<?php } else { ?>
					<section data-wow-delay=".8s" class="wow-callback zoomIn page-title-section <?php if(get_theme_mod('bredcrumb_style',2) == 2):?> breadcrumb-2<?php endif;?>">
					<?php } ?>
					
					<div class="spnc-container">
						<div class="spnc-row spnc-breadcrumb-wrap" <?php if( get_header_image() ){ ?> style="background:#17212c url('<?php header_image(); ?>'); background-size: cover;" <?php } ?>>
							<div class="breadcrumb-overlay"></div>
						<?php 
						if($newscrunch_breadcrumb_type == 'yoast') {
							if ( function_exists('yoast_breadcrumb') ) {
								$seo_bread_title = get_option('wpseo_titles');
						        if($seo_bread_title['breadcrumbs-enable'] == true) {
						         	$breadcrumbs = yoast_breadcrumb("","",false);
						           	$breadcrumb='<ul class="page-breadcrumb" itemscope itemtype="http://schema.org/BreadcrumbList">
						           					<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">'.wp_kses_post($breadcrumbs).'</li>
						           				</ul>';
						        }   
							}
						} 
						
					    if((get_theme_mod('bredcrumb_alignment','left')=='left') || (get_theme_mod('bredcrumb_alignment','parallel')=='parallel') || (get_theme_mod('bredcrumb_alignment','parallel')=='parallelr') ){ 
					    	echo '<div class="spnc-col-1 text-left">';
					    	
				    		if($breadcrumb)	{
				    			echo $breadcrumb;
				    		}
					    	if($newscrunch_breadcrumb_type == 'rankmath') {
								if( function_exists( 'rank_math_the_breadcrumbs' ) ) {
							    	rank_math_the_breadcrumbs();
							    }
							}
						    elseif($newscrunch_breadcrumb_type == 'navxt') { 
						    	if( function_exists( 'bcn_display' ) ){
									echo '<nav class=" navxt-breadcrumb">';
	                                 	bcn_display();
									echo '</nav>';
	                            }  
	                        }
	                        elseif($newscrunch_breadcrumb_type == 'default') {
								do_action( 'newscrunch_breadcrumb_trail_content' );  
							}
	                    					    
					    	echo'</div>';
					    }
					    elseif(get_theme_mod('bredcrumb_alignment','centered')=='centered'){
					    	echo '<div class="spnc-col-1 text-center">';
					    	
				    		if($breadcrumb) {
				    			echo $breadcrumb;
				    		}
					    	elseif($newscrunch_breadcrumb_type == 'rankmath') {
								if( function_exists( 'rank_math_the_breadcrumbs' ) ) {
							        rank_math_the_breadcrumbs();	
							    }
							}
						    elseif($newscrunch_breadcrumb_type == 'navxt') { 
						    	if( function_exists( 'bcn_display' ) ){
									echo '<nav class="navxt-breadcrumb">';
	                                 	bcn_display();
									echo '</nav>';
	                            }  
	                        }
	                        elseif($newscrunch_breadcrumb_type == 'default') {
								do_action( 'newscrunch_breadcrumb_trail_content' );  
							}
		                    
					    	echo'</div>';
					    }
					    elseif(get_theme_mod('bredcrumb_alignment','right')=='right'){ 
					    	echo '<div class="spnc-col-1 text-right">';
					    	
				    		if($breadcrumb) {
				    			echo $breadcrumb;
				    		}						    	
					    	if($newscrunch_breadcrumb_type == 'rankmath') {
								if( function_exists( 'rank_math_the_breadcrumbs' ) ) {
							     	rank_math_the_breadcrumbs();
							    }
							}
						    elseif($newscrunch_breadcrumb_type == 'navxt') { 
						    	if( function_exists( 'bcn_display' ) ){
									echo '<nav class="navxt-breadcrumb">';
	                                 	bcn_display();
									echo '</nav>';
	                            }  
	                        }
	                        elseif($newscrunch_breadcrumb_type == 'default') {
								do_action( 'newscrunch_breadcrumb_trail_content' );  
							}
	                                    	
					    	echo'</div>';
					    }
						?>
					    </div>
					</div>
				</section>
			<?php }
		}
	}
	add_action('newscrunch_breadcrumbs_hook2','newscrunch_breadcrumbs2');
endif;?>