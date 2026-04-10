<?php

add_filter( 'comment_form_default_fields', 'champion_bootstrap3_comment_form_fields' );
function champion_bootstrap3_comment_form_fields( $fields ) {
	$commenter = wp_get_current_commenter();
	$req       = get_option( 'require_name_email' );
	$aria_req  = ( $req ? " aria-required='true'" : '' );
	$html5     = current_theme_supports( 'html5', 'comment-form' ) ? 1 : 0;
	$fields    = array(
		'author' => '<div class="row"><div class="col-md-4 col-xs-12 col-sm-4"><div class="form-group comment-form-author">' .
		            '<input class="form-control" placeholder="' . esc_attr__( 'Your Name', 'champion' ) . ( $req ? ' *' : '' ) . '" id="author" name="author" type="text" value="' . esc_attr( $commenter['comment_author'] ) . '" size="30"' . $aria_req . ' /></div></div>',
		'email'  => '<div class="col-md-4 col-xs-12 col-sm-4"><div class="form-group comment-form-email">' .
		            '<input class="form-control" placeholder="' . esc_attr__( 'Your Email', 'champion' ) . ( $req ? ' *' : '' ) . '" id="email" name="email" ' . ( $html5 ? 'type="email"' : 'type="text"' ) . ' value="' . esc_attr( $commenter['comment_author_email'] ) . '" size="30"' . $aria_req . ' /></div></div>',
		'url'    => '<div class="col-md-4 col-xs-12 col-sm-4"><div class="form-group comment-form-url">' .
		            '<input class="form-control" placeholder="' . esc_attr__( 'Website', 'champion' ) . '" id="url" name="url" type="text" value="' . esc_attr( $commenter['comment_author_url'] ) . '" size="30" /></div></div></div>'
	);

	return $fields;
}

add_filter( 'comment_form_defaults', 'champion_bootstrap3_comment_form' );
function champion_bootstrap3_comment_form( $args ) {
	$args['comment_field'] = '<div class="form-group comment-form-comment">
    <textarea placeholder="' . _x( 'Comment', 'noun', 'champion' ) . ' *" class="form-control" id="comment" name="comment" aria-required="true"></textarea>
    </div>';

	return $args;
}

add_action( 'comment_form', 'champion_bootstrap3_comment_button' );
function champion_bootstrap3_comment_button() {
	echo '<button class="btn btn-danger" type="submit"><span>' . esc_html__( 'Post Comment', 'champion' ) . '</span></button>';
}