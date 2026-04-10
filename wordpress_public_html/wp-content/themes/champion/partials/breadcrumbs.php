<?php
$args['list_tpl'] = '<ol class="breadcrumb">%s</ol>';
$args['text']     = array(
	'home'      => esc_html__( 'Home', 'champion' ),
	'category'  => esc_html__( 'Category archive "%s"', 'champion' ),
	'search'    => esc_html__( 'Search results for "%s"', 'champion' ),
	'tag'       => esc_html__( 'Tag "%s"', 'champion' ),
	'author'    => esc_html__( 'Author Archives: %s', 'champion' ),
	'not_found' => esc_html__( 'Page not found', 'champion' ),
	'paged'     => esc_html__( 'Page %s', 'champion' ),
	'blog'      => esc_html__( 'Blog', 'champion' ),
);

$breadcrumbs_status        = get_post_meta( get_the_ID(), 'breadcrumbs', true );
$global_breadcrumbs_status = get_theme_mod( 'breadcrumbs' );

if ( empty( $breadcrumbs_status ) && empty( $global_breadcrumbs_status ) ) {
	Stm_breadcrumbs::breadcrumbs( $args );
} elseif ( $breadcrumbs_status == 'default' && empty( $global_breadcrumbs_status ) ) {
	Stm_breadcrumbs::breadcrumbs( $args );
} elseif ( $breadcrumbs_status == 'show' ) {
	Stm_breadcrumbs::breadcrumbs( $args );
}