jQuery(function($) {
    // Remove item from cart via AJAX
    $('body').on('click', '.remove-item', function(e) {
        var rowCount = $('tr.woocommerce-cart-form__cart-item').length;
        e.preventDefault();
        var itemKey = $(this).data('cart-item-key');
        var data = {
            action: 'remove_cart_item',
            cart_item_key: itemKey,
            security: customCartData.nonce
        };

        $.post(customCartData.ajax_url, data, function(response) {
            if (rowCount == 1) {
                location.reload(); // Reload the cart after last item removal
            }
        });
    });

    $('body').on('click', 'button.plus, button.minus', function() {
        var $qty = $(this).closest('.quantity').find('input.qty'); // Find the input field
        var currentVal = parseInt($qty.val(), 10); // Parse the current value
        var maxVal = parseInt($qty.attr('max'), 10); // Get max value
        var minVal = parseInt($qty.attr('min'), 10); // Get min value
        var step = parseInt($qty.attr('step'), 10); // Get step value, default to 1

        if (!currentVal) currentVal = 0; // Handle NaN case

        if ($(this).hasClass('plus')) {
            if (!maxVal || currentVal < maxVal) {
                $qty.val(currentVal + step).change(); // Increment by step
            }
        } else if ($(this).hasClass('minus')) {
            if (currentVal == 0) {
                return false;
            }
            if (!minVal || currentVal > minVal) {
                $qty.val(currentVal - step).change(); // Decrement by step
            }
        }
    });

    // Enable the "Update Cart" button when quantity is changed
    $('body').on('change', 'input.qty', function() {
        $(this).closest('form').find('button[name="update_cart"]').prop('disabled', false);
    });
});