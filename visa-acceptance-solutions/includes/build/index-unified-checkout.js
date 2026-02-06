var {
    registerPaymentMethod,
    registerExpressPaymentMethod
} = wc.wcBlocksRegistry;
var ucBlocksSettings = wc.wcSettings.getSetting('visa_acceptance_solutions_unified_checkout_data');
var ucExpressPaySettings = wc.wcSettings.getSetting('express_pay_unified_checkout_data');

var {
    decodeEntities
} = wp.htmlEntities;

var { useSelect } = window.wp.data;
var { CHECKOUT_STORE_KEY } = window.wc.wcBlocksData;
var { ValidatedTextInput } = window.wc.blocksCheckout;
var uc_token, req_data;

function reloadAfterError() {
    setTimeout(() => {
        window.location.reload();
    }, 5000);
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

// Step-up iframe HTML template.
var stepUpIframeHTML = '<div id="cardinal_collection_form_div">' +
    '<iframe id="cardinal_collection_iframe" name="collectionIframe" height="10" width="10" style="display:none;"></iframe>' +
    '<form id="cardinal_collection_form" method="POST" target="collectionIframe" action="">' +
        '<input id="cardinal_collection_form_input" type="hidden" name="JWT" value="">' +
    '</form>' +
    '<div id="modal-container" style="display:none;">' +
        '<div id="modal-content">' +
            '<iframe id="step-up-iframe-id" name="step-up-iframe" height="400" width="400"></iframe>' +
            '<form id="step-up-form" target="step-up-iframe" method="post" action="">' +
                '<input type="hidden" id="accessToken" name="JWT" value="">' +
                '<input type="hidden" name="MD" id="merchantData" value="">' +
            '</form>' +
        '</div>' +
    '</div>' +
'</div>';

function getStepUpIframe() {
    return React.createElement('div', {
        dangerouslySetInnerHTML: { __html: stepUpIframeHTML }
    });
}

/**
 * Populate checkout billing and shipping fields from transient token
 * @param {string} transientToken - The transient token received from payment provider
 * @param {boolean} isExpressPay - Whether this is the express pay flow
 * @returns {Promise} Promise that resolves when addresses are populated
 */
function populateCheckoutFromTransientToken(transientToken, isExpressPay) {
    return new Promise((resolve, reject) => {
        var expressOnly = !!isExpressPay;
        var isLoggedIn = ucExpressPaySettings && ucExpressPaySettings.is_user_logged_in;

        if (!expressOnly || isLoggedIn) {
            resolve();
            return;
        }

        if (!transientToken) {
            resolve();
            return;
        }
        
        // Make AJAX call to get billing/shipping from transient token
        jQuery.ajax({
            type: "POST",
            url: ucBlocksSettings["ajax_url"],
            data: {
                action: 'get_addresses_from_transient_token',
                transientToken: transientToken
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const billing = response.data.billing;
                    const shipping = response.data.shipping;
                    
                    // Update WooCommerce Blocks checkout store with billing address
                    if (billing && Object.keys(billing).length > 0) {
                        const billingAddress = {
                            first_name: billing.first_name || '',
                            last_name: billing.last_name || '',
                            company: billing.company || '',
                            address_1: billing.address_1 || '',
                            address_2: billing.address_2 || '',
                            city: billing.city || '',
                            state: billing.state || '',
                            postcode: billing.postcode || '',
                            country: billing.country || '',
                            email: billing.email || '',
                            phone: billing.phone || ''
                        };
                        
                        try {
                            // Update billing address in checkout store
                            window.wp.data.dispatch('wc/store/cart').setBillingAddress(billingAddress);
                        } catch (e) {
                            console.log('Failed to set billing address:', e);
                        }
                    }
                    
                    // Update shipping address
                    if (shipping && Object.keys(shipping).length > 0) {
                        const shippingAddress = {
                            first_name: shipping.first_name || '',
                            last_name: shipping.last_name || '',
                            company: shipping.company || '',
                            address_1: shipping.address_1 || '',
                            address_2: shipping.address_2 || '',
                            city: shipping.city || '',
                            state: shipping.state || '',
                            postcode: shipping.postcode || '',
                            country: shipping.country || '',
                            phone: shipping.phone || ''
                        };
                        
                        try {
                            // Update shipping address in checkout store
                            window.wp.data.dispatch('wc/store/cart').setShippingAddress(shippingAddress);
                        } catch (e) {
                            console.log('Failed to set shipping address:', e);
                        }
                    }
                }
                resolve();
            },
            error: function(error) {
                console.log('Error fetching addresses from transient token:', error);
                resolve(); // Resolve anyway to allow order submission
            }
        });
    });
}

