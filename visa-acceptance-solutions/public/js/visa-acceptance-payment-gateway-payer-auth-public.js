var pareq;
var order_id;
var cardtoken;
var savedtoken;
var tokenCheckbox;
var flag;
var blockstoken;
var enrollment_uc_flag = false;
const challengeWindowSizeMap = {
    '01': { width: 250, height: 400 },
    '02': { width: 390, height: 400 },
    '03': { width: 500, height: 600 },
    '04': { width: 600, height: 400 },
    '05': { width: window.innerWidth, height: window.innerHeight},
    '06': { width: 400, height: 400 }
};

 
function reloadAfterError() {
    setTimeout(() => {
        window.location.reload();
    }, 5000);
}
 

(function($) {
    'use strict';

    /**
     * All of the code for your public-facing JavaScript source
     * should reside in this file.
     *
     * Note: It has been assumed you will write jQuery code here, so the
     * $ function reference has been prepared for usage within the scope
     * of this function.
     *
     * This enables you to define handlers, for when the DOM is ready:
     *
     * $(function() {
     *
     * });
     *
     * When the window is loaded:
     *
     * $( window ).load(function() {
     *
     * });
     *
     * ...and/or other possibilities.
     *
     * Ideally, it is not considered best practise to attach more than a
     * single DOM-ready or window-load handler for a particular page.
     * Although scripts in the WordPress core, Plugins and Themes may be
     * practising this, we should strive to set a better example in our own work.
     */

    /**
     * Implementing Cardinal Cruise Direct Connection
     * API Step 1: Payer Authentication Setup Service.
     * 3-D Secure 2.x and backward compatble with 3-D Secure 1.0
     */

    /**
     * Step 1: Payer Authentication Setup Service
     * Step 1-a) Get Order ID as soon as place order clicked
     */

    var referenceId;
    var currentHash = '';
    var setup_uc_flag = false;
    var sca_flag = 'no';


    /**
     *
     * Step 1-b)Event listener for hashchange to extract order id from it
     */
    jQuery(window).on(
        'hashchange',
        function() {

            // check if loader is present if not then add and show loader
            let param = {'hash': ''};
            if(typeof payer_auth_param !== 'undefined' ){
                param = payer_auth_param;
            } else if(typeof visa_acceptance_uc_payer_auth_param !== 'undefined'){
                param = visa_acceptance_uc_payer_auth_param;
            }

            if (param['hash'] != location.hash) {

                currentHash = location.hash;
                if (typeof payer_auth_param !== 'undefined') {
                    payer_auth_param['hash'] = currentHash;
                } else if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined') {
                    visa_acceptance_uc_payer_auth_param['hash'] = currentHash;
                }


                const regex = /#order_change_(\d{3})_(\d+)/;
                const match = currentHash.match(regex);
                if (match) {
                    order_id = match[2];
                }
                currentHash = currentHash.slice(0, 14);
                if (currentHash == '#order_change_') {

                    var loader = $("#loader");
                    if (loader.length) {
                        if (isLoaderOnDisplay) {
                            showLoader();
                        }
                    } else {
                        addLoader();
                        showLoader();
                    }

                    // Fetch tokens such as saved cards token, flex token and saved card checkbox

                    // Step 2) Payer Auth Setup call After getting the order ID
                    if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && (jQuery("#transientToken").val() != undefined && jQuery("#transientToken").val() != '')) {
                        var transientToken = jQuery("#transientToken").val();
                        fetchTokens_uc();

                        setup_uc(order_id, transientToken);
                    } else {
                        fetchTokens();
                        setup(order_id);
                    }

                } else if (currentHash == '#order_blocks_') {
                    var loader = $("#loader");
                    if (loader.length) {
                        if (isLoaderOnDisplay) {
                            showLoader();
                        }
                    } else {
                        addLoader();
                        showLoader();
                    }
                    var currentHash = location.hash;
                    const regex = /#order_blocks_(\d{3})_(\d+)_(.*)/;
                    const match = currentHash.match(regex);
                    if (match) {
                        order_id = match[2];
                        blockstoken = match[3];
                    }

                    if (match == null) {
                        const updatedRegex = /#order_blocks_(\d{3})_(\d+)/;
                        const anotherMatch = currentHash.match(updatedRegex);
                        order_id = anotherMatch[2];
                        blockstoken = anotherMatch[3];
                    }

                    // Fetch tokens such as saved cards token, flex token and saved card checkbox

                    // Step 2) Payer Auth Setup call After getting the order ID
                    if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && (jQuery("#transientToken").val() != undefined && jQuery("#transientToken").val() != '')) {
                        var transientToken = jQuery("#transientToken").val();
                        fetchTokens_uc();

                        setup_uc(order_id, transientToken);
                    } else {
                        fetchTokens();
                        setup(order_id);
                    }

                } else if (currentHash == '#order_bolcks_') {

                    var loader = $("#loader");
                    if (loader.length) {
                        if (isLoaderOnDisplay) {
                            showLoader();
                        }
                    } else {
                        addLoader();
                        showLoader();
                    }
                    var currentHash = location.hash;
                    const regex = /#order_bolcks_(\d{3})_(\d+)_(.*)/;
                    const match = currentHash.match(regex);
                    if (match) {
                        order_id = match[2];
                        blockstoken = match[3];
                    }

                    if (match == null) {
                        const updatedRegex = /#order_bolcks_(\d{3})_(\d+)/;
                        const anotherMatch = currentHash.match(updatedRegex);
                        order_id = anotherMatch[2];
                        blockstoken = anotherMatch[3];
                    }

                    var transientToken = jQuery("#transientToken").val();
                    // Fetch tokens such as saved cards token, flex token and saved card checkbox
                    fetchTokens_uc();

                    // Step 2) Payer Auth Setup call After getting the order ID

                    if (transientToken == undefined) {
                        transientToken = '';
                    }
                    setup_uc(order_id, transientToken);
                }
            }

        }
    );

    /**
     *
     * Step 2-a)Listen for message event after setup response and call enrollment if received
     */
    window.addEventListener(
        "message",
        function(event) {
            if ((typeof payer_auth_param !== 'undefined' && event.origin === payer_auth_param["cardinal_url"]) || (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && event.origin === visa_acceptance_uc_payer_auth_param["cardinal_url"])) {
                let data = JSON.parse(event.data);
                if (data != undefined && data.Status) {
                    // Step 3) After Payer Enrollment Service is called after receiving responce for setup
                    // Passing Order ID and Reference ID
                    if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && (jQuery("#transientToken").val() != undefined && jQuery("#transientToken").val() != '')) {
                        var transientToken = jQuery("#transientToken").val();
                        if(jQuery('#sca_form').val() == 'true'){
                            enrollment_uc(order_id, referenceId, transientToken, 'yes');
                        } else{
                            enrollment_uc(order_id, referenceId, transientToken, 'no');
                        }
                        
                    } else if (setup_uc_flag && referenceId) {
                        var transientToken = jQuery("#transientToken").val();
                        if(jQuery('#sca_form').val() == 'true'){
                            
                            enrollment_uc(order_id, referenceId, transientToken, 'yes');
                        } else{
                            
                            enrollment_uc(order_id, referenceId, transientToken, 'no');
                        }
                        
                    } else {
                        if(referenceId){
                            enrollment(order_id, referenceId);
                        }
                        
                    }
                }
            }
        },
        false
    );

    /**
     * Step 2) Payer Authentication Setup Service After getting the order ID
     */
    function setup(orderid) {

        try {
            $.ajax({
                type: "POST",
                url: payer_auth_param["admin_url"],
                cache: false,
                async: false,
                data: {
                    action: "wc_call_payer_auth_setup_action",
                    nonce: payer_auth_param["nonce_setup"],
                    data: cardtoken,
                    savedtoken: savedtoken,
                    orderid: orderid
                },
                success: function(data) {
                    if (data.status == "COMPLETED") {
                        referenceId = encodeURI(data.referenceId);
                        var dataCollectionUrl = encodeURI(data.dataCollectionUrl);
                        var accessToken = encodeURIComponent(data.accessToken);

                        // Adding element only if it's special case of pay-order page
                        if (document.querySelector("#order_review") != null) {
                            if (document.querySelector("#cardinal_collection_iframe") == null) {
                                var iframe = document.createElement('iframe');

                                // Set the attributes for the iframe
                                iframe.id = 'cardinal_collection_iframe';
                                iframe.name = 'collectionIframe';
                                iframe.height = '10';
                                iframe.width = '10';
                                iframe.style.display = 'none';
                                document.body.appendChild(iframe);
                            }
                            var formElement = document.createElement("form");
                            var formElement = document.createElement("form");

                            // Set attributes for the form element
                            formElement.id = "cardinal_collection_form";
                            formElement.method = "POST";
                            formElement.target = "collectionIframe";
                            formElement.action = "/";

                            // Create an input element
                            var inputElement = document.createElement("input");

                            // Set attributes for the input element
                            inputElement.id = "cardinal_collection_form_input";
                            inputElement.type = "hidden";
                            inputElement.name = "JWT";
                            inputElement.value = "your_value_here"; // Set the actual value you want

                            // Append the input element to the form element
                            formElement.appendChild(inputElement);

                            // Append the form element to the document body or another container
                            document.body.appendChild(formElement);
                        }

                        // updating form
                        $("#cardinal_collection_form").attr("action", dataCollectionUrl);
                        $("#cardinal_collection_form #cardinal_collection_form_input").val(accessToken);

                        var ddcForm = document.querySelector("#cardinal_collection_form");
                        if (ddcForm) {
                            ddcForm.submit();
                        }
                    } else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null && data.status !== 'AUTHORIZED' && data.status !== 'AUTHORIZED_PENDING_REVIEW'){
                        const redirection_url = data.checkoutRedirect;
                        const actual_data = data;
                        try {
                            jQuery.ajax({
                                type: "POST",
                                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                cache: false,
                                async: false,
                                data: {
                                    action: "wc_call_uc_payer_auth_error_handler",
                                    nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"]
                                },
                                success: function(data) {
                                    hideLoader();
                                    window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                    window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                        actual_data.error,
                                        {id:"checkout",context:"wc/checkout"}
                                    );
                                    reloadAfterError();
                                },
                                error: function(XMLHttpRequest, textStatus, errorThrown) {
                                    console.log(errorThrown);
                                    alert(visa_acceptance_ajaxUCObj.error_failure);
                                },
                            });
                        } catch (exception) {
                            console.log(exception);
                            alert(visa_acceptance_ajaxUCObj.error_failure);
                        }
                    } else {
                        if (data.checkoutRedirect) {
                            window.location.href = data.checkoutRedirect;
                        } else {
                            const currentURL = new URL(window.location.href);
                            let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                            window.location.href = decodeURIComponent(baseUrl);
                        }
                    }
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    alert(visa_acceptance_ajaxUCObj.error_failure);
                    reloadAfterError();

                },
            });
        } catch (exception) {
            console.log(exception);
            alert(visa_acceptance_ajaxUCObj.error_failure);
        }
    }

    /**
     * Step 2) Payer Authentication Setup Service After getting the order ID
     */
    function setup_uc(orderid, transientToken) {

        try {
            $.ajax({
                type: "POST",
                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                cache: false,
                async: false,
                data: {
                    action: "wc_call_uc_payer_auth_setup_action",
                    nonce: visa_acceptance_uc_payer_auth_param["nonce_setup"],
                    data: transientToken,
                    savedtoken: savedtoken,
                    orderid: orderid
                },
                success: function(data) {
                    if (data.status == "COMPLETED") {
                        referenceId = encodeURI(data.referenceId);
                        var dataCollectionUrl = encodeURI(data.dataCollectionUrl);
                        var accessToken = encodeURIComponent(data.accessToken);
                        setup_uc_flag = true;

                        // Adding element only if it's special case of pay-order page
                        if (document.querySelector("#order_review") != null) {
                            if (document.querySelector("#cardinal_collection_iframe") == null) {
                                var iframe = document.createElement('iframe');

                                // Set the attributes for the iframe
                                iframe.id = 'cardinal_collection_iframe';
                                iframe.name = 'collectionIframe';
                                iframe.height = '10';
                                iframe.width = '10';
                                iframe.style.display = 'none';
                                document.body.appendChild(iframe);
                            }
                            var formElement = document.createElement("form");

                            // Set attributes for the form element
                            formElement.id = "cardinal_collection_form";
                            formElement.method = "POST";
                            formElement.target = "collectionIframe";
                            formElement.action = "/";

                            // Create an input element
                            var inputElement = document.createElement("input");

                            // Set attributes for the input element
                            inputElement.id = "cardinal_collection_form_input";
                            inputElement.type = "hidden";
                            inputElement.name = "JWT";
                            inputElement.value = "your_value_here"; // Set the actual value you want

                            // Append the input element to the form element
                            formElement.appendChild(inputElement);

                            // Append the form element to the document body or another container
                            document.body.appendChild(formElement);
                        }

                        // updating form
                        $("#cardinal_collection_form").attr("action", dataCollectionUrl);
                        $("#cardinal_collection_form #cardinal_collection_form_input").val(accessToken);

                        var ddcForm = document.querySelector("#cardinal_collection_form");
                        if (ddcForm) {
                            ddcForm.submit();
                        }
                    } else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null && data.status !== 'AUTHORIZED' && data.status !== 'AUTHORIZED_PENDING_REVIEW'){
                        const redirection_url = data.checkoutRedirect;
                        const actual_data = data;
                        try {
                            jQuery.ajax({
                                type: "POST",
                                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                cache: false,
                                async: false,
                                data: {
                                    action: "wc_call_uc_payer_auth_error_handler",
                                    nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"]
                                },
                                success: function(data) {
                                    hideLoader();
                                    window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                    window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                        actual_data.error,
                                        {id:"checkout",context:"wc/checkout"}
                                    );
                                    reloadAfterError();
                                },
                                error: function(XMLHttpRequest, textStatus, errorThrown) {
                                    console.log(errorThrown);
                                    alert(visa_acceptance_ajaxUCObj.error_failure);
                                    
                                },
                            });
                          
                        } catch (exception) {
                            console.log(exception);
                            alert(visa_acceptance_ajaxUCObj.error_failure);
                        }
                    }else {
                        if (data.checkoutRedirect) {
                            window.location.href = data.checkoutRedirect;
                        } else {
                            const currentURL = new URL(window.location.href);
                            let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                            window.location.href = decodeURIComponent(baseUrl);
                        }
                        // Redirecting to checkout page
                    }
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    alert(visa_acceptance_ajaxUCObj.error_failure);
                },
            });
        } catch (exception) {
            console.log(exception);
            alert(visa_acceptance_ajaxUCObj.error_failure);
        }
    }

    /**
     *  Step 3)Payer Authentication Enrollment Service
     */
    function enrollment(orderid, referenceId) {

        try {
            jQuery.ajax({
                type: "POST",
                url: payer_auth_param["admin_url"],
                cache: false,
                async: false,
                data: {
                    action: "wc_call_payer_auth_enrollment_action",
                    nonce: payer_auth_param["nonce_enrollment"],
                    cardtoken: cardtoken,
                    savedtoken: savedtoken,
                    tokenCheckbox: tokenCheckbox,
                    orderid: orderid,
                    referenceId: referenceId
                },
                success: function(data) {

                    if (data.status == "PENDING_AUTHENTICATION") {
                        var stepUpUrl = encodeURI(data.stepUpUrl);
                        var accessToken = encodeURIComponent(data.accessToken);
                        pareq = encodeURI(data.pareq);
                        var decodedPareqValue = window.atob(pareq);
                        var pareqJson = JSON.parse(decodedPareqValue);
                        var challengeWindowSize = pareqJson.challengeWindowSize;
                        const { width, height } = challengeWindowSizeMap[challengeWindowSize] || challengeWindowSizeMap['06'];

                        if (document.querySelector("#order_review") != null) {
                            if (document.querySelector("#step-up-form") == null) {
                                var modalContainer = document.createElement('div');
                                modalContainer.id = 'modal-container';
                                modalContainer.style.display = 'none';

                                // Create the modal content div
                                var modalContent = document.createElement('div');
                                modalContent.id = 'modal-content';

                                // Create the iframe element
                                var iframe = document.createElement('iframe');
                                iframe.id = 'step-up-iframe-id';
                                iframe.name = 'step-up-iframe';

                                // Create the form element
                                var form = document.createElement('form');
                                form.id = 'step-up-form';
                                form.target = 'step-up-iframe';
                                form.method = 'post';
                                form.action = '/'; // Replace with the actual action URL

                                // Create hidden input for accessToken
                                var accessTokenInput = document.createElement('input');
                                accessTokenInput.type = 'hidden';
                                accessTokenInput.id = 'accessToken';
                                accessTokenInput.name = 'JWT';
                                accessTokenInput.value = ''; // Set the initial value if needed

                                // Create hidden input for merchantData
                                var merchantDataInput = document.createElement('input');
                                merchantDataInput.type = 'hidden';
                                merchantDataInput.id = 'merchantData';
                                merchantDataInput.name = 'MD';
                                merchantDataInput.value = ''; // Set the initial value if needed

                                // Append elements to the DOM
                                form.appendChild(accessTokenInput);
                                form.appendChild(merchantDataInput);
                                modalContent.appendChild(iframe);
                                modalContent.appendChild(form);
                                modalContainer.appendChild(modalContent);
                                document.body.appendChild(modalContainer);
                            }
                        }
                        // Updating form
                        jQuery('#step-up-iframe-id').attr({width, height});
                        jQuery("#step-up-form").attr("action", stepUpUrl);
                        jQuery("#accessToken").val(accessToken);

                        var stepupForm = document.querySelector("#step-up-form");
                        if (stepupForm) {
                            showPopup();
                            stepupForm.submit();
                        }
                    } else {
                        if(data.sca && data.sca == 'yes'){
                            //sca_flag = true;
                            var sca_form = document.createElement('input');
                            sca_form.setAttribute("id", "sca_form");
                            sca_form.setAttribute("type", "hidden");
                            sca_form.setAttribute("value", "true");
                            document.body.appendChild(sca_form);
                            setup(order_id);
                        } else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null && data.status !== 'AUTHORIZED' && data.status !== 'AUTHORIZED_PENDING_REVIEW'){
                            const redirection_url = data.redirect;
                            const actual_data = data;
                            try {
                                jQuery.ajax({
                                    type: "POST",
                                    url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                    cache: false,
                                    async: false,
                                    data: {
                                        action: "wc_call_uc_payer_auth_error_handler",
                                        nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"],
                                    },
                                    success: function(data) {
                                        hideLoader();
                                        window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                        window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                            actual_data.error,
                                            {id:"checkout",context:"wc/checkout"}
                                        );
                                    reloadAfterError();
                                    },
                                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                                        console.log(errorThrown);
                                        alert(visa_acceptance_ajaxUCObj.error_failure);
                                    },
                                });
                            } catch (exception) {
                                console.log(exception);
                                alert(visa_acceptance_ajaxUCObj.error_failure);
                            }
                        } else {
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                const currentURL = new URL(window.location.href);
                                let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                                window.location.href = decodeURIComponent(baseUrl);
                            }
                        }
                    }
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    alert(visa_acceptance_ajaxUCObj.error_failure);
                    reloadAfterError();

                },
            });
        } catch (exception) {
            console.log(exception);
            alert(visa_acceptance_ajaxUCObj.error_failure);
        }
    }

    /**
     *  Step 3)Payer Authentication Enrollment Service
     */
    function enrollment_uc(orderid, referenceId, transientToken, sca_flag) {
        try {
            jQuery.ajax({
                type: "POST",
                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                cache: false,
                async: false,
                data: {
                    action: "wc_call_uc_payer_auth_enrollment_action",
                    nonce: visa_acceptance_uc_payer_auth_param["nonce_enrollment"],
                    cardtoken: transientToken,
                    savedtoken: savedtoken,
                    tokenCheckbox: tokenCheckbox,
                    orderid: orderid,
                    referenceId: referenceId,
                    scaCase: sca_flag
                },
                success: function(data) {

                    if (data.status == "PENDING_AUTHENTICATION") {
                        var stepUpUrl = encodeURI(data.stepUpUrl);
                        var accessToken = encodeURIComponent(data.accessToken);
                        pareq = encodeURI(data.pareq);
                        var decodedPareqValue = window.atob(pareq);
                        var pareqJson = JSON.parse(decodedPareqValue);
                        var challengeWindowSize = pareqJson.challengeWindowSize;
                        const { width, height } = challengeWindowSizeMap[challengeWindowSize] || challengeWindowSizeMap['06'];
                        enrollment_uc_flag = true;
                        if (document.querySelector("#order_review") != null) {
                            if (document.querySelector("#step-up-form") == null) {
                                var modalContainer = document.createElement('div');
                                modalContainer.id = 'modal-container';
                                modalContainer.style.display = 'none';

                                // Create the modal content div
                                var modalContent = document.createElement('div');
                                modalContent.id = 'modal-content';

                                // Create the iframe element
                                var iframe = document.createElement('iframe');
                                iframe.id = 'step-up-iframe-id';
                                iframe.name = 'step-up-iframe';

                                // Create the form element
                                var form = document.createElement('form');
                                form.id = 'step-up-form';
                                form.target = 'step-up-iframe';
                                form.method = 'post';
                                form.action = '/'; // Replace with the actual action URL

                                // Create hidden input for accessToken
                                var accessTokenInput = document.createElement('input');
                                accessTokenInput.type = 'hidden';
                                accessTokenInput.id = 'accessToken';
                                accessTokenInput.name = 'JWT';
                                accessTokenInput.value = ''; // Set the initial value if needed

                                // Create hidden input for merchantData
                                var merchantDataInput = document.createElement('input');
                                merchantDataInput.type = 'hidden';
                                merchantDataInput.id = 'merchantData';
                                merchantDataInput.name = 'MD';
                                merchantDataInput.value = ''; // Set the initial value if needed

                                // Append elements to the DOM
                                form.appendChild(accessTokenInput);
                                form.appendChild(merchantDataInput);
                                modalContent.appendChild(iframe);
                                modalContent.appendChild(form);
                                modalContainer.appendChild(modalContent);
                                document.body.appendChild(modalContainer);
                            }
                        }
                        // Updating form
                        jQuery('#step-up-iframe-id').attr({width, height});
                        jQuery("#step-up-form").attr("action", stepUpUrl);
                        jQuery("#accessToken").val(accessToken);

                        var stepupForm = document.querySelector("#step-up-form");
                        if (stepupForm) {

                            showPopup();
                            stepupForm.submit();
                        }
                    } else {
                        if(data.sca && data.sca == 'yes'){
                            //sca_flag = true;
                            var sca_form = document.createElement('input');
                            sca_form.setAttribute("id", "sca_form");
                            sca_form.setAttribute("type", "hidden");
                            sca_form.setAttribute("value", "true");
                            document.body.appendChild(sca_form);
                            var transientToken = jQuery("#transientToken").val();
                            setup_uc(order_id, transientToken);
                        } 
                        else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null && data.status !== 'AUTHORIZED' && data.status !== 'AUTHORIZED_PENDING_REVIEW'){
                            const redirection_url = data.redirect;
                            const actual_data = data;
                            try {
                                jQuery.ajax({
                                    type: "POST",
                                    url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                    cache: false,
                                    async: false,
                                    data: {
                                        action: "wc_call_uc_payer_auth_error_handler",
                                        nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"],
                                    },
                                    success: function(data) {
                                        hideLoader();
                                        window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                        window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                            actual_data.error,
                                            {id:"checkout",context:"wc/checkout"}
                                        );
                                    reloadAfterError();
                                    },
                                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                                        console.log(errorThrown);
                                        alert(visa_acceptance_ajaxUCObj.error_failure);
                                    },
                                });
                            } catch (exception) {
                                console.log(exception);
                                alert(visa_acceptance_ajaxUCObj.error_failure);
                            }
                        } else {
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                const currentURL = new URL(window.location.href);
                                let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                                window.location.href = decodeURIComponent(baseUrl);

                            }
                        }
                    }
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    alert(visa_acceptance_ajaxUCObj.error_failure);
                    reloadAfterError();

                },
            });
        } catch (exception) {
            console.log(exception);
            alert(visa_acceptance_ajaxUCObj.error_failure);
        }
    }

    // Saves tokens such as saved cards token, flex token and saved card checkbox
    function fetchTokens() {
        var useNewCardlength = null;
        cardtoken = null;
        savedtoken = null;
        tokenCheckbox = null;

        useNewCardlength = jQuery("#wc-visa-acceptance-solutions-credit-card-use-new-payment-method").length;
        if (useNewCardlength != 0) {
            // Saving the Card token(Stored or From Flex) based on the radio button selected

            // if flex radio button is selected
            if ($("#wc-visa-acceptance-solutions-credit-card-use-new-payment-method").is(":checked")) {
                // flex token
                if (jQuery("#wc-credit-card-flex-token").length != 0) {
                    cardtoken = jQuery("#wc-credit-card-flex-token").val();
                }
            } else {
                // saved card token
                savedtoken = jQuery('input[name="wc-visa-acceptance-solutions-credit-card-payment-token"]:checked').val();
            }
        } else {
            if (blockstoken) {
                // saved card token from blocks UI
                savedtoken = blockstoken;
            }
            // flex token
            else if (jQuery("#wc-credit-card-flex-token").length != 0) {
                cardtoken = jQuery("#wc-credit-card-flex-token").val();
            }
        }

        if (null != cardtoken) {
            jQuery("#wc-credit-card-flex-token").val(null);
        } else if (null != savedtoken) {
            jQuery('input[name="wc-visa-acceptance-solutions-credit-card-payment-token"]:checked').val(null);
        }

        if (jQuery('#wc-credit-card-tokenize-payment-method').length) {
            if (jQuery('#wc-credit-card-tokenize-payment-method').prop('checked')) {

                // Checkbox is checked
                tokenCheckbox = "yes";
            } else {
                // Checkbox is not checked
                tokenCheckbox = "no";
            }
        }

    }

    function fetchTokens_uc() {
        var useNewCardlength = null;
        cardtoken = null;
        savedtoken = null;
        tokenCheckbox = null;

        useNewCardlength = jQuery("#wc-visa-acceptance-solutions-unified-checkout-use-new-payment-method").length;
        if (useNewCardlength != 0) {
            // Saving the Card token(Stored or From Flex) based on the radio button selected

            // if flex radio button is selected
            if ($("#wc-visa-acceptance-solutions-unified-checkout-use-new-payment-method").is(":checked")) {
                // flex token
                if (jQuery("#transientToken").length != 0) {
                    cardtoken = jQuery("#transientToken").val();
                }
            } else {
                // saved card token
                savedtoken = jQuery('input[name="wc-visa-acceptance-solutions-unified-checkout-payment-token"]:checked').val();
            }
        } else {
            if (blockstoken) {
                // saved card token from blocks UI
                savedtoken = blockstoken;
            }
            // flex token
            else if (jQuery("#transientToken").length != 0) {
                cardtoken = jQuery("#transientToken").val();
            }
        }

        if (null != cardtoken) {
            //jQuery( "#transientToken" ).val( null );
        } else if (null != savedtoken) {
            jQuery('input[name="wc-visa-acceptance-solutions-unified-checkout-payment-token"]:checked').val(null);
        }

        if (jQuery('#wc-unified-checkout-tokenize-payment-method').length) {
            if (jQuery('#wc-unified-checkout-tokenize-payment-method').prop('checked')) {

                // Checkbox is checked
                tokenCheckbox = "yes";
            } else {
                // Checkbox is not checked
                tokenCheckbox = "no";
            }
        }

    }

    // Added this to call fetchTokens and setup from a global context
    window.myUtils = {
        fetchTokens: fetchTokens,
        setup: setup,
        setup_uc: setup_uc,
        fetchTokens_uc: fetchTokens_uc
    };

})(jQuery);


