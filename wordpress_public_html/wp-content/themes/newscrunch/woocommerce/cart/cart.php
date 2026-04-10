<?php
/**
 * The template for displaying all cart page content
 *
 *
 * @package Newscrunch
 */
defined( 'ABSPATH' ) || exit;

get_header();

do_action( 'newscrunch_breadcrumbs_filter' );

//News Highlight Section
newscrunch_highlight_views('inner');?>
<section class="page-section-full bg-default custom-cart-page" id="content">
	<div class="spnc-container spnc-single-post">
	<div class="spnc-row">
		
			<?php if(get_theme_mod('wc_sidebar_layout','right')=='left'): get_sidebar('woocommerce'); endif; ?>
      	<div class="spnc-col-<?php echo ( !is_active_sidebar( 'woocommerce' ) ? '1' :'7' ); ?> wow-callback zoomIn">
 				<div class="spnc-entry-content">
 					<div class="spnc-cart-product-wrap">
 						<?php do_action( 'woocommerce_before_cart' ); if ( ! WC()->cart->is_empty() ) :?>
  						<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
	     					<?php do_action( 'woocommerce_before_cart_table' ); ?>
							<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents" cellspacing="0">
								<thead>
									<tr>
										<th class="product-name"><?php esc_html_e( 'Product', 'newscrunch' ); ?>  <?php echo esc_html( '(' . count( WC()->cart->get_cart() ) . ')' ); ?></th>
										<th class="product-price"><?php esc_html_e( 'Price', 'newscrunch' ); ?></th>
										<th class="product-quantity"><?php esc_html_e( 'Quantity', 'newscrunch' ); ?></th>
										<th class="product-subtotal"><?php esc_html_e( 'Total', 'newscrunch' ); ?></th>
										<th class="product-remove"></th>
									</tr>
								</thead>
								<tbody>
									<?php do_action( 'woocommerce_before_cart_contents' );
										foreach ( WC()->cart->get_cart() as $newscrunch_cart_item_key => $newscrunch_cart_item ) 
										{
											$newscrunch_product   = apply_filters( 'woocommerce_cart_item_product', $newscrunch_cart_item['data'], $newscrunch_cart_item, $newscrunch_cart_item_key );
											$newscrunch_product_id = apply_filters( 'woocommerce_cart_item_product_id', $newscrunch_cart_item['product_id'], $newscrunch_cart_item, $newscrunch_cart_item_key );

											if ( $newscrunch_product && $newscrunch_product->exists() && $newscrunch_cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $newscrunch_cart_item, $newscrunch_cart_item_key ) ) 
											{
												$newscrunch_product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $newscrunch_product->is_visible() ? $newscrunch_product->get_permalink( $newscrunch_cart_item ) : '', $newscrunch_cart_item, $newscrunch_cart_item_key ); ?>
												<tr class="woocommerce-cart-form__cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $newscrunch_cart_item, $newscrunch_cart_item_key ) ); ?>">
													<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'newscrunch' ); ?>">
													<?php
													// Get the product object
												   $newscrunch_product_data = $newscrunch_cart_item['data'];

												   // Get the product title
												   $newscrunch_product_title = $newscrunch_product_data->get_title();

												   // Get the product image URL
												   $newscrunch_product_image_url = wp_get_attachment_url( $newscrunch_product_data->get_image_id() );

												   // Get the product page URL
												   $newscrunch_product_url = get_permalink( $newscrunch_product_data->get_id() );
										
													if ( ! $newscrunch_product_permalink ) {
														echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', esc_html( $newscrunch_product->get_name() ), $newscrunch_cart_item, $newscrunch_cart_item_key ) . '&nbsp;' );
													} else {
														echo wp_kses_post(
															    '<a href="' . esc_url( $newscrunch_product_url ) . '" class="product-image">
															        <img width="150" height="150" src="' . esc_url( $newscrunch_product_image_url ) . '" class="attachment-thumbnail size-thumbnail">
															    </a>
															    <div class="spnc-title-price-wrap">
															        <h5>
															            <a href="' . esc_url( $newscrunch_product_url ) . '" class="product-title">' . esc_html( $newscrunch_product_title ) . '</a>
															        </h5>
															    </div>'
															);
													}

													do_action( 'woocommerce_after_cart_item_name', $newscrunch_cart_item, $newscrunch_cart_item_key );

													// Meta data.
													echo wc_get_formatted_cart_item_data( $newscrunch_cart_item );

													// Backorder notification.
													if ( $newscrunch_product->backorders_require_notification() && $newscrunch_product->is_on_backorder( $newscrunch_cart_item['quantity'] ) ) {
														echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'newscrunch' ) . '</p>', $newscrunch_product_id ) );
													} ?>
													</td>

													<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'newscrunch' ); ?>">
														<?php
															echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $newscrunch_product ), $newscrunch_cart_item, $newscrunch_cart_item_key );
														?>
													</td>

													<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'newscrunch' ); ?>">
														<?php
														if ( $newscrunch_product->is_sold_individually() ) {
															$newscrunch_product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $newscrunch_cart_item_key );
														} else {
															$newscrunch_product_quantity = woocommerce_quantity_input(
																array(
																	'input_name'   => "cart[{$newscrunch_cart_item_key}][qty]",
																	'input_value'  => $newscrunch_cart_item['quantity'],
																	'max_value'    => $newscrunch_product->get_max_purchase_quantity(),
																	'min_value'    => '0',
																	'product_name' => $newscrunch_product->get_name(),
																),
																$newscrunch_product,
																false
															);
														}

														echo apply_filters('woocommerce_cart_item_quantity', $newscrunch_product_quantity, $newscrunch_cart_item_key, $newscrunch_cart_item);?>
													</td>

													<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'newscrunch' ); ?>">
														<?php
															echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $newscrunch_product, $newscrunch_cart_item['quantity'] ), $newscrunch_cart_item, $newscrunch_cart_item_key ); // phpcs:ignore
														?>
													</td>

													<td class="product-remove" data-title="<?php esc_attr_e( 'Remove', 'newscrunch' ); ?>">
														<?php
															echo apply_filters( 
																'woocommerce_cart_item_remove_link',
																sprintf(
																	 '<a href="%s" class="remove-item" aria-label="%s" data-product_id="%s" data-product_sku="%s">   <i class="fa-solid fa-trash"></i></a>',
																	esc_url( wc_get_cart_remove_url( $newscrunch_cart_item_key ) ),
																	esc_html__( 'Remove this item', 'newscrunch' ),
																	esc_attr( $newscrunch_product_id ),
																	esc_attr( $newscrunch_product->get_sku() )
																),
																$newscrunch_cart_item_key
															);
														?>
													</td>
												</tr>
											<?php
											}
										} ?>

									<?php do_action( 'woocommerce_cart_contents' ); ?>

									<tr>
										<td colspan="6" class="actions">

											<?php if ( wc_coupons_enabled() ) { ?>
												<div class="coupon">
													<label for="coupon_code"><?php esc_html_e( 'Coupon:', 'newscrunch' ); ?></label> <input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Coupon code', 'newscrunch' ); ?>" /> <button type="submit" class="newscrunch-btn btn-secondary" name="apply_coupon" value="<?php esc_attr_e( 'Apply Coupon', 'newscrunch' ); ?>"><?php esc_html_e( 'Apply Coupon', 'newscrunch' ); ?></button>
													<?php do_action( 'woocommerce_cart_coupon' ); ?>
												</div>
											<?php } ?>

											<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="newscrunch-btn btn-text-1 mr-20"><span><?php esc_html_e( 'Continue Shopping', 'newscrunch' ); ?></span></a>

											<button type="submit" class="newscrunch-btn btn-secondary" name="update_cart" value="<?php esc_attr_e( 'Update Cart', 'newscrunch' ); ?>"><?php esc_html_e( 'Update Cart', 'newscrunch' ); ?></button>

											<?php do_action( 'woocommerce_cart_actions' ); ?>

											<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
										</td>
									</tr>

								<?php do_action( 'woocommerce_after_cart_contents' ); ?>
								</tbody>
							</table>
						<?php do_action( 'woocommerce_after_cart_table' ); ?>
						</form>
						<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

						<div class="cart-collaterals">
							<?php
								/**
								 * Cart collaterals hook.
								 *
								 * @hooked woocommerce_cross_sell_display
								 */
								do_action( 'woocommerce_cart_collaterals' );
							?>
						</div>
						<?php endif; if ( WC()->cart->is_empty() ) :?>
						<div class="cart-empty woocommerce-info"><?php esc_html_e('Your cart is currently empty.', 'newscrunch'); ?></div>
						<?php
				    	$newscrunch_shop_page_url = wc_get_page_permalink( 'shop' );
				      echo '<a href="' . esc_url( $newscrunch_shop_page_url ) . '" class="button return-to-shop-button">' . esc_html__( 'Return to Shop', 'newscrunch' ) . '</a>';  endif;?>
					</div>
				</div>
			</div>
				
			<?php if(get_theme_mod('wc_sidebar_layout','right')=='right'): get_sidebar('woocommerce'); endif;?>	
		</div>
	</div>
</section>

<?php do_action( 'woocommerce_after_cart' );

get_footer();