var ucblockssavedcomponent = (props) => {
    // Check if tokenization is enabled, then show saved card CVV field else hide.
    if (!ucBlocksSettings.tokenization) {
        (function () {
            var tokenizationEnabled = (typeof ucBlocksSettings !== 'undefined') ? !!ucBlocksSettings.enable_tokenization : true;

            if (tokenizationEnabled) return;

            function findUseAnotherElement() {
                const nodes = Array.from(document.querySelectorAll(
                    '.wc-block-components-radio-control, .wc-block-components-checkout__payment-method, .wc-block-components-checkout-step__description, legend, label'
                ));
                return nodes.find(n => {
                    try {
                        return n.textContent && n.textContent.trim().toLowerCase().includes('use another payment method');
                    } catch (e) {
                        return false;
                    }
                }) || null;
            }

            function hideSavedCardsAndUseAnother() {
                const useAnother = findUseAnotherElement();
                if (!useAnother) return false;

                const parent = useAnother.parentElement;
                if (!parent) return false;

                const children = Array.from(parent.children);
                for (let i = 0; i < children.length; i++) {
                    const el = children[i];
                    if (el.classList.contains('wc-block-components-radio-control') ||
                        el.className.indexOf('payment-method') !== -1 ||
                        el.querySelector?.('input[type="radio"]')) {
                        el.style.display = 'none';
                        el.setAttribute('data-hidden-by-tokenization', '1');
                    }
                    if (el === useAnother) {
                        el.style.display = 'none';
                        el.setAttribute('data-hidden-by-tokenization', '1');
                        break;
                    }
                }
                let prev = useAnother.previousElementSibling;
                while (prev) {
                    if (prev.querySelector && prev.querySelector('.wc-block-components-radio-control')) {
                        prev.style.display = 'none';
                        prev.setAttribute('data-hidden-by-tokenization', '1');
                    }
                    prev = prev.previousElementSibling;
                }
                window.wp.data.dispatch('wc/store/payment').__internalSetActivePaymentMethod(ucBlocksSettings.visa_acceptance_solutions_uc_id);

                return true;
            }
            if (hideSavedCardsAndUseAnother()) return;

            // observe DOM in case checkout re-renders.
            const observer = new MutationObserver(() => {
                hideSavedCardsAndUseAnother();
            });
            observer.observe(document.body, { childList: true, subtree: true });
            const fallbackInterval = setInterval(() => {
                if (hideSavedCardsAndUseAnother()) {
                    clearInterval(fallbackInterval);
                    observer.disconnect();
                }
            }, 500);
            window.__showSavedCards_by_tokenization = function() {
                document.querySelectorAll('[data-hidden-by-tokenization]').forEach(el => {
                    el.style.display = '';
                    el.removeAttribute('data-hidden-by-tokenization');
                });
            };
        })();
        return null;
    } 

    jQuery('.wc-block-components-checkout-place-order-button').show();
    var {
        token,
        eventRegistration,
        emitResponse
    } = props;
    var {
        onPaymentSetup,
        onCheckoutFail
    } = eventRegistration;

    var [cvvLength] = React.useState(4);
    var cvvRef = React.useRef('');

    // Flex microform states - MUST be declared before they're used.
    var [flexInstance, setFlexInstance] = React.useState(null);
    var [flexToken, setFlexToken] = React.useState(null);
    var [captureContext, setCaptureContext] = React.useState(null);
    var [flexFieldValid, setFlexFieldValid] = React.useState(false);
    var [flexError, setFlexError] = React.useState(null);
    var [validationError, setValidationError] = React.useState(null);
    var flexCvvRef = React.useRef(null);

    React.useEffect(() => {
        if (!cvvRef.current) return;
        cvvRef.current = '';
    }, [token])

    React.useEffect(() => {

        async function deriveAESKeyFromString(tknString) {
            var encoder = new TextEncoder();
            var valIdData = encoder.encode(tknString);
        
            // Hash the valId using SHA-256 and use the full 32 bytes for AES-256.
            var hashBuffer = await crypto.subtle.digest('SHA-256', valIdData);
        
            return new Uint8Array(hashBuffer).slice(0, 32); // Extract first 32 bytes for AES-256.
        }
        
        async function encryptData(data, tknString) {
            var extId = crypto.getRandomValues(new Uint8Array(12));//iv:extId
            var encoder = new TextEncoder();
            var encodedData = encoder.encode(data);
        
            try {
                var valId = await deriveAESKeyFromString(tknString);//key:valId
        
                var cryptoKey = await crypto.subtle.importKey(
                    'raw',
                    valId,
                    { name: 'AES-GCM' },
                    false,
                    [ucBlocksSettings.encrypt_const]
                );
        
                var encryptedBuffer = await crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: extId },
                    cryptoKey,
                    encodedData
                );
        
                var encryptedArray = new Uint8Array(encryptedBuffer);
                var refIdLength = 16;
                var ciphertext = encryptedArray.slice(0, encryptedArray.length - refIdLength);
                var refId = encryptedArray.slice(encryptedArray.length - refIdLength);//tag:refId
        
                return {
                    encrypted: btoa(String.fromCharCode(...ciphertext)),
                    extId: btoa(String.fromCharCode(...extId)),
                    refId: btoa(String.fromCharCode(...refId)),
                };
            } catch (error) {
                return null;
            }
        }
        
        var unsubscribe = onPaymentSetup(async () => {
            
            if (token) {
                if (ucBlocksSettings.saved_card_cvv) {
                    if (flexInstance) {
                        // Validate that Flex instance has the createToken method.
                        if (typeof flexInstance.createToken !== 'function') {
                            console.error('Flex instance does not have createToken method');
                            setValidationError("Payment system error. Please refresh and try again.");
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: __("Payment system error. Please refresh and try again."),
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                        await new Promise(resolve => setTimeout(resolve, 100));
                        if (!flexInstance.securityCode) {
                            console.error('Security code field not available');
                            setValidationError("Security code field not loaded. Please refresh and try again.");
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: __("Security code field not loaded. Please refresh and try again."),
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                        
                        if (!flexFieldValid) {
                            console.error('Security code field is not valid - user needs to enter CVV');
                            setValidationError("Please enter a valid security code.");
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: __("Please enter a valid security code."),
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                        setValidationError(null);
                        try {
                            var flexTokenResponse = await flexInstance.createToken();
                            if (!flexTokenResponse) {
                                return {
                                    type: emitResponse.responseTypes.ERROR,
                                    message: __("Please enter a valid security code and try again."),
                                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                                };
                            }
                            if (flexTokenResponse.error) {
                                console.error('Flex token creation error:', flexTokenResponse.error);
                                return {
                                    type: emitResponse.responseTypes.ERROR,
                                    message: __("Please Enter Valid Security Code."),
                                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                                };
                            }
                            if (!flexTokenResponse.token) {
                                console.error('Flex token missing in response:', flexTokenResponse);
                                return {
                                    type: emitResponse.responseTypes.ERROR,
                                    message: __("Failed to create security token. Please try again."),
                                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                                };
                            }
                            setFlexToken(flexTokenResponse.token);
                            
                            var wc_credit_card_getorderid = 'order_token';
                            var payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;

                            return {
                                type: emitResponse.responseTypes.SUCCESS,
                                meta: {
                                    paymentMethodData: {
                                        token,
                                        wc_credit_card_getorderid,
                                        flex_cvv_token: flexTokenResponse.token,
                                        payer_auth_enabled,
                                    },
                                },
                            };
                            
                        } catch (error) {               
                            setValidationError("Please enter a valid security code.");
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: __("Please enter a valid security code."),
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                    }
                    var wc_credit_card_getorderid = 'order_token';
                    var payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;
                    var tknString = ucBlocksSettings.token_key;
            
                    // Encrypt CVV using the derived AES-256 key.
                    var encryptedCVV = await encryptData(cvvValue, tknString);

                    if (!encryptedCVV) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: __("Security code validation failed. Please try again."),
                            messageContext: emitResponse.noticeContexts.PAYMENTS,
                        };
                    }
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
                } else {
                    var wc_credit_card_getorderid = 'order_token';
                    var payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                token,
                                wc_credit_card_getorderid,
                                payer_auth_enabled,
                            },
                        },
                    };
                }
            } else {
                console.error('No token provided for payment processing');
            }
            return {
                type: "error",
                validationErrors: {
                    "flex-cvv-field": {
                        message: __("Payment method not selected properly. Please refresh and try again."),
                        hidden: false
                    }
                }
            };
        });

        var ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
            var errorResponse = processingResponse;
            if (errorResponse.processingResponse?.paymentDetails?.message) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: errorResponse.processingResponse.paymentDetails.message,
                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                };
            }
            reloadAfterError();
            return true;
        });
        jQuery('.wc-block-components-notice-banner.is-error').hide().text('');
        return () => {
            unsubscribe();
            ucPaymentComponentfail();
        };
    }, [
        emitResponse.responseTypes.ERROR,
        emitResponse.responseTypes.SUCCESS,
        onPaymentSetup,
        onCheckoutFail,
        token,
        flexInstance,
        flexFieldValid
    ]);
    var [cvvValue, setCvvValue] = React.useState("");
    
    // Reset CVV and Flex when token changes.
    React.useEffect(() => {
        setCvvValue("");
        cvvRef.current = "";
        setFlexToken(null);
        setFlexFieldValid(false);
        setFlexError(null);
        setValidationError(null);
    }, [token]);
    var savedCardCvv = ucBlocksSettings.saved_card_cvv ? React.createElement("div", { className: "saved-card-container" },
        React.createElement("div", { className: "saved-card-flex-container" },
            React.createElement("label", { 
                htmlFor: "flex-cvv-field",
                className: "saved-card-cvv-label"
            }, "Security Code"),
            // Show error message if Flex initialization failed (wrong MID credentials).
            flexError ? React.createElement("div", {
                className: "wc-block-components-validation-error flex-cvv-error",
                role: "alert",
                style: {
                    color: "#d63638",
                    fontSize: "14px",
                    marginTop: "8px",
                    padding: "12px",
                    backgroundColor: "#f9e2e2",
                    border: "1px solid #d63638",
                    borderRadius: "4px"
                }
            }, flexError) : React.createElement("div", {
                className: "flex-cvv-container"
            },
                // Flex microform will be loaded here.
                React.createElement("div", {
                    ref: flexCvvRef,
                    id: "flex-cvv-field",
                    className: "saved-card-cvv-field flex-microform-field",
                    style: {
                        border: validationError ? "1px solid #d63638" : "1px solid #ccc",
                        borderRadius: "4px",
                        padding: "8px",
                        fontSize: "14px",
                        minHeight: "40px",
                        width: "100%"
                    }
                }),
                // Show inline validation error below CVV field.
                validationError ? React.createElement("div", {
                    className: "wc-block-components-validation-error",
                    role: "alert",
                    style: {
                        color: "#d63638",
                        fontSize: "12px",
                        marginTop: "4px"
                    }
                }, validationError) : null
            )
        ),
        React.createElement('div', { className: "saved-card-alignment-fix" }),
    ) : null;

    // Initialize Flex microform for saved card CVV.
    React.useEffect(() => {      
        if (typeof Flex !== 'undefined' && token && ucBlocksSettings.saved_card_cvv) {
            var flexCaptureContext = ucBlocksSettings.flex_capture_context;
            
            if (flexCaptureContext && typeof flexCaptureContext === 'string') {
                var jwtParts = flexCaptureContext.split('.');
                if (jwtParts.length === 3) {
                    setCaptureContext(flexCaptureContext);
                    
                    try {
                        // Direct Flex API implementation.
                        var flex = new Flex(flexCaptureContext);
                        var microform = flex.microform('card', { 
                            styles: {
                                'input': {
                                    'font-size': '26px',
                                    'font-family': 'inherit',
                                    'color': '#333',
                                }
                            },
                        });
                        var cardType = '';
                        var cardKey = token || ucBlocksSettings.token_key;
                       
                        if (ucBlocksSettings.token_type && cardKey) {
                            cardType = ucBlocksSettings.token_type[cardKey] || '';
                        }
                        var max_length = ('AMEX' == cardType) ? 4:3;
                        if(max_length)
                        var securityCode = microform.createField('securityCode', { placeholder: '•••',maxLength:max_length });              
                        
                        // Wait for DOM to be ready and load CVV field.
                        setTimeout(() => {
                            var containerElement = flexCvvRef.current;
                            if (!containerElement) {
                                setFlexInstance(null);
                                return;
                            }
                            
                            if (!(containerElement instanceof HTMLElement)) {
                                console.error('FIELD_LOAD_INVALID_CONTAINER: Container is not a valid DOM element', containerElement);
                                setFlexInstance(null);
                                return;
                            }
                            
                            try {
                                securityCode.load(containerElement);
                                setFlexInstance({
                                    microform: microform,
                                    securityCode: securityCode,
                                    createToken: function() {
                                        return new Promise((resolve, reject) => {
                                            try {
                                                const options = {};
                                                
                                                microform.createToken(options, (err, flexjwtToken) => {
                                                    if (err) {
                                                        console.error('Microform createToken error:', err);
                                                        reject(err);
                                                    } else {
                                                        // Return the token directly - no AJAX call needed.
                                                        resolve({ token: flexjwtToken });
                                                    }
                                                });
                                            } catch (error) {
                                                reject(error);
                                            }
                                        });
                                    }
                                });
                                
                                // Handle CVV field events.
                                securityCode.on('change', function(data) {
                                    setFlexFieldValid(data.valid);
                                    setCvvValue(data.valid ? 'yes' : '');
                                    if (data.valid) {
                                        cvvRef.current = 'flex-cvv-valid';
                                        setValidationError(null); // Clear error when valid
                                    } else {
                                        cvvRef.current = '';
                                    }
                                });
                            } catch (fieldError) {
                                setFlexError('Unable to load secure CVV field. Please refresh the page or contact support.');
                                setFlexInstance(null);
                            }
                        }, 100);
                        
                    } catch (error) {
                        // Check for specific CAPTURE_CONTEXT_INVALID error.
                        if (error.message && error.message.includes('CAPTURE_CONTEXT_INVALID')) {
                            setFlexError('Payment configuration error. Invalid merchant credentials detected. Please contact the site administrator.');
                        } else if (error.message && error.message.includes('AUTHENTICATION')) {
                            setFlexError('Payment gateway authentication failed. Please contact the site administrator.');
                        } else {
                            setFlexError('Unable to initialize secure payment form. Please contact support.');
                        }
                        
                        setFlexInstance(null);
                    }
                } else {
                    setFlexError('Payment configuration error. Invalid security token format. Please contact support.');
                    setFlexInstance(null);
                }
            } else {
                setFlexInstance(null);
                // Hide the entire saved card CVV container.
                if (flexCvvRef.current && flexCvvRef.current.parentElement) {
                    flexCvvRef.current.parentElement.parentElement.style.display = 'none';
                }
            }
        } else {
            console.warn('Flex CVV initialization skipped:', {
                flexLibraryLoaded: typeof Flex !== 'undefined',
                hasToken: !!token,
                savedCardCvvEnabled: !!ucBlocksSettings.saved_card_cvv
            });
        }
    }, [token, ucBlocksSettings.saved_card_cvv]);

    const DDL_StepUp_iframe = getStepUpIframe();

    return [savedCardCvv, DDL_StepUp_iframe];
};

