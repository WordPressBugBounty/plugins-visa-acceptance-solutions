var {
    registerPaymentMethod
} = wc.wcBlocksRegistry;
const ucBlocksSettings = wc.wcSettings.getSetting('visa_acceptance_solutions_unified_checkout_data');
var {
    decodeEntities
} = wp.htmlEntities;

const { useSelect } = window.wp.data;
const { CHECKOUT_STORE_KEY } = window.wc.wcBlocksData;
const { ValidatedTextInput } = window.wc.blocksCheckout;
var uc_token, req_data;
function reloadAfterError() {
    setTimeout(() => {
        window.location.reload();
    }, 5000);
}
const ucblockssavedcomponent = (props) => {
    jQuery('.wc-block-components-checkout-place-order-button').show();
    const {
        token,
        eventRegistration,
        emitResponse
    } = props;
    const {
        onPaymentSetup,
        onCheckoutFail
    } = eventRegistration;

    const [cvvLength] = React.useState(4);
    const cvvRef = React.useRef('');

    React.useEffect(() => {
        if (!cvvRef.current) return;
        cvvRef.current = '';
    }, [token])

    React.useEffect(() => {

        async function deriveAESKeyFromString(tknString) {
            const encoder = new TextEncoder();
            const valIdData = encoder.encode(tknString);
        
            // Hash the valId using SHA-256 and use the full 32 bytes for AES-256.
            const hashBuffer = await crypto.subtle.digest('SHA-256', valIdData);
        
            return new Uint8Array(hashBuffer).slice(0, 32); // Extract first 32 bytes for AES-256.
        }
        
        async function encryptData(data, tknString) {
            const extId = crypto.getRandomValues(new Uint8Array(12));//iv:extId
            const encoder = new TextEncoder();
            const encodedData = encoder.encode(data);
        
            try {
                const valId = await deriveAESKeyFromString(tknString);//key:valId
        
                const cryptoKey = await crypto.subtle.importKey(
                    'raw',
                    valId,
                    { name: 'AES-GCM' },
                    false,
                    [ucBlocksSettings.encrypt_const]
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
        
                return {
                    encrypted: btoa(String.fromCharCode(...ciphertext)),
                    extId: btoa(String.fromCharCode(...extId)),
                    refId: btoa(String.fromCharCode(...refId)),
                };
            } catch (error) {
                console.error('Encryption Error:', error);
                return null;
            }
        }
        
        const unsubscribe = onPaymentSetup(async () => {
            if (token) {
                if (ucBlocksSettings.saved_card_cvv && cvvRef.current.length < 3) {
                    return {
                        type: "error",
                        validationErrors: {
                            "saved_card_cvv_field": {
                                message:  __("Please Enter Valid Security Code."),
                                hidden: false
                            }
                        }
                    };
                }

                const wc_credit_card_getorderid = 'order_token';
                const payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;
        
                // Get the valId from server.
                const tknString = ucBlocksSettings.token_key;
        
                // Encrypt CVV using the derived AES-256 key.
                const encryptedCVV = await encryptData(cvvRef.current, tknString);//keyString:tknString
        
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            token,
                            wc_credit_card_getorderid,
                            wc_cc_security_code_blocks: encryptedCVV.encrypted,
                            ext_Id: encryptedCVV.extId,
                            ref_Id: encryptedCVV.refId,
                            payer_auth_enabled,
                        },
                    },
                };
            }
        });

        const ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
            var errorResponse = processingResponse;
            if (errorResponse.processingResponse?.paymentDetails?.message) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: errorResponse.processingResponse.paymentDetails.message,
                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                };
            }

            reloadAfterError();
            // so we don't break the observers.
            return true;
        });
        jQuery('.wc-block-components-notice-banner.is-error').hide().text('');
        // Unsubscribes when this component is unmounted.
        return () => {
            unsubscribe();
            ucPaymentComponentfail();
        };
    }, [
        emitResponse.responseTypes.ERROR,
        emitResponse.responseTypes.SUCCESS,
        onPaymentSetup,
        onCheckoutFail,
        token
    ]);
    const [cvvValue, setCvvValue] = React.useState("");
    
    // Reset CVV when token changes
    React.useEffect(() => {
        setCvvValue("");
        cvvRef.current = "";
    }, [token]);
    var x = ucBlocksSettings.saved_card_cvv ? React.createElement("div", { className: "saved-card-container" },
        React.createElement(ValidatedTextInput, {
            id: "saved_card_cvv_field",
            errorId: "saved_card_cvv_field",
            className: "saved-card-cvv-field",
            label: "Card Security Code Ending in " + ucBlocksSettings.last_four[token],
            type: "password",
            autoComplete:'new-password',
            onChange: (value, e) => {
            const numericValue = value.replace(/\D/g, "").slice(0, cvvLength);
            setCvvValue(numericValue);
            cvvRef.current = numericValue;
            },
             value: cvvValue,
            customValidation: (inputObject) => {
            const isValid = inputObject.value.length >= 3 && inputObject.value.length <= 4;
            inputObject.setCustomValidity( isValid ? "" : ucBlocksSettings.cvv_error );
            return isValid;
            },
            "maxlength": cvvLength
        }),
        React.createElement('div', { className: "saved-card-alignment-fix" }),
    ) : null;

    const DDL_StepUp_iframe = React.createElement(
        'div', {},
        React.createElement(
            "iframe", {
            id: "cardinal_collection_iframe",
            name: "collectionIframe",
            height: "10",
            width: "10",
            style: {
                display: "none"
            },
        },
            null
        ),
        React.createElement(
            "form", {
            id: "cardinal_collection_form",
            method: "POST",
            target: "collectionIframe",
            action: "",
        },
            React.createElement("input", {
                id: "cardinal_collection_form_input",
                type: "hidden",
                name: "JWT",
                value: "",
            })
        ),
        React.createElement(
            "div", {
            id: "modal-container",
            style: {
                display: "none"
            },
        },
            React.createElement(
                "div", {
                id: "modal-content"
            },
                React.createElement("iframe", {
                    id: "step-up-iframe-id",
                    name: "step-up-iframe",
                    height: "400",
                    width: "400",
                }),
                React.createElement(
                    "form", {
                    id: "step-up-form",
                    target: "step-up-iframe",
                    method: "post",
                    action: "",
                },
                    React.createElement("input", {
                        type: "hidden",
                        id: "accessToken",
                        name: "JWT",
                        value: "",
                    }),
                    React.createElement("input", {
                        type: "hidden",
                        name: "MD",
                        id: "merchantData",
                        value: "",
                    })
                ),
            )
        )
    );
    return [x, DDL_StepUp_iframe];
};
const ucComponents = (props) => {
    jQuery('.wc-block-components-checkout-place-order-button').hide();

    const {
        onSubmit,
        eventRegistration,
        emitResponse,
        billing,
        components
    } = props;
    const {
        onPaymentSetup,
        onCheckoutFail
    } = eventRegistration;

    const { LoadingMask } = components;
    const isIdle = useSelect(CHECKOUT_STORE_KEY).isIdle();
    const orderId = useSelect(CHECKOUT_STORE_KEY).getOrderId();

    const { cartTotal } = billing || {};
    const [isLoadingCC, setLoadingCC] = React.useState(true);
    const [captureContext, setCaptureContext] = React.useState(null);

    React.useEffect(() => {
        setLoadingCC(true);
        //get the updated captureContext if any cart value is changed
        getCaptureContext(orderId).then(response => {
            response && setCaptureContext(response);
        }).catch((error) => {
            console.error(error);
            setCaptureContext("Invalid Capture context");
        }).finally(() => setLoadingCC(false));
    }, [cartTotal.value]);

     /**
     * 
     * @param {string} orderId - current orderid
     * @returns {Promise<string>}
     */
    const getCaptureContext = React.useCallback(async (orderId) => {
        return new Promise((resolve, reject) => {
            try {
            jQuery.ajax({
                type: "POST",
                url: ucBlocksSettings["ajax_url"],
                cache: false,
                async: true,
                data: {
                    action: "wc_call_uc_update_price_action",
                    order_id: orderId
                },
                success: function (data) {
                        if (!data) {
                            return reject('Invalid data');
                        }
                        if (!data.success) {
                            return reject("Server Error Requesting Capture Context");
                        }
                        if (data.success && data.capture_context) {
                            return resolve(data.capture_context);
                        }
                        if (data.success && !data.capture_context) {
                            return resolve(false);
                        }
                        return reject("Something went wrong");
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    return reject(errorThrown);
                },
            });
            } catch (error) {
                return reject(error);
            }
        });
    }, [cartTotal.value]);

    React.useEffect(() => {

        const ucPaymentComponent = onPaymentSetup(async () => {
            // Here we can do any processing we need, and then emit a response.
            // For example, we might validate a custom field, or perform an AJAX request, and then emit a response indicating it is valid or not.

            const blocks_token = uc_token;
            const payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;
            if (blocks_token) {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            blocks_token,
                            payer_auth_enabled,
                        },
                    },
                };
            }

            return {
                type: emitResponse.responseTypes.ERROR,
                message: __('There was an error'),
            };
        });



        //Can be Used Later.
        const ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
            if (jQuery('#embeddedPaymentContainer').find('iframe').length > 0) {
                jQuery('#embeddedPaymentContainer').children().remove();
            }

            var errorResponse = processingResponse;
            if (errorResponse.processingResponse?.paymentDetails?.message) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: errorResponse.processingResponse.paymentDetails.message,
                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                };
            }
            reloadAfterError();
            // // so we don't break the observers.
            return true;
        });
        jQuery('.wc-block-components-notice-banner.is-error'
).hide().text('');
        // Unsubscribes when this component is unmounted.
        return () => {
            ucPaymentComponent();
            ucPaymentComponentfail();
        };


    }, [
        emitResponse.responseTypes.ERROR,
        emitResponse.responseTypes.SUCCESS,
        onPaymentSetup,
        onCheckoutFail,
    ]);

    React.useEffect(() => {
        if ((!isIdle && ucBlocksSettings.isVersionSupported) || isLoadingCC) {
            return;
        }
        if (jQuery("#radio-control-wc-payment-method-options-" + ucBlocksSettings.visa_acceptance_solutions_uc_id).is(":checked")) {
            // if (test.current) return;
            jQuery('.wc-block-components-checkout-place-order-button').hide();
            var transientToken = document.getElementById("transientToken");
            var cc = document.getElementById("jwt").value;
            if (captureContext) {
                cc = captureContext;
            }
            var showArgs = {
                containers: {
                    paymentSelection: "#buttonPaymentListContainer",
                    paymentScreen: '#embeddedPaymentContainer',
                }
            };
            //clear in case of reinitialising or any error happens.
            jQuery('#buttonPaymentListContainer').empty();
            jQuery('#embeddedPaymentContainer').empty();

            if (typeof Accept !== 'undefined') {
                jQuery('.blocks-error-div-uc').hide();
                jQuery('.blocks-failure-error-div-uc').hide();

                Accept(cc)
                    .then(function (accept) {
                        return accept.unifiedPayments(false);
                    })
                    .then(function (up) {
                        jQuery('.wc-block-components-checkout-place-order-button').hide();
                        return up.show(showArgs);
                    })
                    .then(function (tt) {
                        transientToken.value = tt;
                        jQuery('#transientToken').val(tt);
                        uc_token = tt;
                        // For infinite page loading at checkout.
                        jQuery('#embeddedPaymentContainer').empty();
                        onSubmit();
                    })
                    .catch(function (error) {
                        jQuery('#buttonPaymentListContainer').empty();
                        jQuery('#embeddedPaymentContainer').empty();
                        jQuery('.blocks-failure-error-div-uc').show();

                    });
            } else {
                // Error div inside Visa Acceptance Solutions Unified Checkout.
                jQuery('.blocks-error-div-uc').show();
            }

        }
    }, [captureContext, isIdle, isLoadingCC])

    const loader = React.createElement(LoadingMask, {
        isLoading: true,
        screenReaderLabel: "Loading Capture Context",
        showSpinner: true
    });

    if (isLoadingCC) {
        return [loader];
    }

    const tra = React.createElement('div', null,
        React.createElement('input', {
            type: 'hidden',
            id: 'transientToken',
            name: 'transientToken'
        })
    );


    const DDL_StepUp_iframe = React.createElement(
        'div', {},
        React.createElement(
            "iframe", {
            id: "cardinal_collection_iframe",
            name: "collectionIframe",
            height: "10",
            width: "10",
            style: {
                display: "none"
            },
        },
            null
        ),
        React.createElement(
            "form", {
            id: "cardinal_collection_form",
            method: "POST",
            target: "collectionIframe",
            action: "",
        },
            React.createElement("input", {
                id: "cardinal_collection_form_input",
                type: "hidden",
                name: "JWT",
                value: "",
            })
        ),
        React.createElement(
            "div", {
            id: "modal-container",
            style: {
                display: "none"
            },
        },
            React.createElement(
                "div", {
                id: "modal-content"
            },
                React.createElement("iframe", {
                    id: "step-up-iframe-id",
                    name: "step-up-iframe",
                    height: "400",
                    width: "250",
                }),
                React.createElement(
                    "form", {
                    id: "step-up-form",
                    target: "step-up-iframe",
                    method: "post",
                    action: "",
                },
                    React.createElement("input", {
                        type: "hidden",
                        id: "accessToken",
                        name: "JWT",
                        value: "",
                    }),
                    React.createElement("input", {
                        type: "hidden",
                        name: "MD",
                        id: "merchantData",
                        value: "",
                    })
                )
            )
        )
    );




    const a = React.createElement('div', {
        id: 'buttonPaymentListContainer'
    },
        React.createElement('div', {
            type: 'button',
            id: 'checkoutEmbedded',
            disabled: 'disabled',
        }),
        React.createElement('div', {
            type: 'button',
            id: 'checkoutSidebar',
            disabled: 'disabled',
        }),
    );
    const embed = React.createElement('div', {
        id: 'embeddedPaymentContainer'
    });
    const errorDiv = React.createElement("p", {
        className: 'blocks-error-div-uc',
        style: {
            display: "none",
            color: 'red'
        }
    }, ucBlocksSettings.form_load_error);
    const errorFailure = React.createElement("p", {
        className: 'blocks-failure-error-div-uc',
        style: {
            display: "none",
            color: "red"
        }
    }, ucBlocksSettings.failure_error);

    // Adding description at checkout.
    const description = React.createElement("div", {}, ucBlocksSettings.description)
    const notice = React.createElement(window.wc.blocksCheckout.StoreNoticesContainer , {
        context:'visa_acceptance_solutions_notice_container'
    });

    return [description, a, tra, DDL_StepUp_iframe, embed, errorDiv, errorFailure, notice];
}

