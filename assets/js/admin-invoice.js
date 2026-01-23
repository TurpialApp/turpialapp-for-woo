/**
 * Admin Invoice JavaScript
 *
 * Handles the invoice creation button functionality in WooCommerce order metabox.
 *
 * @package CachicamoApp_For_WooCommerce
 * @since 1.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize invoice creation button
        const initInvoiceButton = function() {
            $('#cachicamoapp_create_invoice').on('click', function(e) {
                e.preventDefault();
                const $button = $(this);
                const $spinner = $button.next('.spinner');
                const orderId = cachicamoapp_invoice_data.order_id;
                const nonce = cachicamoapp_invoice_data.nonce;
                
                $button.prop('disabled', true);
                $spinner.css('visibility', 'visible');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cachicamoapp_create_invoice',
                        order_id: orderId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload(); // Reload the page on success.
                        } else {
                            alert(response.data.message || cachicamoapp_invoice_data.error_message);
                            $button.prop('disabled', false);
                            $spinner.css('visibility', 'hidden');
                        }
                    },
                    error: function() {
                        alert(cachicamoapp_invoice_data.connection_error);
                        $button.prop('disabled', false);
                        $spinner.css('visibility', 'hidden');
                    }
                });
            });
        };

        // Initialize if the elements exist
        if ($('#cachicamoapp_create_invoice').length) {
            initInvoiceButton();
        }
    });
})(jQuery);