var ucComponents = (props) => {
    jQuery('.wc-block-components-checkout-place-order-button').hide();

    var {
        onSubmit,
        eventRegistration,
        emitResponse,
        billing,
        components
    } = props;
    var {
        onPaymentSetup,
        onCheckoutFail
    } = eventRegistration;

    var { LoadingMask } = components;
    var isIdle = useSelect(CHECKOUT_STORE_KEY).isIdle();
    var orderId = useSelect(CHECKOUT_STORE_KEY).getOrderId();
    
    // Get shipping rates to track changes.
    var shippingRates = useSelect((select) => {
        try {
            return select('wc/store/cart').getShippingRates();
        } catch (e) {
            return [];
        }
    });

    var { cartTotal, shippingAddress } = billing || {};
    var [isLoadingCC, setLoadingCC] = React.useState(true);
    var [captureContext, setCaptureContext] = React.useState(null);

    // Restore payment method after reload (for regular UC component).
    React.useEffect(() => {
        var savedPaymentMethod = sessionStorage.getItem('visa_last_payment_method');
        if (savedPaymentMethod) {
            try {
                window.wp.data.dispatch('wc/store/payment').__internalSetActivePaymentMethod(savedPaymentMethod);
                sessionStorage.removeItem('visa_last_payment_method');
            } catch (e) {
                console.log('Failed to restore payment method:', e);
            }
        }
    }, []);

    React.useEffect(() => {
        setLoadingCC(true);
        //get the updated captureContext if any cart value is changed or shipping changes
        getCaptureContext(orderId).then(response => {
            response && setCaptureContext(response);
        }).catch((error) => {
            setCaptureContext("Invalid Capture context");
        }).finally(() => setLoadingCC(false));
    }, [cartTotal.value, shippingAddress, shippingRates]);

     /**
     * 
     * @param {string} orderId - current orderid
     * @returns {Promise<string>}
     */
    var getCaptureContext = React.useCallback(async (orderId) => {
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
                        if (data.success && data.capture_context_jwt) {
                            return resolve(data.capture_context_jwt);
                        }
                        if (data.success && data.capture_context) {
                            return resolve(data.capture_context);
                        }
                        if (data.success && !data.capture_context && !data.capture_context_jwt) {
                            return resolve(false);
                        }
                        return reject("Something went wrong");
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    return reject(errorThrown);
                },
            });
            } catch (error) {
                return reject(error);
            }
        });
    }, [cartTotal.value, shippingAddress, shippingRates]);

    React.useEffect(() => {

        var ucPaymentComponent = onPaymentSetup(async () => {

            var blocks_token = uc_token;
            var payer_auth_enabled = ucBlocksSettings.payer_auth_enabled;
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
        var ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
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
            return true;
        });
        jQuery('.wc-block-components-notice-banner.is-error').hide().text('');
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
            jQuery('.wc-block-components-checkout-place-order-button').hide();
            var transientToken = document.getElementById("transientToken");
            var cc = document.getElementById("jwt") ? document.getElementById("jwt").value : '';
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
                        jQuery('#embeddedPaymentContainer').empty();
                        
                        // Extract and populate billing/shipping from transient token, then submit
                        populateCheckoutFromTransientToken(tt, false).then(function() {
                            onSubmit();
                        });
                    })
                    .catch(function (error) {
                        jQuery('#buttonPaymentListContainer').empty();
                        jQuery('#embeddedPaymentContainer').empty();
                        jQuery('.blocks-failure-error-div-uc').show();

                    });
            } else {
                jQuery('.blocks-error-div-uc').show();
            }

        }
    }, [captureContext, isIdle, isLoadingCC])

    var loader = React.createElement(LoadingMask, {
        isLoading: true,
        screenReaderLabel: "Loading Capture Context",
        showSpinner: true
    });

    if (isLoadingCC) {
        return [loader];
    }

    const trasientToken = React.createElement('div', null,
        React.createElement('input', {
            type: 'hidden',
            id: 'transientToken',
            name: 'transientToken'
        })
    );


    const DDL_StepUp_iframe = getStepUpIframe();

    var newCardDiv = React.createElement('div', {
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
    var embed = React.createElement('div', {
        id: 'embeddedPaymentContainer'
    });
    var errorDiv = React.createElement("p", {
        className: 'blocks-error-div-uc',
        style: {
            display: "none",
            color: 'red'
        }
    }, ucBlocksSettings.form_load_error);
    var errorFailure = React.createElement("p", {
        className: 'blocks-failure-error-div-uc',
        style: {
            display: "none",
            color: "red"
        }
    }, ucBlocksSettings.failure_error);

    // Adding description at checkout.
    var description = React.createElement("div", {}, ucBlocksSettings.description)
    var notice = React.createElement(window.wc.blocksCheckout.StoreNoticesContainer , {
        context:'visa_acceptance_solutions_notice_container'
    });

    return [description, newCardDiv, trasientToken, DDL_StepUp_iframe, embed, errorDiv, errorFailure, notice];
}

var ucExpressPayComponents = (props) => {

    var {
        onSubmit,
        eventRegistration,
        emitResponse,
        billing,
        components
    } = props;
    var {
        onPaymentSetup,
        onCheckoutFail
    } = eventRegistration;

    var { LoadingMask } = components;
    var isIdle = useSelect(CHECKOUT_STORE_KEY).isIdle();
    var orderId = useSelect(CHECKOUT_STORE_KEY).getOrderId();
    
    // Get shipping rates to track changes.
    var shippingRates = useSelect((select) => {
        try {
            return select('wc/store/cart').getShippingRates();
        } catch (e) {
            return [];
        }
    });
    

    var { cartTotal, shippingAddress } = billing || {};
    var [isLoadingCC, setLoadingCC] = React.useState(true);
    var [captureContext, setCaptureContext] = React.useState(null);
    var [isExpressCheckout, setExpressCheckout] = React.useState(null);

    React.useEffect(() => {
        setLoadingCC(true);
        //get the updated captureContext if any cart value is changed or shipping changes.
        getCaptureContext(orderId).then(response => {
            response && setCaptureContext(response);
        }).catch((error) => {
            setCaptureContext("Invalid Capture context");
        }).finally(() => setLoadingCC(false));
    }, [cartTotal.value, shippingRates]);

     /**
     * 
     * @param {string} orderId - current orderid
     * @returns {Promise<string>}
     */
    var getCaptureContext = React.useCallback(async (orderId) => {
        return new Promise((resolve, reject) => {
            try {
            jQuery.ajax({
                type: "POST",
                url: ucExpressPaySettings["ajax_url"],
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
                        if (data.success && data.capture_context_ep_jwt) {
                            return resolve(data.capture_context_ep_jwt);
                        }
                        if (data.success && data.capture_context) {
                            return resolve(data.capture_context);
                        }
                        if (data.success && !data.capture_context && !data.capture_context_ep_jwt) {
                            return resolve(false);
                        }
                        return reject("Something went wrong");
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    return reject(errorThrown);
                },
            });
            } catch (error) {
                return reject(error);
            }
        });
    }, [cartTotal.value, shippingAddress, shippingRates]);

    React.useEffect(() => {
        if (!isExpressCheckout)
            return;
        var expressPayPaymentComponent = onPaymentSetup(async () => {
            // Here we can do any processing we need, and then emit a response.
            // For example, we might validate a custom field, or perform an AJAX request, and then emit a response indicating it is valid or not.
            var blocks_token = uc_token;
            var payer_auth_enabled = ucExpressPaySettings.payer_auth_enabled;
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
        var expressPayPaymentComponentfail = onCheckoutFail((processingResponse) => {
            if (jQuery('#embeddedExpressPaymentContainer').find('iframe').length > 0) {
                jQuery('#embeddedExpressPaymentContainer').children().remove();
            }

            var errorResponse = processingResponse;
            if (errorResponse.processingResponse?.paymentDetails?.message) {
                // Show payment method section back when validation fails.
                hideExpressPayLoader();
                jQuery('#payment-method').show();
                jQuery('.wc-block-components-checkout-place-order-button').show();
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: errorResponse.processingResponse.paymentDetails.message,
                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                };
            }
            // Show payment method section back on any checkout failure.
            hideExpressPayLoader();
            jQuery('#payment-method').show();
            jQuery('.wc-block-components-checkout-place-order-button').show();
            reloadAfterError();
            return true;
        });
        jQuery('.wc-block-components-notice-banner.is-error').hide().text('');
        return () => {
            expressPayPaymentComponent();
            expressPayPaymentComponentfail();
        };


    }, [
        emitResponse.responseTypes.ERROR,
        emitResponse.responseTypes.SUCCESS,
        onPaymentSetup,
        onCheckoutFail,
    ]);

    // Watch for validation errors and show payment form back.
    React.useEffect(() => {
        if (!isExpressCheckout) return;
        
        const observer = new MutationObserver(() => {
            // Check if validation errors are present in the DOM.
            const hasValidationErrors = document.querySelector('.wc-block-components-validation-error') || 
                                       document.querySelector('.wc-block-error-message');
            
            if (hasValidationErrors && jQuery('#payment-method').is(':hidden')) {
                hideExpressPayLoader();
                jQuery('#payment-method').show();
                jQuery('.wc-block-components-checkout-place-order-button').show();
            }
        });
        
        // Observe the checkout form for changes.
        const checkoutForm = document.querySelector('.wc-block-checkout__form');
        if (checkoutForm) {
            observer.observe(checkoutForm, { childList: true, subtree: true });
        }
        
        return () => observer.disconnect();
    }, [isExpressCheckout]);

    React.useEffect(() => {
        if ((!isIdle && ucExpressPaySettings.isVersionSupported) || isLoadingCC) {
            return;
        }
        if ( ( ucExpressPaySettings.enable_gpay || ucExpressPaySettings.enable_apay || ucExpressPaySettings.enable_paze) ) {
            if (jQuery(".wc-block-components-express-payment__content")) {
                
                var transientToken = document.getElementById("transientToken");
                var cc = document.getElementById("ep_jwt")?.value ?? null;

                if (captureContext) {
                    cc = captureContext;
                }
                var showArgs = {
                    containers: {
                        paymentSelection: "#expressPaymentListContainer",
                        paymentScreen: '#embeddedExpressPaymentContainer',
                    }
                };
                //clear in case of reinitialising or any error happens.
                jQuery('#expressPaymentListContainer').empty();
                jQuery('#embeddedExpressPaymentContainer').empty();
                
                if (typeof Accept !== 'undefined' && cc) {
                    jQuery('.blocks-error-div-express-uc').hide();
                    jQuery('.blocks-failure-error-div-express-uc').hide();

                    Accept(cc)
                        .then(function (accept) {
                            return accept.unifiedPayments(false);
                        })
                        .then(function (up) {
                            jQuery('.wc-block-components-checkout-place-order-button').hide();
                            return up.show(showArgs);
                        })
                        .then(function (tt) {
                            
                            // Set the active payment method to Visa Acceptance Solutions Unified Checkout when make payment with Express Pay.
                            // Otherwise default selected payment method will be used.
                            window.wp.data.dispatch('wc/store/payment').__internalSetActivePaymentMethod(ucBlocksSettings.visa_acceptance_solutions_uc_id);
                            
                            jQuery('#payment-method').hide();
                            setExpressCheckout(true);
                            // Show loading overlay.
                            showExpressPayLoader();

                            transientToken.value = tt;
                            jQuery('#transientToken').val(tt);
                            uc_token = tt;
                            window.uc_token_global = tt;
                            // For infinite page loading at checkout.
                            jQuery('#embeddedExpressPaymentContainer').empty();
                            
                            // Extract and populate billing/shipping from transient token, then submit
                            populateCheckoutFromTransientToken(tt, true).then(function() {
                                setTimeout(() => {
                                    onSubmit();
                                    // Monitor if checkout remains idle after submission attempt (validation failed).
                                    setTimeout(() => {
                                        const checkoutStatus = window.wp.data.select('wc/store/checkout');
                                        if (checkoutStatus && typeof checkoutStatus.isIdle === 'function' && checkoutStatus.isIdle()) {
                                            // Checkout is still idle, meaning validation failed - show form back.
                                            hideExpressPayLoader();
                                            jQuery('#payment-method').show();
                                            jQuery('.wc-block-components-checkout-place-order-button').show();
                                        }
                                    }, 500);
                                }, 200);
                            });
                            hideExpressPayLoader();
                        })
                        .catch(function (error) {
                            jQuery('#expressPaymentListContainer').empty();
                            jQuery('#embeddedExpressPaymentContainer').empty();
                            jQuery('.blocks-failure-error-div-express-uc').show();
                            // Show payment method section back if express pay fails.
                            hideExpressPayLoader();
                            jQuery('#payment-method').show();
                            jQuery('.wc-block-components-checkout-place-order-button').show();
                        });
                } else {
                    jQuery('.blocks-error-div-express-uc').show();
                    if (cc != null) {
                        reloadAfterError();
                    }
                }

            }
        }
        else {
            jQuery(".wc-block-components-express-payment").hide();
            jQuery('.wc-block-components-express-payment-continue-rule').hide();
        }
    }, [captureContext, isIdle, isLoadingCC])

    var loaderCC = React.createElement(LoadingMask, {
        isLoading: true,
        screenReaderLabel: "Loading Capture Context",
        showSpinner: true
    });

    if (isLoadingCC) {
        return [loaderCC];
    }

    var trasientToken = React.createElement('div', null,
        React.createElement('input', {
            type: 'hidden',
            id: 'transientToken',
            name: 'transientToken'
        })
    );

    var EP_DDL_StepUp_iframe = getStepUpIframe();

    var expressPayDiv = React.createElement('div', {
        id: 'expressPaymentListContainer'
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

    var embed = React.createElement('div', {
        id: 'embeddedExpressPaymentContainer'
    });

    var errorDiv = React.createElement("p", {
        className: 'blocks-error-div-express-uc',
        style: {
            display: "none",
            color: 'red'
        }
    }, ucExpressPaySettings.form_load_error);

    var errorFailure = React.createElement("p", {
        className: 'blocks-failure-error-div-express-uc',
        style: {
            display: "none",
            color: "red"
        }
    }, ucExpressPaySettings.failure_error);

    // Adding description at checkout.
    var description = React.createElement("div", {}, ucExpressPaySettings.description)
    var notice = React.createElement(window.wc.blocksCheckout.StoreNoticesContainer , {
        context:'visa_acceptance_solutions_notice_container'
    });

    return [description, expressPayDiv, trasientToken, EP_DDL_StepUp_iframe, embed, errorDiv, errorFailure, notice];
}

// Add function to show place order button on checkout.

function checkAndRemove() {
    if (!jQuery("#radio-control-wc-payment-method-options-" + ucBlocksSettings.visa_acceptance_solutions_uc_id).is(':checked')) {
        jQuery('.wc-block-components-checkout-place-order-button').show();
    }
}

// Check the condition every 1000 milliseconds (1 second).
setInterval(checkAndRemove, 500);

var canMakePayment = () => {
    return true;
};

var UCId = typeof ucBlocksSettings.visa_acceptance_solutions_uc_id === 'string' && ucBlocksSettings.visa_acceptance_solutions_uc_id.length ? ucBlocksSettings.visa_acceptance_solutions_uc_id : 'visa_acceptance_solutions_uc';
var ucOptions = {
    name: UCId,
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
registerPaymentMethod(ucOptions);

if ( ucExpressPaySettings.subscription_order && ! ucExpressPaySettings.is_subscriptions_tokenization_enabled ){}
else {
    if(ucExpressPaySettings.enabled_payment_methods.length !== 0 ) {
        var expressPayUCId = typeof ucExpressPaySettings.express_pay_uc_id === 'string' && ucExpressPaySettings.express_pay_uc_id.length ? ucExpressPaySettings.express_pay_uc_id : 'express_pay_uc';
        var expressCheckoutForm = {
            name: expressPayUCId,
            label: React.createElement("div", {}, ucExpressPaySettings.title),
            content: React.createElement(ucExpressPayComponents, {}, null),
            edit: React.createElement('div', {}, null),
            canMakePayment,
            paymentMethodId: ucExpressPaySettings.express_pay_uc_id,
            ariaLabel: "Express Checkout",
            supports: {
                features: ucExpressPaySettings?.supports ?? [],
            },
        };
        registerExpressPaymentMethod(expressCheckoutForm);
    }
}

if(ucExpressPaySettings.force_tokenization || ucBlocksSettings.force_tokenization){
    window.wp.data.dispatch( 'core/notices' ).createInfoNotice(
        ('One or more items in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.'),
        {id:"checkout",context:"visa_acceptance_solutions_notice_container",  isDismissible:false}
    );
}
