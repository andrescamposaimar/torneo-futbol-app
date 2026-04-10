<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$input_id    = isset( $input_id ) ? $input_id : 'quantity_' . uniqid();
$input_name  = isset( $input_name ) ? $input_name : 'quantity';
$input_value = isset( $input_value ) ? $input_value : '1';
$max_value   = isset( $max_value ) ? $max_value : '';
$min_value   = isset( $min_value ) ? $min_value : '1';
$step        = isset( $step ) ? $step : '1';
?>
<div class="quantity">
   <button type="button" class="minus">-</button>  
    <input
        type="number"
        id="<?php echo esc_attr( $input_id ); ?>"
        class="input-text qty text"
        step="<?php echo esc_attr( $step ); ?>"
        min="<?php echo esc_attr( $min_value ); ?>"
        max="<?php echo esc_attr( $max_value ); ?>"
        name="<?php echo esc_attr( $input_name ); ?>"
        value="<?php echo esc_attr( $input_value ); ?>"
        title="<?php echo esc_attr_x( 'Qty', 'Product quantity input tooltip', 'newscrunch' ); ?>"
        size="4"
        placeholder="<?php echo esc_attr( $placeholder ); ?>"
        inputmode="<?php echo esc_attr( $inputmode ); ?>" />
    <button type="button" class="plus">+</button>
</div>