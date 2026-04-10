<?php
/**
 * Template part for displaying a message that posts cannot be found
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Newscrunch
 */
?>
<article data-wow-delay=".8s" itemscope itemtype="https://schema.org/Article" id="post-<?php the_ID(); ?>" <?php post_class('spnc-post content-none wow-callback zoomIn'); ?>>
	<div class="spnc-post-content">
		<header class="spnc-entry-header">
			<h3 itemprop="name" class="spnc-entry-title"><?php esc_html_e('Nothing found', 'newscrunch' ); ?></h3>
			<p itemprop="description"><?php esc_html_e('Sorry, but nothing matched your search criteria. Please try again with some different keywords.','newscrunch' ); ?></p>
		</header>
	</div>
</article>
