(function($) {
    'use strict';

    /**
     * Admin settings JavaScript for Visa Acceptance Solutions
     */
    $(document).ready(function() {
        // Only run if we have the settings data.
        if (typeof vasAdminSettings === 'undefined') {
            return;
        }

        // Add Visa logo to title header.
        function titleHeaderLogo() {
            $('.woocommerce h2').addClass('wc-title-header');
            $(".wc-title-header").each(function() {
               var textContent = $(this).text();
                if (textContent && textContent.replace(/^\s+|\s+$/g, '')) {
                    var logo = $("<img>", {
                        src: vasAdminSettings.logoUrl,
                        alt: "Visa Logo",
                        css: {
                            height: "25px",
                            marginLeft: "8px",
                            verticalAlign: "middle"
                        }
                    });
                    $(this).append(logo);
                }
            });
        }
        titleHeaderLogo();

        // Environment field toggle.
        $('#woocommerce_' + vasAdminSettings.gatewayId + '_environment').change(function() {
            var environment = $(this).val();
            // Hide all environment-dependent fields.
            $('.environment-field').closest('tr').hide();
            // Show the currently configured environment fields.
            var environmentFields = $('.' + environment + '-field');
            environmentFields.not('.hidden').closest('tr').show();
            // Check if element exists before accessing it.
            var $dmCheckbox = $('#woocommerce_visa_acceptance_solutions_gpay_enable_decision_manager');
            if ($dmCheckbox.length && $dmCheckbox.is(':checked')) {
                environmentFields.filter('.hidden').closest('tr').show();
            }
        }).change();

        // Transaction type field toggle (if exists).
        if (vasAdminSettings.hasTransactionType) {
            $('#woocommerce_' + vasAdminSettings.gatewayIdFull + '_transaction_type').change(function() {
                var transaction_type = $(this).val();
                var virtualOrderConfiguration = $('#woocommerce_visa_acceptance_solutions_unified_checkout_charge_virtual_orders').closest('tr');
                var capturePaidOrders = $('#woocommerce_visa_acceptance_solutions_unified_checkout_enable_paid_capture').closest('tr');
                if (transaction_type == 'charge') {
                    virtualOrderConfiguration.hide();
                    capturePaidOrders.hide();
                } else {
                    virtualOrderConfiguration.show();
                    capturePaidOrders.show();
                }
            }).change();
        }
         // Webhook deletion handler.
        $(document).on('click', '.vas-delete-webhook', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var webhookId = $button.data('webhook-id');
            var productId = $button.data('product-id');
            var $row = $button.closest('tr');
            
            if (!confirm('Are you sure you want to delete the webhook subscription for "' + productId + '"?')) {
                return;
            }
            
            // Disable button and show loading state.
            $button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'vas_delete_webhook',
                    webhook_id: webhookId,
                    nonce: vasAdminSettings.webhookNonce
                },
                success: function(response) {
                    if (response.success) {
                        // Remove the row with fade effect.
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            
                            // Check if table is now empty.
                            var remainingRows = $('.vas-webhooks-table tbody tr').length;
                            if (remainingRows === 0) {
                                $('#vas-webhooks-container').html(
                                    '<p class="vas-no-webhooks">' +
                                    'No webhook subscriptions found. ' +
                                    'Enable webhook features in settings above and save to automatically subscribe.' +
                                    '</p>'
                                );
                            }
                        });
                        
                        // Show success notice.
                        if (response.data && response.data.message) {
                            showVasNotice(response.data.message, 'success');
                        }
                    } else {
                        // Show error message.
                        var errorMsg = response.data && response.data.message 
                            ? response.data.message 
                            : 'Failed to delete webhook.';
                        showVasNotice('Error: ' + errorMsg, 'error');
                        
                        // Re-enable button.
                        $button.prop('disabled', false).text('Delete');
                    }
                },
                error: function(xhr, status, error) {
                    showVasNotice('Error: Failed to delete webhook. Please try again.', 'error');
                    $button.prop('disabled', false).text('Delete');
                }
            });
        });

        // Sanitize and validate message input.
        function sanitizeMessage(message) {
            // Convert to string and ensure it's defined
            if (typeof message === 'undefined' || message === null) {
                return '';
            }
            
            // Convert to string and limit length to prevent abuse.
            var sanitized = String(message).substring(0, 500);
            
            // Remove any potential script tags or HTML.
            sanitized = sanitized.replace(/<[^>]*>/g, '');
            
            return sanitized;
        }

        // Helper function to show admin notices.
        function showVasNotice(message, type) {
            // Sanitize message before use.
            var sanitizedMessage = sanitizeMessage(message);
            
            if (!sanitizedMessage) {
                return; // Don't show empty notices.
            }
            
            type = type || 'info';
            var noticeClass = 'notice-' + type;
            var noticeEl = document.createElement('div');
            noticeEl.className = 'notice ' + noticeClass + ' is-dismissible';

            var paragraphEl = document.createElement('p');
            paragraphEl.appendChild(document.createTextNode(sanitizedMessage));
            noticeEl.appendChild(paragraphEl);

            var $notice = $(noticeEl);
            
            // Remove existing VAS notices.
            $('.notice.vas-webhook-notice').remove();
            
            // Add new notice.
            $notice.addClass('vas-webhook-notice');
            if ($('.wrap > h1').length) {
                $notice.insertAfter('.wrap > h1');
            } else {
                $('.wrap').prepend($notice);
            }
            
            // Auto-dismiss after 5 seconds.
            setTimeout(function() {
                $notice.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });

})(jQuery);