/**
 *
 * Step 4-a)Payer Auth Validation Service
 * Data such as Auth ID, Order ID and Pareq is passsed in it
 */
function validation(authid, orderid, pareq) {

    try {
        jQuery.ajax({
            type: "POST",
            url: payer_auth_param["admin_url"],
            cache: false,
            async: false,
            data: {
                action: "wc_call_payer_auth_validation_action",
                nonce: payer_auth_param["nonce_validation"],
                cardtoken: cardtoken,
                savedtoken: savedtoken,
                tokenCheckbox: tokenCheckbox,
                orderid: orderid,
                authid: authid,
                pareq: pareq
            },
            success: function(data) {

                if (data.status == "AUTHORIZED" ||
                    data.status == "AUTHORIZED_PENDING_REVIEW") {
                    // redirect to order completion page
                    window.location.href = data.redirect;
                } else {
                    if(data.sca && data.sca == 'yes'){
                        var sca_form = document.createElement('input');
                        sca_form.setAttribute("id", "sca_form");
                        sca_form.setAttribute("type", "hidden");
                        sca_form.setAttribute("value", "true");
                        document.body.appendChild(sca_form);
                        myUtils.setup(orderid);
                    } else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null){
                        const redirection_url = data.redirect;
                        const actual_data = data;
                        try {
                            jQuery.ajax({
                                type: "POST",
                                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                cache: false,
                                async: false,
                                data: {
                                    action: "wc_call_uc_payer_auth_error_handler",
                                    nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"],
                                },
                                success: function(data) {
                                    hideLoader();
                                    window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                    window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                        actual_data.error,
                                        {id:"checkout",context:"wc/checkout"}
                                    );
                                    reloadAfterError();
                                },
                                error: function(XMLHttpRequest, textStatus, errorThrown) {
                                    console.log(errorThrown);
                                    alert(visa_acceptance_ajaxUCObj.error_failure);
                                },
                            });
                        } catch (exception) {
                            console.log(exception);
                            alert(visa_acceptance_ajaxUCObj.error_failure);
                        }
                    } else {
                        // redirect to checkout page
                        if (data.redirect) {
                            // redirect to checkout page
                            window.location.href = data.redirect;
                        } else {
                            const currentURL = new URL(window.location.href);
                            let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                            window.location.href = decodeURIComponent(baseUrl);
                        }
                    }
                }
            },
            error: function(XMLHttpRequest, textStatus, errorThrown) {
                console.log(errorThrown);
                alert(visa_acceptance_ajaxUCObj.error_failure);
                reloadAfterError();
            },
        });
    } catch (exception) {
        console.log(exception);
        alert(visa_acceptance_ajaxUCObj.error_failure);
    }
}

