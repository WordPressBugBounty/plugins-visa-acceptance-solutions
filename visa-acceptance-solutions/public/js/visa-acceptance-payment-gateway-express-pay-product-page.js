(function($) {
    'use strict';

    var disableLoader = false;

    setInterval(checkDisableLoader, 1000);

    function checkDisableLoader() {
        if (disableLoader) {
            jQuery('.blockUI.blockOverlay').hide();
            disableLoader = false;
            location.reload();
        }
    }

    //Building Error Message.
    const errorMessagePara = document.createElement("p");
    errorMessagePara.setAttribute('style','color:red')
    const errorMessageText = document.createTextNode(express_pay_ajaxUCObj['form_load_error']);
    errorMessagePara.appendChild(errorMessageText);

    function reloadAfterError() {
        setTimeout(() => {
            window.location.reload();
        }, 5000);
    }

    function addLoader() {
        // Remove existing loader if any
        var existingLoader = document.getElementById('loader');
        if (existingLoader) {
            existingLoader.parentNode.removeChild(existingLoader);
        }

        var loaderHtml = `
            <div id="ep_loader" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(239, 239, 239, 0.5); z-index:999999;">
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);">
                    <div class="loader-spinner" style="border:4px solid #f3f3f3; border-top:4px solid #3498db; border-radius:50%; width:50px; height:50px; margin:0 auto 10px; animation:spin 1s linear infinite;"></div>
                </div>
                <style>@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>
            </div>`;

        // Append to top window if possible
        try {
            window.top.document.body.insertAdjacentHTML('beforeend', loaderHtml);
        } catch (e) {
            document.body.insertAdjacentHTML('beforeend', loaderHtml);
        }
    }

    function showExpressPayLoader() {
        addLoader();
        var loader = document.getElementById('ep_loader');
        if (loader) {
            loader.style.display = 'block';
        }
    }

    function hideExpressPayLoader() {
        try {
            var topLoader = window.top.document.getElementById('ep_loader');
            if (topLoader) {
                topLoader.parentNode.removeChild(topLoader);
            }
        } catch (e) {
            var loader = document.getElementById('loader');
            if (loader) {
                loader.parentNode.removeChild(loader);
            }
        }
    }

    // Global variable to track selected variation ID for variable products
    var selectedVariationId = null;
    
    // Global variable to track grouped product quantities
    var groupedProductQuantities = {};
    
    //Update capture context for quantity changes on product page.
    jQuery(document).ready(function($) {
        var captureContext = document.getElementById("jwt_updated") ? document.getElementById("jwt_updated").value : (document.getElementById("jwt") ? document.getElementById("jwt").value : null);
        
        // Initialize grouped product quantities if this is a grouped product
        if (express_pay_ajaxUCObj['is_grouped_product'] && express_pay_ajaxUCObj['grouped_product_ids']) {
            express_pay_ajaxUCObj['grouped_product_ids'].forEach(function(productId) {
                var qtyInput = $('input[name="quantity[' + productId + ']"]');
                if (qtyInput.length) {
                    groupedProductQuantities[productId] = parseInt(qtyInput.val()) || 0;
                }
            });
        }
        
        // Check if variation form exists and trigger initial check
        var variationForm = $('form.variations_form');
        if (variationForm.length) {
            // Trigger check after a short delay to allow WooCommerce to initialize
            setTimeout(function() {
                variationForm.trigger('check_variations');
            }, 100);
        }
        
        // Show Express Pay when variation is selected for variable products.
        $('form.variations_form').on('found_variation', function(event, variation) {
            var expressPayDiv = $('#wc-express-checkout-product');
            // Check if variation is valid and purchasable
            if (expressPayDiv.length && variation && variation.variation_id && variation.is_purchasable) {
                selectedVariationId = variation.variation_id; // Store selected variation ID
                var isCurrentlyHidden = expressPayDiv.is(':hidden');
                console.log('Variation selected:', variation.variation_id, 'Hidden:', isCurrentlyHidden);
                // Update capture context with variation details
                showExpressPayLoader();
                $.ajax({
                    url: express_pay_ajaxUCObj.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'product_page_quantity_update',
                        nonce: express_pay_ajaxUCObj.quantity_update_nonce,
                        quantity: $('input.qty').val() || 1,
                        product_id: variation.variation_id,
                        timestamp: new Date().getTime(),
                        force_refresh: 1,
                        is_switch: express_pay_ajaxUCObj.is_switch || false,
                        switch_subscription_id: express_pay_ajaxUCObj.switch_subscription_id || '',
                        switch_item_id: express_pay_ajaxUCObj.switch_item_id || ''
                    },
                    success: function(response) {
                        console.log('AJAX Response:', response);
                        if (response.success) {
                            // Handle case where capture_context_ep_jwt might be null
                            if (response.capture_context_ep_jwt) {
                                captureContext = response.capture_context_ep_jwt;
                            } else if (!captureContext) {
                                // No capture context available at all
                                console.error('No capture context is available.');
                                hideExpressPayLoader();
                                return;
                            }
                            // If response has null but we have existing captureContext, continue with existing one
                            var jwtInput = document.getElementById('jwt_updated') || document.getElementById('jwt');
                            if (!jwtInput) {
                                jwtInput = document.createElement('input');
                                jwtInput.type = 'hidden';
                                jwtInput.id = 'jwt_updated';
                                document.body.appendChild(jwtInput);
                            }
                            jwtInput.value = captureContext;
                            
                            // Always show Express Pay section after successful variation selection
                            expressPayDiv.show();
                            
                            if (typeof initProductPageExpressCheckout === 'function') {
                                initProductPageExpressCheckout();
                                setTimeout(function() {
                                    hideExpressPayLoader();
                                }, 500);
                            }
                        } else {
                            console.error('Failed to update the capture context for the selected variation:', response);
                            hideExpressPayLoader();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('An error occurred while updating the variation via AJAX:', error);
                        hideExpressPayLoader();
                    }
                });
            }
        });
        
        // Hide Express Pay when variation is reset/cleared or when no valid variation found
        $('form.variations_form').on('reset_data reset_image hide_variation', function() {
            selectedVariationId = null; // Clear selected variation
            var expressPayDiv = $('#wc-express-checkout-product');
            if (expressPayDiv.length && expressPayDiv.is(':visible')) {
                expressPayDiv.hide();
            }
        });
        
        // Handle "Clear" link click
        $(document).on('click', '.reset_variations', function() {
            selectedVariationId = null; // Clear selected variation
            var expressPayDiv = $('#wc-express-checkout-product');
            if (expressPayDiv.length && expressPayDiv.is(':visible')) {
                expressPayDiv.hide();
            }
        });
        
        // Also hide on change if returning to "Choose an option"
        $('form.variations_form').on('change', 'select', function() {
            var allSelected = true;
            $('form.variations_form select').each(function() {
                if (!$(this).val() || $(this).val() === '') {
                    allSelected = false;
                    return false;
                }
            });
            
            if (!allSelected) {
                var expressPayDiv = $('#wc-express-checkout-product');
                if (expressPayDiv.length && expressPayDiv.is(':visible')) {
                    expressPayDiv.hide();
                }
            }
        });
        
        // Handle quantity changes for grouped products
        if (express_pay_ajaxUCObj['is_grouped_product']) {
            $('form.cart').on('input change', 'input[name^="quantity["]', function() {
                var inputName = $(this).attr('name');
                var productId = inputName.match(/\d+/)[0];
                var newQuantity = parseInt($(this).val()) || 0;
                
                // Prevent negative values
                if (newQuantity < 0) {
                    $(this).val(0);
                    newQuantity = 0;
                }
                
                // Update tracked quantities
                groupedProductQuantities[productId] = newQuantity;
                
                // Check if any product has quantity > 0
                var hasValidQuantity = Object.values(groupedProductQuantities).some(function(qty) {
                    return qty > 0;
                });
                
                if (!hasValidQuantity) {
                    // Hide express pay if no products selected
                    $('#wc-express-checkout-product').hide();
                    return;
                }
                
                // Debounce the update
                clearTimeout(window.groupedQtyUpdateTimer);
                window.groupedQtyUpdateTimer = setTimeout(function() {
                    showExpressPayLoader();
                    
                    $.ajax({
                        url: express_pay_ajaxUCObj.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'product_page_quantity_update',
                            nonce: express_pay_ajaxUCObj.quantity_update_nonce,
                            product_id: express_pay_ajaxUCObj['product_id'],
                            grouped_items: groupedProductQuantities,
                            force_refresh: 1,
                            is_switch: express_pay_ajaxUCObj.is_switch || false,
                            switch_subscription_id: express_pay_ajaxUCObj.switch_subscription_id || '',
                            switch_item_id: express_pay_ajaxUCObj.switch_item_id || ''
                        },
                        success: function(response) {
                            if (response.success && response.capture_context_ep_jwt) {
                                captureContext = response.capture_context_ep_jwt;
                                
                                var jwtInput = document.getElementById('jwt_updated') || document.getElementById('jwt');
                                if (!jwtInput) {
                                    jwtInput = document.createElement('input');
                                    jwtInput.type = 'hidden';
                                    jwtInput.id = 'jwt_updated';
                                    document.body.appendChild(jwtInput);
                                }
                                jwtInput.value = captureContext;
                                
                                // Show Express Pay section
                                $('#wc-express-checkout-product').show();
                                
                                if (typeof initProductPageExpressCheckout === 'function') {
                                    initProductPageExpressCheckout();
                                    setTimeout(function() {
                                        hideExpressPayLoader();
                                    }, 500);
                                }
                            } else {
                                console.error('Failed to update the capture context for grouped products:', response)
                                hideExpressPayLoader();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('An error occurred while updating grouped product quantities via AJAX:', error);
                            hideExpressPayLoader();
                        }
                    });
                }, 500);
            });
        }
        
        // Handle quantity changes for simple and variable products (not grouped)
        if (!express_pay_ajaxUCObj['is_grouped_product']) {
            $('form.cart').on('input change', 'input.qty', function() {
                var updatedQuantity = $(this).val();
                
                // Prevent negative numbers and values below 1
                var quantity = parseInt(updatedQuantity);
                if (!isNaN(quantity) && quantity < 1) {
                    $(this).val(1); // Reset to 1 if below 1
                    return;
                }
                
                // For variable products, skip if no variation is selected yet
                if ($('form.variations_form').length > 0 && !selectedVariationId) {
                    return;
                }

            // Optional: Add a small debounce so it doesn't trigger too often
            clearTimeout(window.qtyUpdateTimer);
            window.qtyUpdateTimer = setTimeout(function() {
                // Only proceed if quantity is a valid number greater than 0
                var quantity = parseInt(updatedQuantity);
                if (isNaN(quantity) || quantity < 1) {
                    return; // Don't trigger AJAX for invalid quantities
                }
                
                showExpressPayLoader();
                
                // For variable products, use the selected variation ID, otherwise use the product ID
                var productIdToUse = selectedVariationId || express_pay_ajaxUCObj['product_id'];
                
                $.ajax({
                    url: express_pay_ajaxUCObj.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'product_page_quantity_update',
                        nonce: express_pay_ajaxUCObj.quantity_update_nonce,
                        quantity: updatedQuantity,
                        product_id: productIdToUse,
                        force_refresh: selectedVariationId ? 1 : 0,
                        is_switch: express_pay_ajaxUCObj.is_switch || false,
                        switch_subscription_id: express_pay_ajaxUCObj.switch_subscription_id || '',
                        switch_item_id: express_pay_ajaxUCObj.switch_item_id || ''
                    },
                    success: function(response) {
                        if (response.success && response.capture_context_ep_jwt) {
                            // update the capture context used by Accept
                            captureContext = response.capture_context_ep_jwt;

                            // update hidden input used elsewhere
                            var jwtInput = document.getElementById('jwt_updated') || document.getElementById('jwt');
                            if (!jwtInput) {
                                jwtInput = document.createElement('input');
                                jwtInput.type = 'hidden';
                                jwtInput.id = 'jwt_updated';
                                document.body.appendChild(jwtInput);
                            }
                            jwtInput.value = captureContext;

                            if (typeof initProductPageExpressCheckout === 'function') {
                                initProductPageExpressCheckout();
                                setTimeout(function() {
                                    hideExpressPayLoader();
                                }, 500);
                            }
                        } else {
                            console.error('Failed to update the capture context:', response);
                            hideExpressPayLoader();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('An error occurred while updating the quantity via AJAX:', error);
                        hideExpressPayLoader();
                    }
                });
            }, 200);
            });
        }
    });

    function initProductPageExpressCheckout() {
        var transientToken = document.getElementById("transientToken");
        var captureContext = document.getElementById("jwt_updated") ? document.getElementById("jwt_updated").value : (document.getElementById("jwt") ? document.getElementById("jwt").value : null);
        var currentQuantity = jQuery('form.cart input.qty').val();
        var showArgs = {
            containers: {
                paymentSelection: "#expressPaymentListContainer_product"
            }
        };

        if (typeof VAS !== 'undefined' && typeof VAS.UnifiedCheckout === 'function') {
            $('#wc-error-failure').hide();

            async function launchProductExpressCheckout() {
                let client;
                let checkout;
                try {
                    client = await VAS.UnifiedCheckout(captureContext);
                    checkout = await client.createCheckout({ autoProcessing: false });
                    const tt = await checkout.mount('#expressPaymentListContainer_product');

                if (transientToken) {
                    transientToken.value = tt;
                }

                var container = document.getElementById('expressPaymentListContainer_product');
                if (container) {
                        // Show loader while processing.
                        showExpressPayLoader();
                        var completeMandatePromise = checkout.complete(tt)
                                .then(function(completeResponse) {
                                    if (!completeResponse) {
                                        return '';
                                    }
                                    var serialized = '';
                                    try {
                                        if (typeof completeResponse === 'string') {
                                            var trimmed = completeResponse.trim();
                                            if (
                                                (trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}') ||
                                                (trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']')
                                            ) {
                                                serialized = trimmed;
                                            } else {
                                                // Treat as JWT — decode the middle (payload) segment.
                                                var jwtParts = trimmed.split('.');
                                                if (jwtParts.length === 3) {
                                                    try {
                                                        var decoded = JSON.parse(atob(jwtParts[1]));
                                                        serialized = JSON.stringify(decoded);
                                                    } catch (jwtErr) {
                                                        serialized = trimmed;
                                                    }
                                                } else {
                                                    serialized = JSON.stringify(JSON.parse(trimmed));
                                                }
                                            }
                                        } else {
                                            serialized = JSON.stringify(completeResponse);
                                        }
                                    } catch (e) { serialized = ''; }
                                    return serialized;
                                })
                                .catch(function() {
                                    return '';
                                });

                        return completeMandatePromise.then(function(completeResponse) {
                        var orderData = { 
                            action: 'express_pay_for_order', 
                            product_id: express_pay_ajaxUCObj['product_id'],
                            billing_details: express_pay_ajaxUCObj['billing_details'],
                            shipping_details: express_pay_ajaxUCObj['shipping_details'],
                            payment_method: express_pay_ajaxUCObj['visa_acceptance_solutions_uc_id'],
                            transientToken: tt,
                            quantity: currentQuantity,
                            is_switch: express_pay_ajaxUCObj.is_switch || false,
                            switch_subscription_id: express_pay_ajaxUCObj.switch_subscription_id || '',
                            switch_item_id: express_pay_ajaxUCObj.switch_item_id || '',
                            uc_complete_response: completeResponse
                        };
                        
                        // For variable products, add the variation ID
                        if (selectedVariationId) {
                            orderData.variation_id = selectedVariationId;
                        }
                        
                        // For grouped products, add grouped items with quantities
                        if (express_pay_ajaxUCObj['is_grouped_product']) {
                            orderData.grouped_items = groupedProductQuantities;
                        }
                        
                        jQuery.ajax({
                            url: express_pay_ajaxUCObj.ajax_url,
                            type: 'POST',
                            data: orderData,
                            success: function(response) {
                                if (response.success && response.data && response.data.sca_reload) {
                                    hideExpressPayLoader();
                                    var scaMsg = response.data.message || 'Your card issuer requires an extra security check to approve this payment. Please try again and follow the verification steps.';
                                    var errorList = document.createElement('ul');
                                    errorList.className = 'woocommerce-error';
                                    errorList.setAttribute('role', 'alert');
                                    var errorItem = document.createElement('li');
                                    errorItem.appendChild(document.createTextNode(scaMsg));
                                    errorList.appendChild(errorItem);
                                    var noticesWrapper = jQuery('.woocommerce-notices-wrapper').first();
                                    noticesWrapper.empty();
                                    noticesWrapper.append(errorList);
                                    jQuery('html, body').animate({ scrollTop: noticesWrapper.offset().top - 100 }, 400);
                                    setTimeout(function() { window.location.reload(); }, 1500);
                                    return;
                                }
                                // On successful payment redirect to order confirmation page.
                                if (response.success && response.data.redirect_url) {
                                    window.location.href = response.data.redirect_url;
                                    hideExpressPayLoader();
                                } else if (!response.success && response.data && response.data.message) {
                                    hideExpressPayLoader();
                                    $('.woocommerce-notices-wrapper .visa-express-pay-error').remove(); 
                                    var noticesWrapper = document.querySelector('.woocommerce-notices-wrapper');
                                    var errorDiv = document.createElement('div');
                                    errorDiv.className = 'visa-express-pay-error';
                                    errorDiv.setAttribute('role', 'alert');
                                    errorDiv.style.cssText = 'background-color:#fff0f0;border:1px solid #cc0000;border-left:4px solid #cc0000;padding:12px 16px;margin:0 0 16px;';
                                    errorDiv.textContent = response.data.message;
                                    if (noticesWrapper) {
                                        noticesWrapper.textContent = '';
                                        noticesWrapper.appendChild(errorDiv);
                                        noticesWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    } else {
                                        var formCart = document.querySelector('form.cart');
                                        if (formCart) {
                                            var wrapperDiv = document.createElement('div');
                                            wrapperDiv.className = 'woocommerce-notices-wrapper';
                                            wrapperDiv.appendChild(errorDiv);
                                            formCart.parentNode.insertBefore(wrapperDiv, formCart);
                                            wrapperDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }
                                    }
                                } else {
                                    window.location.reload();
                                    $('#expressPaymentListContainer_product').hide();
                                    hideExpressPayLoader();
                                }
                            }
                        });
                        }); // end completeMandatePromise.then
                    }
                    } catch (error) {
                        $('#wc-error-failure').show();
                        $('#expressPaymentListContainer_product').hide();
                        hideExpressPayLoader();
                        reloadAfterError();
                        if (checkout) { checkout.destroy(); }
                    if (client) { client.destroy(); }
                    }
                }
            launchProductExpressCheckout();
        } else {
            expressPaymentListContainer_product.append(errorMessagePara);
            jQuery('#wc-express-checkout-product-page-tax-shipping-notice').hide();
            jQuery('#wc-express-checkout-product-page-save-token-div').hide();
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        // Run on product page only.
        if (document.querySelector("form.cart")) {
            // Don't initialize Express Pay for variable products on page load
            // It will be initialized when a variation is selected via the found_variation handler
            var variationForm = document.querySelector('form.variations_form');
            
            // For grouped products, check if any quantity is selected
            if (express_pay_ajaxUCObj['is_grouped_product']) {
                var hasSelectedProduct = false;
                jQuery('input[name^="quantity["]').each(function() {
                    if (parseInt(jQuery(this).val()) > 0) {
                        hasSelectedProduct = true;
                        return false; // break loop
                    }
                });
                
                if (hasSelectedProduct) {
                    // Show Express Pay section if products are selected.
                    jQuery('#wc-express-checkout-product').show();
                    initProductPageExpressCheckout();
                } else {
                    // Hide Express Pay until a product is selected.
                    jQuery('#wc-express-checkout-product').hide();
                }
            } else if (!variationForm) {
                // Not a variable product and not grouped, initialize Express Pay normally.
                initProductPageExpressCheckout();
            }
        }

    });

})(jQuery);
