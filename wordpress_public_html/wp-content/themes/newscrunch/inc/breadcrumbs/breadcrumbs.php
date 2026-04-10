<?php
/**
 * theme breadcurmbs section
 *
 * @package Newscrunch Theme
*/
if (!function_exists('newscrunch_breadcrumbs')):

	function newscrunch_breadcrumbs() {
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
		$breadcrumb_enable_disable	= get_theme_mod('enable_breadcrumb',true);
		if ($enable_disable_banner == true) { ?>
			<section data-wow-delay=".8s" class="<?php if(get_theme_mod('bredcrumb_style',2) == 1):?> breadcrumb-1<?php endif;?> wow-callback zoomIn page-title-section" <?php if( get_header_image() ){ ?> style="background:#17212c url('<?php header_image(); ?>'); background-size: cover;" <?php } ?> >
				<div class="breadcrumb-overlay"></div>
				<div class="spnc-container">
					<div class="spnc-row spnc-breadcrumb-wrap">
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

					if(get_theme_mod('bredcrumb_position','page_header')=='page_header' ):
					    $breadcrumb_col='8';
				    else:
				    	$breadcrumb_col='1';
				    endif;
			
					if(get_theme_mod('bredcrumb_alignment','parallel')=='parallel'){ 

				    	if(get_theme_mod('bredcrumb_position','page_header')=='page_header'):   

				    			if(($breadcrumb_enable_disable == true) && (($newscrunch_breadcrumb_type == 'default')|| (($newscrunch_breadcrumb_type == 'yoast') && ( function_exists('yoast_breadcrumb') )) || (($newscrunch_breadcrumb_type == 'rankmath') && ( class_exists('RankMath') ) ) ||(($newscrunch_breadcrumb_type == 'navxt') && (class_exists('breadcrumb_navxt')))))
				    			{
				    				$breadcrumb_column='8';
				    			}
				    			else
				    			{
				    				$breadcrumb_column='1';
				    			}
				    		echo '<div class="spnc-col-'.$breadcrumb_column.' parallel">';
				    		do_action('newscrunch_breadcrumbs_page_title_hook');
				    		echo '</div>';
				    	endif;
				    	if($breadcrumb_enable_disable == true ){

						    if($breadcrumb){
						    	echo '<div class="spnc-col-'.$breadcrumb_col.' parallel">'.$breadcrumb.'</div>';
							}
						    elseif($newscrunch_breadcrumb_type == 'rankmath') {
								if( function_exists( 'rank_math_the_breadcrumbs' ) ) {
							    echo '<div class="spnc-col-'.$breadcrumb_col.' parallel text-right">'; rank_math_the_breadcrumbs();	echo '</div>';
							    }
							}
						    elseif($newscrunch_breadcrumb_type == 'navxt') {
							    if( function_exists( 'bcn_display' ) ){
									echo '<div class="spnc-col-'.$breadcrumb_col.' parallel text-right"><nav class="navxt-breadcrumb">';
	                                 bcn_display();
									echo '</nav></div>';
	                            }  
	                        }
							elseif($newscrunch_breadcrumb_type == 'default') {
								echo '<div class="spnc-col-'.$breadcrumb_col.' parallel">';
									do_action( 'newscrunch_breadcrumb_trail_content' );  
								echo '</div>';
							}
                        }  
					}
				    elseif(get_theme_mod('bredcrumb_alignment','parallel')=='parallelr'){
				    	if($breadcrumb_enable_disable == true )
				    	{
					    	if($breadcrumb){ echo '<div class="spnc-col-'.$breadcrumb_col.' text-left parallel">';
					    		if($breadcrumb_enable_disable == true ){ 
					    			echo $breadcrumb;
					    		}
					    	 echo '</div>';
					    	}
					    	elseif(($newscrunch_breadcrumb_type == 'rankmath') && ( class_exists('RankMath') )) {
					    		echo '<div class="spnc-col-'.$breadcrumb_col.' text-left parallel">'; 
								if( function_exists( 'rank_math_the_breadcrumbs' ) && $breadcrumb_enable_disable == true ) {
							    	rank_math_the_breadcrumbs();	
							    }
							    echo '</div>';
							}
						    elseif(($newscrunch_breadcrumb_type == 'navxt') && (class_exists('breadcrumb_navxt'))) {
							   
									echo '<div class="spnc-col-'.$breadcrumb_col.' text-left parallel"><nav class="navxt-breadcrumb">';
									 if( function_exists( 'bcn_display' ) && $breadcrumb_enable_disable == true ){
	                                 	bcn_display();
	                                 }
									echo '</nav></div>';
	                              
	                        }
                            elseif($newscrunch_breadcrumb_type == 'default') {
								echo '<div class="spnc-col-'.$breadcrumb_col.' text-left parallel">';
									do_action( 'newscrunch_breadcrumb_trail_content' );  
								echo '</div>';
                            }							
	                    }    

				    	if(get_theme_mod('bredcrumb_position','page_header')=='page_header'):
				    		if( ($breadcrumb)||($newscrunch_breadcrumb_type == 'default')||($newscrunch_breadcrumb_type == 'rankmath')||($newscrunch_breadcrumb_type == 'navxt') ){ $col='8';}else{ $col='1';}

				    		if(($breadcrumb_enable_disable == true) && ((($newscrunch_breadcrumb_type == 'yoast') && ( function_exists('yoast_breadcrumb') )) || (($newscrunch_breadcrumb_type == 'rankmath') && ( class_exists('RankMath') ) ) ||(($newscrunch_breadcrumb_type == 'navxt') && (class_exists('breadcrumb_navxt')))||($newscrunch_breadcrumb_type == 'default')))
			    			{
			    				$breadcrumb_column='8';
			    			}
			    			else
			    			{
			    				$breadcrumb_column='1';
			    			}
					    	echo '<div class="spnc-col-'.$breadcrumb_column.' text-right parallel">';
					    	do_action('newscrunch_breadcrumbs_page_title_hook');
					    	echo '</div>';
				    	endif;
				    }
				    elseif(get_theme_mod('bredcrumb_alignment','parallel')=='centered'){

				    	echo '<div class="spnc-col-1 text-center">';
				    	if($breadcrumb_enable_disable == true ){
					    	if($breadcrumb){echo $breadcrumb;}
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
	                    }
                        if(get_theme_mod('bredcrumb_position','page_header')=='page_header'):
				    		do_action('newscrunch_breadcrumbs_page_title_hook');
				    	endif;

				    	echo'</div>';
				    }
				    elseif(get_theme_mod('bredcrumb_alignment','parallel')=='left'){ 

				    	echo '<div class="spnc-col-1 text-left">';
				    	if($breadcrumb_enable_disable == true ){
					    	if($breadcrumb){echo $breadcrumb;}

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
                    	}
				    	if(get_theme_mod('bredcrumb_position','page_header')=='page_header'):do_action('newscrunch_breadcrumbs_page_title_hook');
				    	endif;
				    
				    	echo'</div>';
				    }
				    elseif(get_theme_mod('bredcrumb_alignment','parallel')=='right'){ 
				    	echo '<div class="spnc-col-1 text-right">';
					    	if($breadcrumb_enable_disable == true ){
						    	if($breadcrumb){echo $breadcrumb;}
						    	
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
								
	                    	}
	                        if(get_theme_mod('bredcrumb_position','page_header')=='page_header'):
					    		do_action('newscrunch_breadcrumbs_page_title_hook');
					    	endif;
                    	
				    	echo'</div>';
				    }
					?>
				    </div>
				</div>
			</section>
		<?php
		}
	}
	add_action('newscrunch_breadcrumbs_hook','newscrunch_breadcrumbs');
endif;?>