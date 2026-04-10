<?php

function champion_stm_excerpt_more( $more ) {
	return '';
}

add_filter( 'excerpt_more', 'champion_stm_excerpt_more' );

function champion_new_excerpt_length( $length ) {
	return 20;
}

add_filter( 'excerpt_length', 'champion_new_excerpt_length' );

add_action( 'wp_head', 'champion_stm_ajaxurl' );
add_action( 'admin_head', 'champion_stm_ajaxurl' );

function champion_stm_ajaxurl() {
    $ajax_nonce = wp_create_nonce("champion_stm_like");
	?>
	<script type="text/javascript">
		var ajaxurl = '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>';
        var security_like = '<?php echo esc_js($ajax_nonce); ?>';
	</script>
<?php
}

if ( ! function_exists( 'champion_stm_the_attached_image' ) ) {

	function champion_stm_the_attached_image() {
		$post = get_post();

		$attachment_size     = apply_filters( 'stm_attachment_size', array( 810, 810 ) );
		$next_attachment_url = wp_get_attachment_url();

		$attachment_ids = get_posts( array(
			'post_parent'    => $post->post_parent,
			'fields'         => 'ids',
			'numberposts'    => - 1,
			'post_status'    => 'inherit',
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'order'          => 'ASC',
			'orderby'        => 'menu_order ID',
		) );

		if ( count( $attachment_ids ) > 1 ) {
			foreach ( $attachment_ids as $attachment_id ) {
				if ( $attachment_id == $post->ID ) {
					$next_id = current( $attachment_ids );
					break;
				}
			}

			// get the URL of the next image attachment...
			if ( $next_id ) {
				$next_attachment_url = get_attachment_link( $next_id );
			} // or get the URL of the first image attachment.
			else {
				$next_attachment_url = get_attachment_link( array_shift( $attachment_ids ) );
			}
		}

		printf( '<a href="%1$s" rel="attachment">%2$s</a>',
			esc_url( $next_attachment_url ),
			wp_get_attachment_image( $post->ID, $attachment_size )
		);
	}
}

function champion_lang_switcher() {
	if ( function_exists( 'icl_get_languages' ) ) {
		$lang_list = icl_get_languages();
		if(count($lang_list) > 1){
			$html      = '<div class="wpml_switcher">';
			$html .= '<a href="#">' . ICL_LANGUAGE_NAME . '<i class="fa fa-caret-down"></i></a>';
			$html .= '<ul>';
			foreach ( $lang_list as $lang ) {
				if ( $lang['active'] == 0 ) {
					$html .= ' <li><a href="' . esc_url( $lang['url'] ) . '">' . $lang['native_name'] . '</a></li> ';
				}
			}
			$html .= '</ul>';
			$html .= '</div>';

			echo champion_filtered_output( $html );
		}
	}
}

if(class_exists('ST_PostType')) {
    ST_PostType::addMetaBox('page_options', __('Page Options', 'champion'), array(
        'page',
        'sp_team'
    ), '', '', '', array(
        'fields' => array(
            'breadcrumbs' => array(
                'label' => __('Breadcrumbs', 'champion'),
                'type' => 'selectbox',
                'icon' => 'fa-road',
                'options' => array(
                    'default' => __('Default', 'champion'),
                    'show' => __('Show', 'champion'),
                    'hide' => __('Hide', 'champion')
                )
            ),
            'title' => array(
                'label' => __('Title', 'champion'),
                'type' => 'selectbox',
                'icon' => 'fa-text-height',
                'options' => array(
                    'show' => __('Show', 'champion'),
                    'hide' => __('Hide', 'champion')
                )
            )
        )
    ));

    ST_PostType::addMetaBox('page_options', __('Page Options', 'champion'), array('post'), '', '', '', array(
        'fields' => array(
            'breadcrumbs' => array(
                'label' => __('Breadcrumbs', 'champion'),
                'type' => 'selectbox',
                'icon' => 'fa-road',
                'options' => array(
                    'default' => __('Default', 'champion'),
                    'show' => __('Show', 'champion'),
                    'hide' => __('Hide', 'champion')
                )
            ),
            'title' => array(
                'label' => __('Title', 'champion'),
                'type' => 'selectbox',
                'icon' => 'fa-text-height',
                'options' => array(
                    'show' => __('Show', 'champion'),
                    'hide' => __('Hide', 'champion')
                )
            ),
            'about_author' => array(
                'label' => __('About The Author', 'champion'),
                'type' => 'selectbox',
                'icon' => 'fa-user',
                'options' => array(
                    'show' => __('Show', 'champion'),
                    'hide' => __('Hide', 'champion')
                )
            )
        )
    ));
}