/**
 *
 * Step 4-a)Payer Auth Validation Service
 * Data such as Auth ID, Order ID and Pareq is passsed in it
 */
function validation_uc(authid, orderid, pareq, transientToken, sca_flag) {

    try {
        jQuery.ajax({
            type: "POST",
            url: visa_acceptance_uc_payer_auth_param["admin_url"],
            cache: false,
            async: false,
            data: {
                action: "wc_call_uc_payer_auth_validation_action",
                nonce: visa_acceptance_uc_payer_auth_param["nonce_validation"],
                cardtoken: transientToken,
                savedtoken: savedtoken,
                tokenCheckbox: tokenCheckbox,
                orderid: orderid,
                authid: authid,
                pareq: pareq,
                scaCase: sca_flag,
            },
            success: function(data) {

                if (data.status == "AUTHORIZED" ||
                    data.status == "AUTHORIZED_PENDING_REVIEW") {
                    // redirect to order completion page
                    window.location.href = data.redirect;
                } else {
                    if(data.sca && data.sca == 'yes'){
                        var sca_form = document.createElement('input');
                        sca_form.setAttribute("id", "sca_form");
                        sca_form.setAttribute("type", "hidden");
                        sca_form.setAttribute("value", "true");
                        document.body.appendChild(sca_form);
                        var transientToken = jQuery("#transientToken").val();
                        myUtils.setup_uc(orderid, transientToken);
                    }

                    //Approach 1: Tried AJAX approach by moving the flow from JS to process_payment
                    else if(typeof ucBlocksSettings !== 'undefined' && document.querySelector("#order_review") == null){
                        const redirection_url = data.redirect;
                        const actual_data = data;
                        try {
                            jQuery.ajax({
                                type: "POST",
                                url: visa_acceptance_uc_payer_auth_param["admin_url"],
                                cache: false,
                                async: false,
                                data: {
                                    action: "wc_call_uc_payer_auth_error_handler",
                                    nonce: visa_acceptance_uc_payer_auth_param["nonce_error_handler"],
                                },
                                success: function(data) {
                                    hideLoader();
                                    window.wp.data.dispatch(window.wc.wcBlocksData.CHECKOUT_STORE_KEY).__internalSetIdle(true);
                                    window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
                                        actual_data.error,
                                        // Handle place to display error message checkout page.
                                        {id:"checkout",context:"wc/checkout"}
                                    );
                                    reloadAfterError();
                                },
                                error: function(XMLHttpRequest, textStatus, errorThrown) {
                                    console.log(errorThrown);
                                    alert(visa_acceptance_ajaxUCObj.error_failure);
                                },
                            });
                        } catch (exception) {
                            console.log(exception);
                            alert(visa_acceptance_ajaxUCObj.error_failure);
                        }
                    }
                    else{
                        // redirect to checkout page
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        } else {
                            const currentURL = new URL(window.location.href);
                            let baseUrl = encodeURIComponent(currentURL.origin + currentURL.pathname + currentURL.search);
                            window.location.href = decodeURIComponent(baseUrl);
                        }
                    }
                }
            },
            error: function(XMLHttpRequest, textStatus, errorThrown) {
                console.log(errorThrown);
                alert(visa_acceptance_ajaxUCObj.error_failure);
                reloadAfterError();
            },
        });
    } catch (exception) {
        console.log(exception);
        alert(visa_acceptance_ajaxUCObj.error_failure);
    }
}

