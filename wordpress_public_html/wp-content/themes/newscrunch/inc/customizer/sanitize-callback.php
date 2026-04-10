<?php
/**
 * Sanitization Callbacks
 * 
 * @package Newscrunch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * array sanitization callback
*/
function newscrunch_sanitize_array( $value ){
        if ( is_array( $value ) ) {
                foreach ( $value as $key => $subvalue ) {
                        $value[ $key ] = esc_attr( $subvalue );
                }
                return $value;
        }
        return esc_attr( $value );
}

/**
 * Checkbox sanitization callback
*/
function newscrunch_sanitize_checkbox($checked) {
    // Boolean check.
    return ( ( isset($checked) && true == $checked ) ? true : false );
}

/**
 * Select choices sanitization callback
*/
function newscrunch_sanitize_select($input, $setting) {
    //input must be a slug: lowercase alphanumeric characters, dashes and underscores are allowed only
    $input = sanitize_key($input);
    //get the list of possible radio box options 
    $choices = $setting->manager->get_control($setting->id)->choices;
    //return input if valid or return default option
    return ( array_key_exists($input, $choices) ? $input : $setting->default );
}

/**
 * Text sanitization callback
*/
function newscrunch_sanitize_text($input) {
    return wp_kses_post(force_balance_tags($input));
}

/**
 * Multiple Select sanitization callback
*/
function newscrunch_select_text_sanitization( $input ) {
    if ( strpos( $input, ',' ) !== false) {
        $input = explode( ',', $input );
    }
    if( is_array( $input ) ) {
        foreach ( $input as $key => $value ) {
            $input[$key] = sanitize_text_field( $value );
        }
        $input = implode( ',', $input );
    }
    else {
        $input = sanitize_text_field( $input );
    }
    return $input;
}

 function newscrunch_sanitize_dropdown_post_title_field( $input ) {
 
    
    $new_input = array();

    // Loop through the input and sanitize each of the values
    foreach ( $input as $key => $val ) {
        
        $new_input[ $key ] = ( isset( $input[ $key ] ) ) ?
            sanitize_text_field( $val ) :
            '';

    }

    return $new_input;

}

/**
 * Range number sanitization callback
*/
function newscrunch_sanitize_number_range($input, $setting) {

    // Ensure input is an absolute integer.
    $input = absint($input);

    // Get the input attributes associated with the setting.
    $atts = $setting->manager->get_control($setting->id)->input_attrs;

    // Get min.
    $min = ( isset($atts['min']) ? $atts['min'] : $input );

    // Get max.
    $max = ( isset($atts['max']) ? $atts['max'] : $input );

    // Get Step.
    $step = ( isset($atts['step']) ? $atts['step'] : 1 );

    // If the input is within the valid range, return it; otherwise, return the default.
    return ( $min <= $input && $input <= $max && is_int($input / $step) ? $input : $setting->default );
    
}