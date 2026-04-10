<?php

/*--------------------------------------------------------------------*/
/*     Register Google Fonts
/*--------------------------------------------------------------------*/
//Load font from google api.

function newscrunch_google_font_scripts_styles() {
    if ('NewsBlogger' == wp_get_theme()) {
        wp_enqueue_style('newscrunch-font-jost', 'https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap');
    }
    else {
        wp_enqueue_style('newscrunch-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap');
        wp_enqueue_style('newscrunch-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap');
    }
}
if(get_theme_mod('newscrunch_enable_local_google_font',true) ==false){
    add_action( 'wp_enqueue_scripts', 'newscrunch_google_font_scripts_styles');
}
?>
<?php 
/**
* Enqueue theme fonts.
*/
if(get_theme_mod('newscrunch_enable_local_google_font',true) ==true){
    add_action( 'wp_enqueue_scripts', 'newscrunch_theme_fonts',1 );
    add_action( 'enqueue_block_editor_assets', 'newscrunch_theme_fonts',1 );
    add_action( 'customize_preview_init', 'newscrunch_theme_fonts', 1 );

    function newscrunch_theme_fonts() {
        $fonts_url = newscrunch_get_fonts_url();
        // Load Fonts if necessary.
        if ( $fonts_url ) {
            get_template_part( 'inc/font/wptt-webfont-loader' );
            wp_enqueue_style( 'newscrunch-theme-fonts', wptt_get_webfont_url( $fonts_url ), array(), '20201110' );
        }
    }
}
/**
 * Retrieve webfont URL to load fonts locally.
 */
function newscrunch_get_fonts_url() {
    $font_families = array(
        'Poppins:300,300i,400,400i,500,500i,600,600i,700,700i,800,800i,900',    
        'Inter:100,200,300,400,500,600,700,800,900',
        'Jost:300,300i,400,400i,500,500i,600,600i,700,700i,800,800i,900',   
    );
    $query_args = array(
        'family'  => urlencode( implode( '|', $font_families ) ),
        'subset'  => urlencode( 'latin,latin-ext' ),
        'display' => urlencode( 'swap' ),
    );
    return apply_filters( 'newscrunch_get_fonts_url', add_query_arg( $query_args, 'https://fonts.googleapis.com/css' ) );
}
?>