/**
 * Step 4) InvokeVaidation function is called from custom endpoint callback fuction
 *         Data such as Auth ID and Order ID is passed here
 *         Validation service is called here
 *
 * Step 4-a)Payer Auth Validation Service
 *          Data such as Auth ID, Order ID and Pareq is passsed in it
 */
function invokeValidation(authid) {

    hidePopup();
    if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && (jQuery("#transientToken").val() != undefined && jQuery("#transientToken").val() != '')) {
        var transientToken = jQuery("#transientToken").val();
        if(jQuery('#sca_validation_form').val() == 'true' || jQuery('#sca_form').val() == 'true'){
            validation_uc(authid, order_id, pareq, transientToken, 'yes');
        } else{
            validation_uc(authid, order_id, pareq, transientToken, 'no');
        }
        
    } else if (enrollment_uc_flag && transientToken == undefined) {
        var transientToken = jQuery("#transientToken").val();
        if(jQuery('#sca_validation_form').val() == 'true' || jQuery('#sca_form').val() == 'true'){
            validation_uc(authid, order_id, pareq, transientToken, 'yes');
        } else{
            validation_uc(authid, order_id, pareq, transientToken, 'no');
        }
    } else {
        validation(authid, order_id, pareq);
    }


}

jQuery('form#order_review').on(
    'submit',
    function(e) {
        if ($('#wc_credit_card_getorderid').val() == 'get_order_id' || flag) {
            return true;
        }
    }
);

