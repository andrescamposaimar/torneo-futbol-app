<?php
/**
 * Review Comments Template
 *
 * Closing li is left out on purpose!.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/review.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @author  WooThemes
 * @package WooCommerce/Templates
 * @version 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<li <?php comment_class(); ?> id="li-comment-<?php comment_ID() ?>">

	<div id="comment-<?php comment_ID(); ?>" class="comment_container comment-container clearfix">
		<div class="comment-avatar">
			<?php echo get_avatar( $comment, apply_filters( 'woocommerce_review_gravatar_size', '60' ), '' ); ?>
		</div>
		<div class="comment-body">
			<div class="comment-info">
				<?php printf( __( '<cite>%s</cite>', 'champion' ), get_comment_author_link() ); ?>
				<?php if ( $comment->comment_approved == '0' ) { ?>
					<span><?php esc_html_e( 'Your comment is awaiting approval', 'champion' ); ?></span>
				<?php }else{ ?>
					<span><?php printf( esc_html__( '%1$s at %2$s', 'champion' ), get_comment_date(), get_comment_time() ); ?><?php edit_comment_link( esc_html__( '(Edit)', 'champion' ), '  ', '' ); ?></span>
				<?php } ?>
				<?php
				do_action( 'woocommerce_review_before_comment_meta', $comment );
				?>
			</div>
			<div class="comment-text">
				<?php do_action( 'woocommerce_review_comment_text', $comment );

				do_action( 'woocommerce_review_after_comment_text', $comment ); ?>
			</div>
		</div>
	</div>
