jQuery(document).ready(function($) {
    $('.quantity').on('click', '.plus, .minus', function() {
        let qty = $(this).siblings('.qty');
        let currentVal = parseFloat(qty.val());
        let max = parseFloat(qty.attr('max'));
        let min = parseFloat(qty.attr('min'));
        let step = parseFloat(qty.attr('step')) || 1;

        if ($(this).hasClass('plus')) {
            if (!max || currentVal + step <= max) {
                qty.val(currentVal + step).change();
            }
        } else {
            if (!min || currentVal - step >= min) {
                qty.val(currentVal - step).change();
            }
        }
    });
});