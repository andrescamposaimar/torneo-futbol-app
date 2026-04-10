<?php get_header(); ?>
<?php get_template_part( 'partials/breadcrumbs' ); ?>
	<h1 class="page-title"><?php echo single_cat_title(); ?></h1>
<?php get_template_part( 'partials/posts' ) ?>
<?php get_footer(); ?>