<?php 
class Newscrunch_Pagination
{
    function newscrunch_page()
    {
    	global $post;
    	global $wp_query, $wp_rewrite,$newscrunch_posts;
    	if ( get_query_var( 'paged' ) ) { $paged = get_query_var( 'paged' ); }
    	elseif ( get_query_var( 'page' ) ) { $paged = get_query_var( 'page' ); }
    	else { $paged = 1; }
    	if($wp_query->max_num_pages==0)
    	{
    		$wp_query = $newscrunch_posts;
    	}
                        
        $faPrevious = (!is_rtl()) ? "fas fa-chevron-left" : "fas fa-chevron-right";
        $faNext = (!is_rtl()) ? "fas fa-chevron-right" : "fas fa-chevron-left";

    	if($wp_query->max_num_pages!=1):
    	?>
    		<div class="pagination">
				<?php if( $paged != 1  ) : ?>
					<a class="prev page-numbers" href="<?php echo esc_url(get_pagenum_link(($paged-1 > 0 ? $paged-1 : 1))); ?>" title="<?php esc_attr_e('Previous Page','newscrunch'); ?>"><i class="<?php echo $faPrevious; ?>"></i></a>	
				<?php endif; ?>
				<?php for($i=1;$i<=($wp_query->max_num_pages>1 ? $wp_query->max_num_pages : 0 );$i++){ ?> 
						<a class="<?php echo ($i == $paged ? 'current ' : ''); ?>" href="<?php echo esc_url(get_pagenum_link($i)); ?>" title="<?php echo esc_attr($i); ?>"><?php echo $i; ?></a> 
				<?php } ?>
				<?php if($wp_query->max_num_pages!= $paged): ?>
						<a class="next page-numbers" href="<?php echo esc_url(get_pagenum_link(($paged+1 <= $wp_query->max_num_pages ? $paged+1 : $wp_query->max_num_pages ))); ?>" title="<?php esc_attr_e('Next Page','newscrunch'); ?>"><i class="<?php echo esc_attr($faNext); ?>"></i></a>
				<?php endif; ?>
    		</div>
    	<?php endif;			
  	} 
}
?>