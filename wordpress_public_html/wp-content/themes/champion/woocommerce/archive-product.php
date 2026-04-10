<?php
/**
 * The Template for displaying product archives, including the main shop page which is a post type archive
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/archive-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.4.0
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

$show_slider = get_theme_mod( 'shop_slider' );
$show_slider_slug = get_theme_mod( 'shop_slider_slug' );
if( empty( $show_slider ) && function_exists( 'putRevSlider' ) ) {
    putRevSlider( $show_slider_slug );
}
/**
 * Hook: woocommerce_before_main_content.
 *
 * @hooked woocommerce_output_content_wrapper - 10 (outputs opening divs for the content)
 * @hooked woocommerce_breadcrumb - 20
 * @hooked WC_Structured_Data::generate_website_data() - 30
 */
do_action( 'woocommerce_before_main_content' );

if( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>

    <h1 class="page-title"><?php woocommerce_page_title(); ?></h1>

<?php endif;
$shop_layout = get_theme_mod( 'shop_layout' );
if( !empty( $_GET[ 'layout' ] ) ) {
    $shop_layout = $_GET[ 'layout' ];
}
?>
<?php if( $shop_layout == 'sidebar_right' || $shop_layout == '' ) { ?>
    <div class="row">
        <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">

            <?php do_action( 'woocommerce_archive_description' ); ?>

            <?php if( have_posts() ) : ?>

                <?php woocommerce_product_loop_start(); ?>

                <?php woocommerce_product_subcategories(); ?>

                <?php while ( have_posts() ) : the_post(); ?>

                    <?php wc_get_template_part( 'content', 'product' ); ?>

                <?php endwhile; // end of the loop. ?>

                <?php woocommerce_product_loop_end(); ?>

                <?php
                /**
                 * woocommerce_archive_description hook.
                 *
                 * @hooked woocommerce_taxonomy_archive_description - 10
                 * @hooked woocommerce_product_archive_description - 10
                 */
                do_action( 'woocommerce_archive_description' );
                ?>

            <?php elseif( !woocommerce_product_subcategories( array(
                'before' => woocommerce_product_loop_start( false ),
                'after' => woocommerce_product_loop_end( false )
            ) )
            ) : ?>

                <?php wc_get_template( 'loop/no-products-found.php' ); ?>

            <?php endif; ?>
        </div>
        <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
            <?php
            /**
             * woocommerce_sidebar hook
             *
             * @hooked woocommerce_get_sidebar - 10
             */
            do_action( 'woocommerce_sidebar' );
            ?>
        </div>
    </div>
<?php } elseif( $shop_layout == 'sidebar_left' ) { ?>
    <div class="row">
        <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
            <?php
            /**
             * woocommerce_sidebar hook
             *
             * @hooked woocommerce_get_sidebar - 10
             */
            do_action( 'woocommerce_sidebar' );
            ?>
        </div>
        <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">

            <?php do_action( 'woocommerce_archive_description' ); ?>

            <?php if( have_posts() ) : ?>

                <?php woocommerce_product_loop_start(); ?>

                <?php woocommerce_product_subcategories(); ?>

                <?php while ( have_posts() ) : the_post(); ?>

                    <?php wc_get_template_part( 'content', 'product' ); ?>

                <?php endwhile; // end of the loop. ?>

                <?php woocommerce_product_loop_end(); ?>

                <?php
                /**
                 * woocommerce_after_shop_loop hook.
                 *
                 * @hooked woocommerce_pagination - 10
                 */
                do_action( 'woocommerce_after_shop_loop' );
                ?>

            <?php elseif( !woocommerce_product_subcategories( array( 'before' => woocommerce_product_loop_start( false ), 'after' => woocommerce_product_loop_end( false ) ) ) ) : ?>

                <?php wc_get_template( 'loop/no-products-found.php' ); ?>

            <?php endif; ?>
        </div>
    </div>
<?php } elseif( $shop_layout == 'fullwidth' ) { ?>
    <?php do_action( 'woocommerce_archive_description' ); ?>

    <?php if( have_posts() ) : ?>
        <div class="row four-col">
            <div class="col-x-12 col-sm-12 col-md-12 col-lg-12 ">
                <?php woocommerce_product_loop_start(); ?>

                <?php woocommerce_product_subcategories(); ?>

                <?php while ( have_posts() ) : the_post(); ?>

                    <?php wc_get_template_part( 'content', 'product' ); ?>

                <?php endwhile; // end of the loop. ?>

                <?php woocommerce_product_loop_end(); ?>

                <?php
                /**
                 * woocommerce_after_shop_loop hook.
                 *
                 * @hooked woocommerce_pagination - 10
                 */
                do_action( 'woocommerce_after_shop_loop' );
                ?>
            </div>
        </div>
    <?php elseif( !woocommerce_product_subcategories( array( 'before' => woocommerce_product_loop_start( false ), 'after' => woocommerce_product_loop_end( false ) ) ) ) : ?>

        <?php wc_get_template( 'loop/no-products-found.php' ); ?>

    <?php endif; ?>
<?php }

/**
 * Hook: woocommerce_after_main_content.
 *
 * @hooked woocommerce_output_content_wrapper_end - 10 (outputs closing divs for the content)
 */
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' ); ?>
