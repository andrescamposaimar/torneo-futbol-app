<?php
/**
 * Template part for displaying page content in page.php
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newscrunch
 */
?>
<article data-wow-delay=".8s" <?php post_class('wow-callback zoomIn spnc-post'); ?> >
	<div class="spnc-post-content">
		<?php if(has_post_thumbnail()) {
			if ( is_single() ) { ?>
				<figure class="spnc-post-thumbnail">
					<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'loading' => false )); ?>					
				</figure>
			<?php }
			else { ?>
				<figure class="spnc-post-thumbnail">
					<a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>">
						<?php the_post_thumbnail('full', array('class'=>'img-fluid', 'loading' => false )); ?>
					</a>				
				</figure>
			<?php }
		}?>					
		<div class="spnc-entry-content">
			<?php the_content();
			newscrunch_edit_link_button();
			?>
		</div>
	</div>
</article>