jQuery(document).ready(function($) {
    if ($('.woocommerce-product-gallery').length) {
        // Add navigation arrows
        $('.woocommerce-product-gallery').prepend('<div class="slider-prev">‹</div>');
        $('.woocommerce-product-gallery').append('<div class="slider-next">›</div>');
        
        // Event listeners for arrows
        $('.slider-prev').on('click', function() {
            $('.woocommerce-product-gallery').flexslider('prev');
        });
        
        $('.slider-next').on('click', function() {
            $('.woocommerce-product-gallery').flexslider('next');
        });
    }
});