function champion_stm_comment( $comment, $args, $depth ) {
	$GLOBALS['comment'] = $comment;
	extract( $args, EXTR_SKIP );
	$add_below = 'div-comment';
	?>

	<li id="li-comment-<?php echo esc_attr( $comment->comment_ID ); ?>" class="comment">
	<div class="comment-container" id="comment-<?php echo esc_attr( $comment->comment_ID ); ?>">
		<div class="comment-avatar">
			<?php echo get_avatar( $comment, 60 ); ?>
		</div>
		<div class="comment-body">
			<div class="comment-info">
				<?php printf( __( '<cite>%s</cite>', 'champion' ), get_comment_author_link() ); ?>
				<span><?php printf( __( '%1$s at %2$s', 'champion' ), get_comment_date(), get_comment_time() ); ?></a><?php edit_comment_link( __( '(Edit)', 'champion' ), '  ', '' ); ?></span>
			</div>
			<div class="comment-reply">
				<?php comment_reply_link( array_merge( $args, array(
					'add_below' => $add_below,
					'depth'     => $depth,
					'max_depth' => $args['max_depth']
				) ) ); ?>
			</div>
			<!-- .reply -->
			<div class="comment-text">
				<?php comment_text(); ?>
			</div>
		</div>
	</div>

<?php
}

add_filter( 'widget_categories_args', 'champion_stm_walker_category' );

function champion_stm_walker_category( $cat_args ) {
	$cat_args['walker'] = new STM_Walker_Category();

	return $cat_args;
}

add_filter( 'woocommerce_product_categories_widget_args', 'champion_product_cat_list_walker' );

function champion_product_cat_list_walker( $cat_args ) {
	$cat_args['walker'] = new STM_Product_Cat_List_Walker();

	return $cat_args;
}

remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 25 );
add_action( 'woocommerce_single_product_summary', 'woocommerce_output_product_data_tabs', 60 );
add_action( 'woocommerce_before_cart', 'woocommerce_breadcrumb', 20 );

add_action( 'woo_breadcrumbs', 'woocommerce_breadcrumb', 10 );

remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );

add_filter( 'woocommerce_breadcrumb_defaults', 'champion_stm_woocommerce_breadcrumbs' );
function champion_stm_woocommerce_breadcrumbs() {
	return array(
		'delimiter'   => '',
		'wrap_before' => '<ol class="breadcrumb">',
		'wrap_after'  => '</ol>',
		'before'      => '<li>',
		'after'       => '</li>',
		'home'        => _x( 'Home', 'breadcrumb', 'champion' ),
	);
}

add_filter( 'woocommerce_product_tabs', 'champion_add_review_tab' );

function champion_add_review_tab( $tabs ) {

	$tabs['add-review'] = array(
		'title' 	=> __( 'Add a review', 'champion' ),
		'priority' 	=> 50,
		'callback' 	=> 'champion_add_review_tab_content'
	);

	return $tabs;

}

function champion_add_review_tab_content() {

	require_once(get_template_directory().'/woocommerce/single-product/tabs/add_review_form.php');

}

function champion_filtered_output($output) {
    return apply_filters('champion_filtered_output', $output);
}

if ( ! function_exists( 'champion_loop_columns' ) ) {

	function champion_loop_columns() {
		$shop_layout = get_theme_mod( 'shop_layout' );

		if ( ! empty( $_GET['layout'] ) ) {
			$shop_layout = $_GET['layout'];
		}

		if($shop_layout == 'sidebar_right' || $shop_layout == 'sidebar_left' || $shop_layout == ''){
			return 3;
		}elseif($shop_layout == 'fullwidth'){
			return 4;
		}
	}

}

