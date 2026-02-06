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
                        quantity: $('input.qty').val() || 1,
                        product_id: variation.variation_id,
                        timestamp: new Date().getTime(),
                        force_refresh: 1
                    },
                    success: function(response) {
                        console.log('AJAX Response:', response);
                        if (response.success) {
                            // Handle case where capture_context_ep_jwt might be null
                            if (response.capture_context_ep_jwt) {
                                captureContext = response.capture_context_ep_jwt;
                            } else if (!captureContext) {
                                // No capture context available at all
                                console.error('No capture context available');
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
                            console.error('Failed to update capture context for variation', response);
                            hideExpressPayLoader();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error updating variation:', error);
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
                            product_id: express_pay_ajaxUCObj['product_id'],
                            grouped_items: groupedProductQuantities,
                            force_refresh: 1,
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
                                console.error('Failed to update capture context for grouped products', response);
                                hideExpressPayLoader();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error updating grouped product quantities:', error);
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
                        quantity: updatedQuantity,
                        product_id: productIdToUse,
                        force_refresh: selectedVariationId ? 1 : 0,
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
                            console.error('Failed to update capture context', response);
                            hideExpressPayLoader();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error updating quantity:', error);
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

        if (typeof Accept !== 'undefined') {
            $('#wc-error-failure').hide();

            Accept(captureContext)
            .then(function(accept) {
                return accept.unifiedPayments();
            })
            .then(function(up) {
                return up.show(showArgs)
            })
            .then(function(tt) {
                if (transientToken) {
                    transientToken.value = tt;
                }
                var container = document.getElementById('expressPaymentListContainer_product');
                if (container) {
                    try {
                        // show loader while processing.
                        showExpressPayLoader();
                        // Ajax to create order.
                        var orderData = { 
                            action: 'express_pay_for_order', 
                            product_id: express_pay_ajaxUCObj['product_id'],
                            billing_details: express_pay_ajaxUCObj['billing_details'],
                            shipping_details: express_pay_ajaxUCObj['shipping_details'],
                            payment_method: express_pay_ajaxUCObj['visa_acceptance_solutions_uc_id'],
                            payer_auth_enabled: express_pay_ajaxUCObj['payer_auth_enabled'],
                            transientToken: tt,
                            quantity: currentQuantity
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
                                // On successfull payment redirect to order confirmation page.
                                if (response.success && response.data.redirect_url) {
                                    window.location.href = response.data.redirect_url; // Go to Order confirmation page.
                                    hideExpressPayLoader();
                                } else {
                                    window.location.reload();
                                    $('#expressPaymentListContainer_product').hide();
                                    hideExpressPayLoader();
                                };
                            }
                        });
                    } catch (error) {
                        $('#wc-error-failure').show();
                        hideExpressPayLoader();
                        reloadAfterError();
                        $('#expressPaymentListContainer_product').hide();
                    }
                }
            })
            .catch(function(error) {
                $('#wc-error-failure').show();
                $('#expressPaymentListContainer_product').hide();
                reloadAfterError();
            });
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
