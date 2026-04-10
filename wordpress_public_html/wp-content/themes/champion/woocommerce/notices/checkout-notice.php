<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! $messages ) return;
?>

<?php foreach ( $messages as $message ) : ?>
	<div class="woocommerce-checkout-info"><div class="notice_content"><?php echo wp_kses_post( $message ); ?></div></div>
<?php endforeach; ?>