// Add function to show place order button on checkout.

function checkAndRemove() {
    if (!jQuery("#radio-control-wc-payment-method-options-" + ucBlocksSettings.visa_acceptance_solutions_uc_id).is(':checked')) {
        jQuery('.wc-block-components-checkout-place-order-button').show();
    }
}

// Check the condition every 1000 milliseconds (1 second)
setInterval(checkAndRemove, 500);

var canMakePayment = () => {
    return true;
};

const ucOptions = {
    name: ucBlocksSettings.visa_acceptance_solutions_uc_id,
    label: React.createElement("div", {}, ucBlocksSettings.title),
    content: React.createElement(ucComponents, {}, null),
    edit: React.createElement("div", {}, null),
    canMakePayment,
    paymentMethodId: ucBlocksSettings.visa_acceptance_solutions_uc_id,
    ariaLabel: "Unified Checkout",
    supports: {
        showSaveOption: ucBlocksSettings.enable_tokenization,
        features: ucBlocksSettings?.supports ?? [],
    },
    savedTokenComponent: React.createElement(ucblockssavedcomponent, {}, null),
};
if(ucBlocksSettings.force_tokenization){
    window.wp.data.dispatch( 'core/notices' ).createInfoNotice(
        ('One or more items in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.'),
        {id:"checkout",context:"visa_acceptance_solutions_notice_container",  isDismissible:false}
    );
}
registerPaymentMethod(ucOptions);