(function($) {
    'use strict';

    var firstLoadFlag = false;
    var isEventTriggered = false;
    var add_payment_method_token;
    var disableLoader = false;

    // Flex microform variables for saved card CVV.
    var flexInstances = {};
    var flexFieldsValid = {};
    
    jQuery(document).on('wc_checkout_form_error', function() {
        window.flexSubmissionInProgress = false;
        jQuery('form.checkout').off('submit', handleFormSubmission).on('submit', handleFormSubmission);
        // Show payment form back when there's a validation error after express pay click.
        hideExpressPayLoader();
        jQuery('.woocommerce-checkout-payment').show();
    });

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
    const errorMessageText = document.createTextNode(visa_acceptance_ajaxUCObj['form_load_error']);
    errorMessagePara.appendChild(errorMessageText);

    var paymentMethodRow = $('tr.payment-method');
    var defaultPaymentMethodRow = $('tr.default-payment-method');
    var paymentMethodRowText = paymentMethodRow.find('td.woocommerce-PaymentMethod.woocommerce-PaymentMethod--default.payment-method-default').text();
    var defaultPaymentMethodRowText = defaultPaymentMethodRow.find('td.woocommerce-PaymentMethod.woocommerce-PaymentMethod--default.payment-method-default').text();

    jQuery('.woocommerce-MyAccount-paymentMethods').on('click', '.woocommerce-PaymentMethod--actions .button.delete', function(e) {
        if (!confirm(visa_acceptance_ajaxUCObj['delete_card_text'])) {
            e.preventDefault();
            location.reload();
        }
        if (!navigator.onLine) {
            alert(visa_acceptance_ajaxUCObj['offline_text']);
            e.preventDefault();
        }
    });

    // Named function for form submission handling.
    async function handleFormSubmission(e) {
        if ($("input[name$='payment_method'][value$='" + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] +"']").is(':checked')) {
            var valueForTkn = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] + '-payment-token]:checked').val();
            
            // If no token is selected, allow normal processing (new card).
            if (!valueForTkn) {
                document.getElementById("errorMessage").value = "no";
                return true; // Allow form submission.
            }
            if (!visa_acceptance_ajaxUCObj['saved_card_cvv']) {
                document.getElementById("errorMessage").value = "no";
                return true;
            }
            
            // CVV is required - check if Flex is being used for this token.
            if (valueForTkn && flexInstances[valueForTkn]) {
                if (!flexFieldsValid[valueForTkn]) {
                    // Show error if Flex CVV field is not valid.
                    jQuery('#error-' + encodeURI(valueForTkn)).show();
                    document.getElementById("errorMessage").value = "yes";
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
                try {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    
                    var flexTokenResponse = await flexInstances[valueForTkn].createToken();
                    
                    if (flexTokenResponse && flexTokenResponse.token) {
                        // Add the flex token to a hidden field.
                        var flexTokenField = jQuery('input[name="flex_cvv_token"]');
                        if (flexTokenField.length === 0) {
                            jQuery('form.checkout').append('<input type="hidden" name="flex_cvv_token" value="' + flexTokenResponse.token + '">');
                        } else {
                            flexTokenField.val(flexTokenResponse.token);
                        }
                        document.getElementById("errorMessage").value = "no";

                        if (!window.flexSubmissionInProgress) {
                            window.flexSubmissionInProgress = true;
                            var form = jQuery('form.checkout');
                            form.off('submit', handleFormSubmission);
                            form.submit();
                        }
                        return false;
                    } else {
                        jQuery('#error-' + encodeURI(valueForTkn)).show();
                        document.getElementById("errorMessage").value = "yes";
                        return false;
                    }
                } catch (error) {
                    jQuery('#error-' + encodeURI(valueForTkn)).show();
                    document.getElementById("errorMessage").value = "yes";
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
            } else {
                // Flex is NOT being used - validate regular input field.
                let security_code = jQuery('input[name=csc-saved-card-' + encodeURI(valueForTkn) + ']').val();
                if (security_code && (security_code.length < 3 || security_code.length > 4)) {
                    jQuery('#error-' + encodeURI(valueForTkn)).show();
                    document.getElementById("errorMessage").value = "yes";
                    //To stop loading checkout page after entering invaid CVV.
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
                document.getElementById("errorMessage").value = "no";
            }
        }
    }

    // Attach the named function to the form submit event.
    jQuery('form.checkout').on("submit", handleFormSubmission);

    if (paymentMethodRow.length && defaultPaymentMethodRow.length && paymentMethodRowText != defaultPaymentMethodRowText) {
        defaultPaymentMethodRow.find('.woocommerce-PaymentMethod--actions .delete').hide();
    }

    function createButton(id, text) {
        return $('<button>', {
            type: 'button',
            id: id,
            class: 'btn btn-lg btn-block btn-primary',
            disabled: 'disabled',
            text: text,
            style: 'display: none;'
        });
    }

    function addLoader() {
        var loaderHtml = '<div id="loader" style="display:none;">' +
            '</div>';
        jQuery('body').append(loaderHtml);
    }

    function showExpressPayLoader() {
        addLoader();
        const blockUIElement = document.querySelector('.blockUI.blockOverlay');
        if (blockUIElement) {
            var computedStyle = getComputedStyle(blockUIElement);
            var display = computedStyle.getPropertyValue('display');
            var visibility = computedStyle.getPropertyValue('visibility')
            if (display == 'none' || visibility == 'hidden') {
                var loader = document.getElementById("loader");
                loader.style.display = "block";
                const topWindow = window.top;
                topWindow.document.body.appendChild(loader);
            }
        } else {

            var loader = document.getElementById("loader");
            loader.style.display = "block";
            const topWindow = window.top;
            topWindow.document.body.appendChild(loader);
        }
    }

    function hideExpressPayLoader() {
        document.getElementById("loader").style.display = "none";
    }

    function populateCheckoutFieldsFromTransientToken(transientToken, isExpressPay) {
        return new Promise((resolve) => {
            var expressOnly = !!isExpressPay;
            var isLoggedIn = (typeof visa_acceptance_ajaxUCObj !== 'undefined' && visa_acceptance_ajaxUCObj.is_user_logged_in);

            if (!expressOnly || isLoggedIn) {
                resolve();
                return;
            }

            if (!transientToken) {
                resolve();
                return;
            }

            jQuery.ajax({
                type: "POST",
                url: visa_acceptance_ajaxUCObj["ajax_url"],
                data: {
                    action: 'get_addresses_from_transient_token',
                    transientToken: transientToken
                },
                success: function (response) {
                    if (response && response.success && response.data) {
                        const billing = response.data.billing || {};
                        const shipping = response.data.shipping || {};

                        const billingMap = {
                            first_name: 'billing_first_name',
                            last_name: 'billing_last_name',
                            company: 'billing_company',
                            address_1: 'billing_address_1',
                            address_2: 'billing_address_2',
                            city: 'billing_city',
                            state: 'billing_state',
                            postcode: 'billing_postcode',
                            country: 'billing_country',
                            email: 'billing_email',
                            phone: 'billing_phone'
                        };

                        Object.keys(billingMap).forEach(function (key) {
                            if (billing[key] !== undefined && billing[key] !== null) {
                                const fieldName = billingMap[key];
                                const $field = jQuery('[name="' + fieldName + '"]');
                                if ($field.length) {
                                    $field.val(billing[key]).trigger('change');
                                }
                            }
                        });

                        const shipDiffCheckbox = jQuery('#ship-to-different-address-checkbox');
                        const shouldSetShipping = shipDiffCheckbox.length ? shipDiffCheckbox.is(':checked') : true;

                        if (shouldSetShipping) {
                            const shippingMap = {
                                first_name: 'shipping_first_name',
                                last_name: 'shipping_last_name',
                                company: 'shipping_company',
                                address_1: 'shipping_address_1',
                                address_2: 'shipping_address_2',
                                city: 'shipping_city',
                                state: 'shipping_state',
                                postcode: 'shipping_postcode',
                                country: 'shipping_country',
                                phone: 'shipping_phone'
                            };

                            Object.keys(shippingMap).forEach(function (key) {
                                if (shipping[key] !== undefined && shipping[key] !== null) {
                                    const fieldName = shippingMap[key];
                                    const $field = jQuery('[name="' + fieldName + '"]');
                                    if ($field.length) {
                                        $field.val(shipping[key]).trigger('change');
                                    }
                                }
                            });
                        }

                        jQuery(document.body).trigger('update_checkout');
                    }
                    resolve();
                },
                error: function () {
                    resolve();
                }
            });
        });
    }

    // Initialize Flex microform for saved card CVV validation.
    function initFlexForToken(tokenId) {

        // Check if Flex is available and we have required settings.
        var flexCaptureContext = document.getElementById("flex_cvv_token_data").value;
        if (typeof Flex !== 'undefined' && tokenId && visa_acceptance_ajaxUCObj['saved_card_cvv'] && flexCaptureContext) {
            
            
            if (flexCaptureContext && typeof flexCaptureContext === 'string') {
                var jwtParts = flexCaptureContext.split('.');
                if (jwtParts.length === 3) {
                    
                    try {
                        var flex = new Flex(flexCaptureContext);
                        
                        var microform = flex.microform('card', { 
                            styles: {
                                'input': {
                                    'font-size': '14px',
                                    'font-family': 'inherit',
                                    'color': '#333',
                                    'padding': '8px'
                                }
                            },
                        });
                        
                        var cardType = '';
                        if (visa_acceptance_ajaxUCObj['token_type'] && tokenId) {
                            cardType = visa_acceptance_ajaxUCObj['token_type'][tokenId] || '';
                        }
                        var max_length = ('AMEX' == cardType) ? 4 : 3;
                        var securityCode = microform.createField('securityCode', { 
                            placeholder: '•••',
                            maxLength: max_length
                        });                
                        
                        // Wait for DOM to be ready and load CVV field.
                        setTimeout(() => {
                            var containerElement = document.getElementById('flex-cvv-' + encodeURI(tokenId));
                            
                            if (!containerElement) {
                                return;
                            }
                            
                            try {
                                securityCode.load(containerElement);
                                flexInstances[tokenId] = {
                                    microform: microform,
                                    securityCode: securityCode,
                                    tokenId: tokenId,

                                    createToken: function() {
                                        return new Promise((resolve, reject) => {
                                            try {
                                                const options = {};
                                                
                                                microform.createToken(options, (err, flexjwtToken) => {
                                                    if (err) {
                                                        console.error('Microform createToken error:', err);
                                                        reject(err);
                                                    } else {
                                                        resolve({ token: flexjwtToken });
                                                    }
                                                });
                                            } catch (error) {
                                                console.error('Error in microform createToken:', error);
                                                reject(error);
                                            }
                                        });
                                    }
                                };
                                
                                flexFieldsValid[tokenId] = false;
                                
                                // Handle CVV field events.
                                securityCode.on('change', function(data) {
                                    flexFieldsValid[tokenId] = data.valid;
                                    if (data.valid) {
                                        jQuery('#error-' + encodeURI(tokenId)).hide();
                                    }
                                });
                                
                                // Hide the regular input field.
                                jQuery('input[name="csc-saved-card-' + encodeURI(tokenId) + '"]').hide();
                                
                                // Show the parent container and Flex container only on success.
                                jQuery('#token-' + encodeURI(tokenId)).show();
                                jQuery('#flex-cvv-' + encodeURI(tokenId)).show();
                                
                            } catch (fieldError) {
                                jQuery('#token-' + encodeURI(tokenId)).hide();
                            }
                        }, 100);
                        
                    } catch (error) {
                        jQuery('#token-' + encodeURI(tokenId)).hide();
                    }
                } else {
                    jQuery('#token-' + encodeURI(tokenId)).hide();
                }
            } else {
                jQuery('#token-' + encodeURI(tokenId)).hide();
            }
        } else {
            if (tokenId) {
                jQuery('#token-' + encodeURI(tokenId)).hide();
            }
        }
    }

    function initExpressCheckout() {

        var transientToken = document.getElementById("transientToken");
        var captureContext = document.getElementById("ep_jwt_updated") ? (document.getElementById("ep_jwt_updated")?.value || null) : (document.getElementById("ep_jwt")?.value || null);

        var showArgs = {
            containers: {
                paymentSelection: "#expressPaymentListContainer"
            }
        };

        if (typeof Accept !== 'undefined' && captureContext) {
            jQuery('#wc-error-failure').hide();

            Accept(captureContext)
                .then(function(accept) {
                    return accept.unifiedPayments();
                })
                .then(function(up) {
                    return up.show(showArgs);
                })
                .then(function(tt) {
                    
                    // Force Visa Unified Checkout as active method after checkout updates.
                    const paymentMethodRadioId = "input[name='payment_method'][value='" + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] + "']";
                    if( ! jQuery(paymentMethodRadioId).is(':checked') ) {
                        jQuery(paymentMethodRadioId).prop('checked', true).trigger('click');
                    }
                    const gatewayRadioId = '#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method';
                    jQuery(gatewayRadioId).prop('checked', true).trigger('click');
                                        
                    // hide default payment method box.
                    jQuery('.woocommerce-checkout-payment').hide();

                    // show loader while processing.
                    showExpressPayLoader();

                    transientToken.value = tt;
                    populateCheckoutFieldsFromTransientToken(tt, true).then(function () {
                        if (jQuery('form#order_review').length > 0) {
                            jQuery('form#order_review').submit();
                        } 
                        else {
                            setTimeout(() => {
                                jQuery('form.checkout').submit();
                            }, 200);
                            hideExpressPayLoader();
                        }
                    });
                })
            .catch(function(error) {
                jQuery('#wc-error-failure').show();
                jQuery('#expressPaymentListContainer').hide();
                // Show payment form back if express pay fails.
                hideExpressPayLoader();
                jQuery('.woocommerce-checkout-payment').show();
            });
        } else if (!captureContext) {
            jQuery('#express_payment_form_load_error').show();
        } else {
            jQuery('#wc-error-failure').show();
        }

        if( jQuery("#wc-express-checkout-section").is(':hidden') ) {
            jQuery('.checkout #wc-error-failure #wc-failure-error').hide();
        }
    }
    // Listen for checkout validation errors and show payment form back.
    jQuery(document.body).on('checkout_error', function() {
        hideExpressPayLoader();
        jQuery('.woocommerce-checkout-payment').show();
    });
    // Run on checkout load.
    if(visa_acceptance_ajaxUCObj['enabled_payment_methods'].length !== 0){
        jQuery(document.body).on('updated_checkout', initExpressCheckout);
    }
    // Run Express Checkout initialization only on Pay for Order page.
    if ((window.location.href.indexOf('order-pay=') !== -1 || window.location.pathname.indexOf('/order-pay/') !== -1) && visa_acceptance_ajaxUCObj['enabled_payment_methods'].length !== 0 ) {
        initExpressCheckout();
    }

    function handlePaymentSelection(event) {
        // Remove the tag when calling the handle function.
        jQuery('#buttonPaymentListContainer').remove();
        var buttonPaymentListContainer = $('<div>', {
            id: 'buttonPaymentListContainer'
        });
        var checkoutEmbeddedButton = createButton('checkoutEmbedded', 'Loading...');
        var checkoutSidebarButton = createButton('checkoutSidebar', 'Loading...');

        buttonPaymentListContainer.append(checkoutEmbeddedButton, checkoutSidebarButton);

        async function deriveAESKeyFromString(stringVal) {
            const encoder = new TextEncoder();
            const valIdData = encoder.encode(stringVal);
        
            // Hash the valId using SHA-256 and take the initial 32 bytes for AES-256.
            const hashBuffer = await crypto.subtle.digest('SHA-256', valIdData);
            return new Uint8Array(hashBuffer).slice(0, 32);
        }
        
        async function encryptData(data, stringVal) {
            const extId = crypto.getRandomValues(new Uint8Array(12));//iv:extId
            const encoder = new TextEncoder();
            const encodedData = encoder.encode(data);
        
            try {
                const valId = await deriveAESKeyFromString(stringVal);//key:valId
                const cryptoKey = await crypto.subtle.importKey(
                    'raw',
                    valId,
                    { name: 'AES-GCM' },
                    false,
                    [visa_acceptance_ajaxUCObj['encrypt_const']]
                );
        
                const encryptedBuffer = await crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: extId },
                    cryptoKey,
                    encodedData
                );
        
                const encryptedArray = new Uint8Array(encryptedBuffer);
                const refIdLength = 16;
                const ciphertext = encryptedArray.slice(0, encryptedArray.length - refIdLength);
                const refId = encryptedArray.slice(encryptedArray.length - refIdLength);//tag:refId
        
                // Return base64 encoded values.
                return {
                    encrypted: btoa(String.fromCharCode(...ciphertext)),
                    extId: btoa(String.fromCharCode(...extId)),
                    refId: btoa(String.fromCharCode(...refId))
                };
            } catch (error) {
                return null;
            }
        }

        var tokencnt = visa_acceptance_ajaxUCObj['token_cnt'];
        if ( visa_acceptance_ajaxUCObj['tokenization'] === 'no' ) {
            // Force "use new card" option to be selected by default.
            jQuery('#wc-credit-card-use-new-payment-method-div').show();
            if (jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').length > 0) {
                jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').prop('checked', true);
            }
            jQuery('#buttonPaymentListContainer').show();
            jQuery('#place_order').hide();
        } else {
            if (tokencnt != "0") {
                if (jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').is(':checked')) {
                    jQuery('#buttonPaymentListContainer').show();
                    jQuery('#place_order').hide();
                } else {
                    jQuery('#buttonPaymentListContainer').hide();
                    jQuery('.wc-payment-gateway-payment-form-manage-payment-methods').show();
                    jQuery('#wc-credit-card-use-new-payment-method-div').show();
                    jQuery('#place_order').show();
                    jQuery('#wc-unified-checkout-normal-save-token-div').hide();
                };
            }
        }
        tknval = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]:checked').val();
        if (tknval && visa_acceptance_ajaxUCObj['saved_card_cvv']) {
            setTimeout(() => {
                initFlexForToken(tknval);
            }, 100);
        }
        jQuery(`input[name="csc-saved-card-${encodeURI(tknval)}"]`).on('input', async function () {
            let rawValue = jQuery(this).val().replace(/\D/g, '');
            let valId = visa_acceptance_ajaxUCObj['token_key'];
            jQuery(this).val(rawValue);
            
            if ((rawValue.length === 3 || rawValue.length === 4)) {
                const encryptedData = await encryptData(rawValue, valId);
                  
                if (encryptedData) {
        
                    // Ensure hidden fields for extId, and refId are created if needed.
                    if ($(`input[name="extId-${encodeURI(tknval)}"]`).length === 0) {
                        jQuery(this).after(`
                            <input type="hidden" name="csc-saved-card-${encodeURI(tknval)}" value="${encryptedData.encrypted}">
                            <input type="hidden" name="extId-${encodeURI(tknval)}" value="${encryptedData.extId}">
                            <input type="hidden" name="refId-${encodeURI(tknval)}" value="${encryptedData.refId}">
                        `);
                    } else {
                        $(`input[name="csc-saved-card-${encodeURI(tknval)}"]`).val(encryptedData.encrypted);
                        $(`input[name="extId-${encodeURI(tknval)}"]`).val(encryptedData.extId);
                        $(`input[name="refId-${encodeURI(tknval)}"]`).val(encryptedData.refId);
                    }
                    jQuery(this).val(rawValue);
                }
            }
        });
        //Fetch token selected
        var tknval = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]:checked').val();
        jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]').click(function() {
            jQuery('#wc-unified-checkout-tokenize-payment-method').prop('checked', false);
            jQuery('.cvv-div').hide();
            jQuery('.wc-unified-checkout-saved-card #wc-unified-checkout-saved-card-cvn').val('');
            
            //Fetch token selected.
            var tknval = jQuery(this).val();
            
            jQuery('[id^="flex-cvv-"]').hide();
            jQuery('input[name^="csc-saved-card-"]').hide();
            jQuery('[id^="token-"]').hide();
            
            // Initialize Flex for the newly selected token (it will show the div if successful).
            if (tknval && visa_acceptance_ajaxUCObj['saved_card_cvv']) {
                setTimeout(() => {
                    initFlexForToken(tknval);
                }, 100);
            }
            jQuery(`input[name="csc-saved-card-${encodeURI(tknval)}"]`).on('input', async function () {
            let rawValue = jQuery(this).val().replace(/\D/g, '');
            let valId = visa_acceptance_ajaxUCObj['token_key'];
            jQuery(this).val(rawValue);
            
            if ((rawValue.length === 3 || rawValue.length === 4)) {
                const encryptedData = await encryptData(rawValue, valId);
                  
                if (encryptedData) {
        
                    // Ensure hidden fields for extId, and refId are created if needed.
                    if ($(`input[name="extId-${encodeURI(tknval)}"]`).length === 0) {
                        jQuery(this).after(`
                            <input type="hidden" name="csc-saved-card-${encodeURI(tknval)}" value="${encryptedData.encrypted}">
                            <input type="hidden" name="extId-${encodeURI(tknval)}" value="${encryptedData.extId}">
                            <input type="hidden" name="refId-${encodeURI(tknval)}" value="${encryptedData.refId}">
                        `);
                    } else {
                        $(`input[name="csc-saved-card-${encodeURI(tknval)}"]`).val(encryptedData.encrypted);
                        $(`input[name="extId-${encodeURI(tknval)}"]`).val(encryptedData.extId);
                        $(`input[name="refId-${encodeURI(tknval)}"]`).val(encryptedData.refId);
                    }
                    jQuery(this).val(rawValue);
                }
            }
        });
            jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]').not(':checked').each(function() {
                var tknval = jQuery(this).val();
                jQuery("#token-" + encodeURI(tknval)).hide();
            });
            if (jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').is(':checked')) {
                //Add at Checkout page below Use a new card.
                if(!jQuery('#buttonPaymentListContainer').length){
                    $('#wc-credit-card-use-new-payment-method-div').append(buttonPaymentListContainer);
                }
                var transientToken = document.getElementById("transientToken");
                var captureContext = document.getElementById("jwt_updated")? document.getElementById("jwt_updated").value : (document.getElementById("jwt") ? document.getElementById("jwt").value : null);
                var showArgs = {
                    containers: {
                        //checkout with card Payment box.
                        paymentSelection: "#buttonPaymentListContainer"
                    }
                };

                if (typeof Accept !== 'undefined') {
                    jQuery('#wc-error-failure').hide();
                    Accept(captureContext)
                        .then(function(accept) {
                            return accept.unifiedPayments();
                        })
                        .then(function(up) {
                            return up.show(showArgs);
                        })
                        .then(function(tt) {
                            transientToken.value = tt;
                            jQuery("body").css({"overflow": "auto"});
                            populateCheckoutFieldsFromTransientToken(tt, false).then(function () {
                                if (jQuery('form#order_review').length > 0) {
                                    jQuery('form#order_review').submit();
                                } else if (jQuery('form#add_payment_method').length > 0) {
                                    add_payment_method_token = tt;
                                    jQuery("form#add_payment_method").submit();
                                } else {
                                    $('form.checkout').submit();
                                }
                            });

                        }).catch(function(error) {
                            jQuery('#wc-error-failure').show();
                            jQuery('#buttonPaymentListContainer').hide();
                        });
                } else {
                    buttonPaymentListContainer.append(errorMessagePara);
                }

                jQuery('#buttonPaymentListContainer').show();
                jQuery('#place_order').hide();
                jQuery('#wc-unified-checkout-normal-save-token-div').show();
            } else {
                jQuery('#place_order').show();
                jQuery('#buttonPaymentListContainer').hide();
                jQuery('#wc-unified-checkout-normal-save-token-div').hide();
            };
        });
       
        if ($("input[name$='payment_method'][value$='" + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] +"']").is(':checked')) {
            if (jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').length == 0 ||  jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').is(':checked')) {
                $("#place_order").hide();                  
                if (event.type == 'init_add_payment_method') {
                    // At My account -> Add Payment page.
                    if(!jQuery('#buttonPaymentListContainer').length){
                        jQuery('.woocommerce-PaymentBox.woocommerce-PaymentBox--' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] + '.payment_box.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).append(buttonPaymentListContainer);
                    }
                }
                    //checkout page - no saved payment method.
                var save_token_checkbox_div = $('#wc-unified-checkout-normal-save-token-div');
                if(save_token_checkbox_div.length){
                    //save card enabled, insert before checkbox div
                    buttonPaymentListContainer.insertBefore(save_token_checkbox_div);
                }else{
                    //If save card option not enabled, append directly to parent div
                    if(!jQuery('#buttonPaymentListContainer').length){
                        $('.payment_box.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).append(buttonPaymentListContainer);
                    }
                }
                var transientToken = document.getElementById("transientToken");
                var captureContext = document.getElementById("jwt_updated")? document.getElementById("jwt_updated").value : (document.getElementById("jwt") ? document.getElementById("jwt").value : null);
                var showArgs = {
                    containers: {
                        paymentSelection: "#buttonPaymentListContainer"
                    }
                };
                if (typeof Accept !== 'undefined') {
                    jQuery('#wc-error-failure').hide();
                    Accept(captureContext)
                        .then(function(accept) {
                            return accept.unifiedPayments();
                        })
                        .then(function(up) {
                            return up.show(showArgs);
                        })
                        .then(function(tt) {
                            transientToken.value = tt;
                            // Adding to make the overflow auto.
                            jQuery("body").css({"overflow": "auto"});
                            populateCheckoutFieldsFromTransientToken(tt, false).then(function () {
                                if (jQuery('form#order_review').length > 0) {
                                    jQuery('form#order_review').submit();
                                } else if (jQuery('form#add_payment_method').length > 0) {
                                    add_payment_method_token = tt;
                                    jQuery("form#add_payment_method").submit();
                                } else {
                                    $('form.checkout').submit();
                                }
                            });

                        }).catch(function(error) {
                            jQuery('#wc-error-failure').show();
                            jQuery('#buttonPaymentListContainer').hide();
                        });
                } else {
                    buttonPaymentListContainer.append(errorMessagePara);
                }

            } else {
                buttonPaymentListContainer.append(errorMessagePara);
            }

        } else {
            $("#place_order").show();
            $('.wc_payment_method.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).find('#buttonPaymentListContainer').empty();
        } 
        if (event.type == 'init_add_payment_method') {
            firstLoadFlag = true;

            function checkAndTrigger() {
                if ($("input[name$='payment_method'][value$='"+ visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] +"']").is(':checked') && !isEventTriggered) {
                    $(document.body).trigger('init_add_payment_method');
                    isEventTriggered = true; // Set the flag to true once the event is triggered.
                } else if (!$("input[name$='payment_method'][value$='"+ visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']+"']").is(':checked')) {
                    isEventTriggered = false; // Reset the flag if the checkbox is unchecked.
                }
            }

            function checkAndRemove() {
                if (!$("input[name$='payment_method'][value$='"+ visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']+"']").is(':checked')) {
                    $("#place_order").show();
                    $('li.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).find('#buttonPaymentListContainer').empty();
                }
            }

            setInterval(checkAndTrigger, 1000);
            setInterval(checkAndRemove, 1000);
        }
    }

    $('body').on('payment_method_selected', handlePaymentSelection);
    // Whenever there is an error it will call the function.
    $('body').on('checkout_error', handlePaymentSelection);
    $('body').on('updated_checkout', handlePaymentSelection);
    $('body').on('init_add_payment_method', function(event) {
        handlePaymentSelection(event);
    });


    //Code for Special case of pay-order page
    //This extracts order_id to pass it to custom getOrderIDPayPage
    var hrefUrl = window.location.href;
    var url = new URL(hrefUrl);
    var orderPayValue = url.searchParams.get("order-pay");
    var startIndex = hrefUrl.indexOf("/order-pay/") + "/order-pay/".length;
    var endIndex = hrefUrl.indexOf("/", startIndex);
    var extractedNumber;
    if (startIndex !== -1 && endIndex !== -1 && hrefUrl.indexOf("/order-pay/") !== -1) {
        extractedNumber = hrefUrl.substring(startIndex, endIndex);
    } else if (orderPayValue !== null) {
        extractedNumber = orderPayValue;
    }

    var repeatFlag = false;

    jQuery('form#order_review').on('submit', async function(e) {
        if ($("input[name$='payment_method'][value$='" + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] +"']").is(':checked')) {
            var valTkn = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]:checked').val();
            var trans_token = document.getElementById("transientToken").value;
            var solution_id = '';
            if (trans_token) {
                const parts = trans_token.split('.');
                const decodedPayload = atob(parts[1]);

                const payload = JSON.parse(decodedPayload);

                if (payload?.content?.processingInformation?.paymentSolution?.value !== undefined) {
                    solution_id = payload.content.processingInformation.paymentSolution.value;
                }


            }
            
            // Check if using saved card with CVV required.
            if (valTkn && visa_acceptance_ajaxUCObj['saved_card_cvv']) {
                // Check if Flex is being used for this token
                if (flexInstances[valTkn]) {
                    if (!flexFieldsValid[valTkn]) {
                        jQuery('#error-' + encodeURI(valTkn)).show();
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }

                    try {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        
                        var flexTokenResponse = await flexInstances[valTkn].createToken();
                        
                        if (flexTokenResponse && flexTokenResponse.token) {
                            // Add the flex token to a hidden field
                            var flexTokenField = jQuery('input[name="flex_cvv_token"]');
                            if (flexTokenField.length === 0) {
                                jQuery('form#order_review').append('<input type="hidden" name="flex_cvv_token" value="' + flexTokenResponse.token + '">');
                            } else {
                                flexTokenField.val(flexTokenResponse.token);
                            }
                            if (!window.orderReviewFlexSubmission) {
                                window.orderReviewFlexSubmission = true;
                                // Check if payer authentication flow should handle submission
                                var isPayerAuthFlow = typeof visa_acceptance_uc_payer_auth_param !== 'undefined' 
                                    && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' 
                                    && solution_id != '001' 
                                    && solution_id != '027';
                                if (!isPayerAuthFlow) {
                                    var form = jQuery('form#order_review');
                                    form.off('submit');
                                    form.submit();
                                    return false;
                                }
                            }
                        } else {
                            jQuery('#error-' + encodeURI(valTkn)).show();
                            return false;
                        }
                    } catch (error) {
                        jQuery('#error-' + encodeURI(valTkn)).show();
                        return false;
                    }
                } else {
                    var security_code = jQuery('input[name=csc-saved-card-' + encodeURI(valTkn) + ']').val();
                    if (( !(typeof security_code == 'undefined') && (security_code == '' || security_code.length > 4 || security_code.length < 3))) {
                        jQuery('#error-' + encodeURI(valTkn)).show();
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }
                }
            }

            if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && solution_id != '001' && solution_id != '027') {
                e.preventDefault();
            }
            if (typeof payer_auth_param == 'undefined') {
                if (!repeatFlag) {
                    repeatFlag = true;

                    if ((jQuery('#payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).is(':checked'))) {
                        if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && solution_id != '001' && solution_id != '027') {
                            addLoader();
                            showLoader();
                            getOrderIDPayPage(extractedNumber);
                        } else {
                            return true;
                        }

                    }
                }
            }
        }
    });

 })(jQuery);