add_filter( 'loop_shop_columns', 'champion_loop_columns' );

remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
add_action( 'woocommerce_after_single_product_summary', 'champion_woocommerce_output_upsells', 15 );

if ( ! function_exists( 'champion_woocommerce_output_upsells' ) ) {
	function champion_woocommerce_output_upsells() {
		woocommerce_upsell_display( 4, 4 ); // Display 3 products in rows of 3
	}
}

add_filter( 'woocommerce_output_related_products_args', 'champion_stm_related_products_args' );
function champion_stm_related_products_args( $args ) {

	$args['posts_per_page'] = 4; // 4 related products
	$args['columns']        = 4; // arranged in 2 columns
	return $args;
}

add_filter( 'post_gallery', 'champion_stm_post_gallery', 10, 2 );

function champion_stm_post_gallery( $output, $attr ) {
	global $post;

	wp_enqueue_style( 'bxslider-css', get_template_directory_uri() . '/assets/css/jquery.bxslider.css', null, ( WP_DEBUG ) ? time() : null, 'all' );
	wp_enqueue_script( 'bxslider-js', get_template_directory_uri() . '/assets/js/jquery.bxslider.min.js', array( 'jquery' ), ( WP_DEBUG ) ? time() : null, true );


	if ( isset( $attr['orderby'] ) ) {
		$attr['orderby'] = sanitize_sql_orderby( $attr['orderby'] );
		if ( ! $attr['orderby'] ) {
			unset( $attr['orderby'] );
		}
	}

	extract( shortcode_atts( array(
		'order'      => 'ASC',
		'orderby'    => 'menu_order ID',
		'id'         => $post->ID,
		'itemtag'    => 'dl',
		'icontag'    => 'dt',
		'captiontag' => 'dd',
		'columns'    => 3,
		'size'       => 'thumbnail',
		'include'    => '',
		'exclude'    => ''
	), $attr ) );

	$id = intval( $id );
	if ( 'RAND' == $order ) {
		$orderby = 'none';
	}

	if ( ! empty( $include ) ) {
		$include      = preg_replace( '/[^0-9,]+/', '', $include );
		$_attachments = get_posts( array(
			'include'        => $include,
			'post_status'    => 'inherit',
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'order'          => $order,
			'orderby'        => $orderby
		) );

		$attachments = array();
		foreach ( $_attachments as $key => $val ) {
			$attachments[ $val->ID ] = $_attachments[ $key ];
		}
	}

	if ( empty( $attachments ) ) {
		return '';
	}

	$output = '<div class="bx-wrapper"><ul class="bxslider" id="bxslider_' . time() . '">';
	foreach ( $attachments as $id => $attachment ) {
		$img      = wp_get_attachment_image_src( $id, 'gallery_image' );
		$img_full = wp_get_attachment_image_src( $id, 'full' );
		$output .= '<li><a class="fancybox" href="' . esc_url( $img_full[0] ) . '" data-fancybox-group="gallery_' . time() . '"><img src="' . esc_url( $img[0] ) . '" alt="' . esc_attr__('Gallery image', 'champion') . '" /></a></li>';
	}
	$output .= '</ul></div>';

	$output .= '<div class="bx-pager" id="bx-pager_' . time() . '">';
	$i = 0;
	foreach ( $attachments as $id => $attachment ) {
		$img = wp_get_attachment_image_src( $id, 'gallery_thumbnail' );
		$output .= '<a data-slide-index="' . esc_attr( $i ) . '" href="#"><img src="' . esc_url( $img[0] ) . '" width="' . esc_attr( $img[1] ) . '" height="' . esc_attr( $img[2] ) . '" alt="' . esc_attr__('Gallery image', 'champion') . '" /></a>';
		$i ++;
	}
	$output .= '</div>
    <script>
        jQuery(document).ready(function($){
            $("#bxslider_' . time() . '").bxSlider({
                pagerCustom: "#bx-pager_' . time() . '"
            });
        });
    </script>
';

	return $output;
}

