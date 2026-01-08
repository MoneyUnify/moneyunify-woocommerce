/**
 * MoneyUnify WooCommerce Checkout Script
 * Ensures payment gateway works with all WooCommerce themes
 * including custom checkout builders like Elementor, Divi, etc.
 */
(function($) {
    'use strict';
    
    // Wait for jQuery and WooCommerce to be ready
    $(document).ready(function() {
        moneyunifyInit();
    });
    
    // Also try on document ready as backup
    $(document).on('ready', moneyunifyInit);
    
    function moneyunifyInit() {
        // Add hidden input field for phone number if it doesn't exist
        moneyunifyEnsureFields();
        
        // Listen for checkout updates
        $(document.body).on('updated_checkout payment_method_selected', function() {
            moneyunifyEnsureFields();
        });
        
        // Validate on order review submit
        $(document.body).on('checkout_place_order', function(e) {
            return moneyunifyValidate(e);
        });
        
        // Handle AJAX checkout
        $(document.body).on('checkout_error', function() {
            moneyunifyEnsureFields();
        });
    }
    
    function moneyunifyEnsureFields() {
        // Check if we're on moneyunify payment method
        var isMoneyUnify = false;
        
        // Check radio buttons
        $('input[name="payment_method"]').each(function() {
            if ($(this).val() === 'moneyunify' && $(this).is(':checked')) {
                isMoneyUnify = true;
            }
        });
        
        // Check if moneyunify container exists
        var $container = $('#moneyunify-payment-fields');
        
        if (isMoneyUnify && $container.length === 0) {
            // MoneyUnify selected but fields not shown - inject them
            moneyunifyInjectFields();
        }
        
        if (isMoneyUnify && $container.length > 0 && $container.find('input[name="moneyunify_phone"]').length === 0) {
            // Fields exist but input missing - add it
            moneyunifyInjectFields();
        }
    }
    
    function moneyunifyInjectFields() {
        // Find where to insert the payment fields
        var $paymentMethod = $('input[name="payment_method"][value="moneyunify"]');
        
        if ($paymentMethod.length === 0) return;
        
        var $label = $paymentMethod.closest('li').find('label');
        var $parent = $paymentMethod.closest('li');
        
        // Create the payment fields HTML
        var html = '<div id="moneyunify-payment-fields" class="payment_box payment_method_moneyunify" style="display:none;">';
        html += '<p class="form-row form-row-wide">';
        html += '<label for="moneyunify_phone">Mobile Money Number <span class="required">*</span></label>';
        html += '<input type="tel" class="input-text" name="moneyunify_phone" id="moneyunify_phone" placeholder="097XXXXXXX" pattern="[0-9]{9,12}" />';
        html += '</p>';
        html += '<p class="form-row form-row-wide">';
        html += '<small>You will receive a payment request on your phone. Approve it to complete payment.</small>';
        html += '</p>';
        html += '</div>';
        
        // Insert after the label
        if ($label.length > 0) {
            $label.after(html);
        } else {
            $parent.append(html);
        }
        
        // Show the fields
        $('#moneyunify-payment-fields').slideDown(200);
        
        // Store reference
        window.moneyunifyFieldsInjected = true;
    }
    
    function moneyunifyValidate(e) {
        // Check if moneyunify is selected
        var isMoneyUnify = false;
        
        $('input[name="payment_method"]').each(function() {
            if ($(this).val() === 'moneyunify' && $(this).is(':checked')) {
                isMoneyUnify = true;
            }
        });
        
        if (!isMoneyUnify) return true;
        
        var phone = $('input[name="moneyunify_phone"]').val();
        
        if (!phone || !/^[0-9]{9,12}$/.test(phone)) {
            // Remove any existing error messages
            $('.woocommerce-error').remove();
            
            // Add error message at the top of checkout
            var $checkoutForm = $('form.checkout');
            if ($checkoutForm.length > 0) {
                $checkoutForm.prepend('<div class="woocommerce-error">Please enter a valid mobile money number (9-12 digits).</div>');
            } else {
                alert('Please enter a valid mobile money number (9-12 digits).');
            }
            
            // Scroll to top
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 500);
            
            return false;
        }
        
        return true;
    }
    
    // Backup: Handle form submission directly
    $(document.body).on('submit', 'form.checkout', function(e) {
        var isMoneyUnify = false;
        
        $('input[name="payment_method"]').each(function() {
            if ($(this).val() === 'moneyunify' && $(this).is(':checked')) {
                isMoneyUnify = true;
            }
        });
        
        if (isMoneyUnify) {
            var phone = $('input[name="moneyunify_phone"]').val();
            if (!phone || !/^[0-9]{9,12}$/.test(phone)) {
                e.preventDefault();
                
                var $form = $(this);
                $('<div class="woocommerce-error">Please enter a valid mobile money number (9-12 digits).</div>')
                    .prependTo($form)
                    .focus();
                
                $form.removeClass('processing');
                $form.find('#place_order').prop('disabled', false);
                
                return false;
            }
        }
    });
    
})(jQuery);
