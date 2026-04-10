<h2 class="panel_title"><?php printf( __('<span>Add your review</span> for %s', 'champion'), get_the_title() ); ?></h2>
	<?php if ( get_option( 'woocommerce_review_rating_verification_required' ) === 'no' || wc_customer_bought_product( '', get_current_user_id(), $product->get_id() ) ) : ?>

		<div id="review_form_wrapper">
			<div id="review_form" class="product_review_form">
				<?php
					$commenter = wp_get_current_commenter();

					$comment_form = array(
						'title_reply'          => '',
						'title_reply_to'       => '',
						'title_reply_before'   => '',
						'title_reply_after'    => '',
						'comment_notes_after'  => '',
						'fields'               => array(
							'author' => '<div class="row"><div class="col-md-6 col-xs-12 col-sm-6"><div class="form-group">' .
										'<input class="form-control" id="author" name="author" placeholder="' . esc_attr__( 'Your Name', 'champion' ) . ' *" type="text" value="' . esc_attr( $commenter['comment_author'] ) . '" size="30" aria-required="true" /></div></div>',
							'email'  => '<div class="col-md-6 col-xs-12 col-sm-6"><div class="form-group">' .
										'<input class="form-control" id="email" name="email" placeholder="' . esc_attr__( 'Your Email', 'champion' ) . ' *" type="text" value="' . esc_attr( $commenter['comment_author_email'] ) . '" size="30" aria-required="true" /></div></div></div>',
						),
						'label_submit'  => esc_html__( 'Submit', 'champion' ),
						'logged_in_as'  => '',
						'comment_field' => '',
					);

					$comment_form['comment_field'] = '<div class="form-group"><textarea id="comment" placeholder="' . esc_attr__( 'Your Review *', 'champion' ) . '" class="form-control" name="comment" cols="45" rows="8" aria-required="true"></textarea></div>';

					if ( get_option( 'woocommerce_enable_review_rating' ) === 'yes' ) {
						$comment_form['comment_field'] .= '<div class="comment-form-rating form-group"><label for="rating">' . esc_html__( 'Your rating', 'champion' ) .'</label><select name="rating" id="rating" aria-required="true" required>
							<option value="">' . esc_html__( 'Rate&hellip;', 'champion' ) . '</option>
							<option value="5">' . esc_html__( 'Perfect', 'champion' ) . '</option>
							<option value="4">' . esc_html__( 'Good', 'champion' ) . '</option>
							<option value="3">' . esc_html__( 'Average', 'champion' ) . '</option>
							<option value="2">' . esc_html__( 'Not that bad', 'champion' ) . '</option>
							<option value="1">' . esc_html__( 'Very poor', 'champion' ) . '</option>
						</select></div>';
					}

					comment_form( apply_filters( 'woocommerce_product_review_comment_form_args', $comment_form ) );
				?>
			</div>
		</div>

	<?php else : ?>

		<p class="woocommerce-verification-required"><?php esc_html_e( 'Only logged in customers who have purchased this product may leave a review.', 'champion' ); ?></p>

	<?php endif; ?>
