<?php
/**
 * The template for displaying product content within loops
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

// Ensure visibility.
if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}
?>
<li <?php wc_product_class('', $product); ?>>
	<?php
	/**
	 * Hook: woocommerce_before_shop_loop_item.
	 *
	 * @hooked woocommerce_template_loop_product_link_open - 10
	 */
	do_action( 'woocommerce_before_shop_loop_item' );
	?>
	<a href="<?php the_permalink(); ?>">
		<?php
		/**
		 * woocommerce_before_shop_loop_item_title hook.
		 *
		 * @hooked woocommerce_show_product_loop_sale_flash - 10
		 * @hooked woocommerce_template_loop_product_thumbnail - 10
		 */
		do_action( 'woocommerce_before_shop_loop_item_title' );
		?>
	</a>

	<div class="product_header clearfix">
		<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<a class="reviews" href="<?php the_permalink(); ?>#reviews"><?php echo sprintf(esc_html__('%s reviews', 'champion'), $product->get_rating_count()); ?></a>
	</div>
	<div class="product_info clearfix">
		<?php $categories = wp_get_post_terms( get_the_ID(), 'product_cat' ); ?>
		<?php if ( $categories ) { ?>
			<a href="<?php echo esc_url(get_term_link( $categories[0] )); ?>" class="category"><?php echo esc_html( $categories[0]->name ); ?></a>
		<?php } ?>
		<?php
		/**
		 * Hook: woocommerce_after_shop_loop_item_title.
		 *
		 * @hooked woocommerce_template_loop_rating - 5
		 * @hooked woocommerce_template_loop_price - 10
		 */
		do_action( 'woocommerce_after_shop_loop_item_title' );
		?>
	</div>
	<div class="product_footer clearfix">
		<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
		<?php if ( $price_html = $product->get_price_html() ) : ?>
			<span class="price"><?php echo champion_filtered_output( $price_html ); ?></span>
		<?php endif; ?>
	</div>
</li>