// Adding Custom Function for getOrderID to handle at admin order
function getOrderIDPayPage(orderid) {

    // Setting Hidden field wc_credit_card_getorderid
    jQuery('<input>').attr({
        type: 'hidden',
        id: 'wc_credit_card_getorderid',
        name: 'wc_credit_card_getorderid',
        value: 'get_order_id'
    }).appendTo('form#order_review');

    // hide the loader as we are going to submit the button and it's going to trigger WordPress built in loader
    hideLoader();

    // Initialized the global order_id with parameter recieved
    order_id = orderid;

    // Using myUtils to call these methods
    //myUtils.fetchTokens();

    // Step 2) Payer Auth Setup call After getting the order ID
    if (typeof visa_acceptance_uc_payer_auth_param !== 'undefined' && visa_acceptance_uc_payer_auth_param['payment_method'] == 'unified_checkout' && (jQuery("#transientToken").val() != undefined && jQuery("#transientToken").val() != '')) {
        var transientToken = jQuery("#transientToken").val();
        myUtils.fetchTokens_uc();

        myUtils.setup_uc(orderid, transientToken);
    } else if (jQuery('input[name="wc-visa-acceptance-solutions-unified-checkout-payment-token"]:checked').val() !== null) {
        var transientToken = jQuery("#transientToken").val();
        myUtils.fetchTokens_uc();

        myUtils.setup_uc(orderid, transientToken);
    } else {
        myUtils.fetchTokens();
        myUtils.setup(orderid);
    }


    jQuery('form#order_review').submit();

}

