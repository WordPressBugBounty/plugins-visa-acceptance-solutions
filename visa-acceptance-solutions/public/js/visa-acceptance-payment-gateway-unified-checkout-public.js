(function($) {
    'use strict';


    var firstLoadFlag = false;
    var isEventTriggered = false;
    var add_payment_method_token;
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

    jQuery('form.checkout').on("submit", function(e) {
        if ($("input[name$='payment_method'][value$='" + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id'] +"']").is(':checked')) {
            var valueForTkn = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] + '-payment-token]:checked').val();
            let security_code = jQuery('input[name=csc-saved-card-' + encodeURI(valueForTkn) + ']').val();
            if ((!(typeof security_code == 'undefined') && (security_code == '' ||  (security_code.length < 3) || security_code.length > 4))) {
                jQuery('#error-' + encodeURI(valueForTkn)).show();
                document.getElementById("errorMessage").value = "yes";
                //To stop loading checkout page after entering invaid CVV.
                e.preventDefault();
                e.stopImmediatePropagation();
            } else {
                document.getElementById("errorMessage").value = "no";
            }
        }
    });

    if (paymentMethodRow.length && defaultPaymentMethodRow.length && paymentMethodRowText != defaultPaymentMethodRowText) {
        // Both rows exist, hide the 'Delete' button in 'payment-method' row
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

    function handlePaymentSelection(event) {
        // Remoeve the tag when calling the handle function.
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
                console.error('Encryption Error:', error);
                return null;
            }
        }

        var tokencnt = visa_acceptance_ajaxUCObj['token_cnt'];
        if (tokencnt != "0") {
            if (jQuery('#wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-use-new-payment-method').is(':checked')) {
                jQuery('#buttonPaymentListContainer').show();
                jQuery('#place_order').hide();
            } else {
                jQuery('#buttonPaymentListContainer').hide();
                jQuery('.wc-payment-gateway-payment-form-manage-payment-methods').show();
                jQuery('#wc-credit-card-use-new-payment-method-div').show();
                jQuery('#place_order').show();
                jQuery('#wc-unified-checkout-save-token-div').hide();
            };
        }
        tknval = jQuery('input[name=wc-' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id_hyphen'] +'-payment-token]:checked').val();
        jQuery("#token-" + encodeURI(tknval)).show();
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
            //Fetch token selected
            var tknval = jQuery(this).val();
            jQuery("#token-" + encodeURI(tknval)).show();
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
                var captureContext = document.getElementById("jwt_updated")? document.getElementById("jwt_updated").value:document.getElementById("jwt").value;
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
                            // Adding to make the overflow auto.
                            jQuery("body").css({"overflow": "auto"});
                            if (jQuery('form#order_review').length > 0) {
                                jQuery('form#order_review').submit();
                            } else if (jQuery('form#add_payment_method').length > 0) {
                                add_payment_method_token = tt;
                                jQuery("form#add_payment_method").submit();
                            } else {
                                $('form.checkout').submit();
                            }

                        }).catch(function(error) {
                            jQuery('#wc-error-failure').show();
                            jQuery('#buttonPaymentListContainer').hide();
                        });
                } else {
                    buttonPaymentListContainer.append(errorMessagePara);
                }

                jQuery('#buttonPaymentListContainer').show();
                jQuery('#place_order').hide();
                jQuery('#wc-unified-checkout-save-token-div').show();
            } else {
                jQuery('#place_order').show();
                jQuery('#buttonPaymentListContainer').hide();
                jQuery('#wc-unified-checkout-save-token-div').hide();
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
                var save_toke_checkbox_div = $('#wc-unified-checkout-save-token-div');
                if(save_toke_checkbox_div.length){
                    //save card enabled, insert before checkbox div
                    buttonPaymentListContainer.insertBefore(save_toke_checkbox_div);
                }else{
                    //If save card option not enabled, append directly to parent div
                    if(!jQuery('#buttonPaymentListContainer').length){
                        $('.payment_box.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).append(buttonPaymentListContainer);
                    }
                }
                var transientToken = document.getElementById("transientToken");
                var captureContext = document.getElementById("jwt_updated")? document.getElementById("jwt_updated").value:document.getElementById("jwt").value;
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
                            if (jQuery('form#order_review').length > 0) {
                                jQuery('form#order_review').submit();
                            } else if (jQuery('form#add_payment_method').length > 0) {
                                add_payment_method_token = tt;
                                jQuery("form#add_payment_method").submit();
                            } else {
                                $('form.checkout').submit();
                            }

                        }).catch(function(error) {
                            jQuery('#wc-error-failure').show();
                            jQuery('#buttonPaymentListContainer').hide();
                        });
                } else {
                    buttonPaymentListContainer.append(errorMessagePara);
                }

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
                    isEventTriggered = true; // Set the flag to true once the event is triggered
                } else if (!$("input[name$='payment_method'][value$='"+ visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']+"']").is(':checked')) {
                    isEventTriggered = false; // Reset the flag if the checkbox is unchecked
                }
            }

            function checkAndRemove() {
                if (!$("input[name$='payment_method'][value$='"+ visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']+"']").is(':checked')) {
                    $("#place_order").show();
                    $('li.payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).find('#buttonPaymentListContainer').empty();
                }
            }

            // Check the condition every 1000 milliseconds (1 second)
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

    jQuery('form#order_review').on('submit', function(e) {
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
            var security_code = jQuery('input[name=csc-saved-card-' + encodeURI(valTkn) + ']').val();
            if (( !(typeof security_code == 'undefined') && (security_code == '' || security_code.length > 4 || security_code.length < 3))) {
                jQuery('#error-' + encodeURI(valTkn)).show();
                //disableLoader = true;
                //To stop loading checkout page after entering invaid CVV.
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }

            if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && solution_id != '012' && solution_id != '027') {
                e.preventDefault();
            }
            if (typeof payer_auth_param == 'undefined') {
                if (!repeatFlag) {
                    repeatFlag = true;

                    if ((jQuery('#payment_method_' + visa_acceptance_ajaxUCObj['visa_acceptance_solutions_uc_id']).is(':checked'))) {
                        //Check extra for Payer auth but not CC


                        if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && solution_id != '012' && solution_id != '027') {
                            // Adds Inner HTML code for Loader
                            addLoader();

                            // Displays Loader
                            showLoader();

                            //Calling getOrderID to get Order ID
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