function champion_stm_like() {
    check_ajax_referer( 'champion_stm_like', 'security' );

    $id = (!empty($_POST['id'])) ? intval(sanitize_text_field($_POST['id'])) : 0;
	$likes = ( get_post_meta( $id, 'champion_stm_like', true ) == '' ) ? 0 : get_post_meta( $id, 'champion_stm_like', true );
	$likes ++;
	update_post_meta( $id, 'champion_stm_like', $likes );
	exit;
}

add_action( 'wp_ajax_nopriv_champion_stm_like', 'champion_stm_like' );
add_action( 'wp_ajax_champion_stm_like', 'champion_stm_like' );

function champion_wp_footer_content(){
	if( ! get_theme_mod('nice_scroll') ){	
		echo '
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$("html").niceScroll({
				       cursorcolor:"'.get_theme_mod( 'base_color' ).'",
				       cursorborder: "none",
				       cursorborderradius: "0"
				    });
				});
			</script>
		';
	}
}

add_action( 'wp_footer', 'champion_wp_footer_content' );

if ( ! empty( $_COOKIE['headerStyle'] ) && get_theme_mod( 'old_header_type_for_link' ) != $_COOKIE['headerStyle'] && ! is_admin() ) {
	$header_type = get_theme_mod( 'header_type' );

	$aviable_headers = array( 'header_1', 'header_2', 'header_3', 'header_4', 'header_5' );
	$header_style    = 'header_1';

	if( ! empty( $_COOKIE['headerStyle'] ) && in_array( $_COOKIE['headerStyle'], $aviable_headers ) ){
		$header_style = $_COOKIE['headerStyle'];
	}

	if ( $header_style == 'header_2' ) {
		set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
		$skin = 'style_2.txt';
	} elseif ( $header_style == 'header_3' ) {
		set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
		$skin = 'style_3.txt';
	} elseif ( $header_style == 'header_4' ) {
		set_theme_mod( 'bg_top_nav_color', 'rgba(255, 255, 255, 0)' );
		$skin = 'style_4.txt';
	} elseif ( $header_style == 'header_5' ) {
		set_theme_mod( 'bg_top_nav_color', 'rgba(21, 22, 24, 0.9)' );
		$skin = 'style_5.txt';
	} else {
		set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
		$skin = 'style_1.txt';
	}

	if(defined('STM_CONFIGURATIONS_PATH')) {
        $backup_file_content = champion_get_local_file_contents(STM_CONFIGURATIONS_PATH . '/demo/data/mega_main_menu/' . $skin);
        if ($backup_file_content !== false && ($options_backup = json_decode($backup_file_content, true))) {
            if (isset($options_backup['last_modified'])) {
                $options_backup['last_modified'] = time() + 30;
                set_theme_mod('old_header_type_for_link', $header_style);
                set_theme_mod('header_type', $header_style);
                update_option('mega_main_menu_options', $options_backup);
            }
        }
    }

}

function champion_is_use_plugin ($plug) {
    return in_array( $plug, (array) get_option( 'active_plugins', array() ) ) || is_plugin_active_for_network( $plug );
}

function champion_get_local_file_contents( $file_path ) {
    ob_start();
    include $file_path;
    $contents = ob_get_clean();

    return $contents;
}

if( empty( $_COOKIE['headerStyle'] ) && ! is_admin() ) {

	$old_header_type_for_link = get_theme_mod( 'old_header_type_for_link' );
	$aviable_headers = array( 'header_1', 'header_2', 'header_3', 'header_4', 'header_5' );

	if ( !in_array( $old_header_type_for_link, $aviable_headers ) ) {
		$header_type = 'header_1';
		set_theme_mod( 'bg_top_nav_color', '#f5f5f5' );
		$skin = 'style_1.txt';
		$backup_file_content = champion_get_local_file_contents( get_template_directory() . '/inc/demo/data/mega_main_menu/' . $skin );
		if ( $backup_file_content !== false && ( $options_backup = json_decode( $backup_file_content, true ) ) ) {
			if ( isset( $options_backup['last_modified'] ) ) {
				$options_backup['last_modified'] = time() + 30;
				set_theme_mod( 'old_header_type', $header_type );
				set_theme_mod( 'header_type', '' );
				update_option( 'mega_main_menu_options', $options_backup );
			}
		}
		set_theme_mod( 'old_header_type_for_link', $header_type );
	}

}