// Step 1-a) Get Order ID as soon as place order clicked
function getOrderID() {

    // Setting Hidden field wc_credit_card_getorderid
    jQuery('<input>').attr({
        type: 'hidden',
        id: 'wc_credit_card_getorderid',
        name: 'wc_credit_card_getorderid',
        value: 'get_order_id'
    }).appendTo('form.checkout.woocommerce-checkout');

    // hide the loader as we are going to submit the button and it's going to trigger WordPress built in loader
    hideLoader();

    // Submitting Checkout button
    jQuery('form.checkout.woocommerce-checkout').submit();
}




function addLoader() {
    var loaderHtml = '<div id="loader" style="display:none;">' +
        '</div>';
    jQuery('body').append(loaderHtml);
}

function showLoader() {
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

function isLoaderOnDisplay() {
    var loader = $("#loader");
    if (loader.length) {
        if (loader.style.display == "block") {
            return true;
        } else {
            return false;
        }
    }
}

function hideLoader() {

    document.getElementById("loader").style.display = "none";
}

function showPopup() {
    const modalContainer = document.getElementById("modal-container");
    modalContainer.style.display = "flex";
    const topWindow = window.top;
    topWindow.document.body.appendChild(modalContainer);
}

function hidePopup() {

    const modalContainer = document.getElementById("modal-container");
    modalContainer.style.display = "none";
}
