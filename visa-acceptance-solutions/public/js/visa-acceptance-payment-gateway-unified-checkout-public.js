(function ($) {
  "use strict";

  var isEventTriggered = false;
  var _checkoutHasUpdated = false;
  var isExpressPaySubmission = false;
  var _placeOrderAttempted = false;

  // TMS token UC widget state.
  var tmsCheckoutClient = null;
  var tmsCheckoutInstance = null;
  var ccUcClient = null;
  var ccUcCheckout = null;
  var ccMountSeq = 0;
  var epUcClient = null;
  var epUcCheckout = null;
  var epMountSeq = 0;
  var _latestEpCaptureContext = null;
  function debounce(fn, wait) {
    var timeoutId;
    return function () {
      var context = this,
        args = arguments;
      clearTimeout(timeoutId);
      timeoutId = setTimeout(function () {
        fn.apply(context, args);
      }, wait);
    };
  }

  function destroyCcUcCheckout() {
    try {
      if (ccUcCheckout) {
        ccUcCheckout.destroy();
      }
      if (ccUcClient) {
        ccUcClient.destroy();
      }
    } catch (e) {
      /* ignore */
    }
    ccUcCheckout = null;
    ccUcClient = null;
  }

  function destroyEpUcCheckout() {
    try {
      if (epUcCheckout) {
        epUcCheckout.destroy();
      }
      if (epUcClient) {
        epUcClient.destroy();
      }
    } catch (e) {
      /* ignore */
    }
    epUcCheckout = null;
    epUcClient = null;
  }

  // JWT/capture-context values are always dot-separated base64url segments; reject
  // anything outside that charset before it is ever written to the DOM.
  function sanitizeTokenValue(value) {
    if (typeof value !== "string") {
      return "";
    }
    return /^[A-Za-z0-9_\-.=]+$/.test(value) ? value : "";
  }

  // Update or create a hidden input field with a sanitized token value.
  function updateHiddenTokenField(id, value) {
    var safeValue = sanitizeTokenValue(value);
    var el = document.getElementById(id);
    if (!el) {
      el = document.createElement("input");
      el.type = "hidden";
      el.id = id;
      document.body.appendChild(el);
    }
    el.value = safeValue;
    return safeValue;
  }

  // Dynamically load the Unified Checkout (UC) client library script via a same-origin admin-ajax proxy.
  function loadUCLibrary(mode, integrity) {
    return new Promise(function (resolve, reject) {
      var nonce =
        typeof visa_acceptance_ajaxUCObj !== "undefined"
          ? visa_acceptance_ajaxUCObj["uc_library_proxy_nonce"]
          : "";
      var ajaxUrl =
        typeof visa_acceptance_ajaxUCObj !== "undefined"
          ? visa_acceptance_ajaxUCObj["ajax_url"]
          : "";
      if (!nonce || !ajaxUrl) {
        reject(new Error("Invalid UC library proxy configuration"));
        return;
      }
      var safeMode = mode === "saved_card" ? "saved_card" : "cc";
      var separator = ajaxUrl.indexOf("?") === -1 ? "?" : "&";
      var proxyUrl =
        ajaxUrl +
        separator +
        "action=vas_uc_library_proxy&mode=" +
        safeMode +
        "&_wpnonce=" +
        encodeURIComponent(nonce);

      // Check if script already exists
      var existingScript = document.getElementById("unified-checkout-library");
      if (existingScript) {
        existingScript.remove();
      }

      var script = document.createElement("script");
      script.id = "unified-checkout-library";
      script.src = proxyUrl;
      if (integrity) {
        script.integrity = integrity;
        script.crossOrigin = "anonymous";
      }
      script.onload = function () {
        resolve();
      };
      script.onerror = function () {
        reject(new Error("Failed to load UC library"));
      };
      document.head.appendChild(script);
    });
  }

  // Collect and send browser data for 3DS device information.
  function collectBrowserData() {
    if (typeof visa_acceptance_ajaxUCObj === "undefined") {
      return;
    }

    var browserData = {
      action: "store_browser_data",
      nonce: visa_acceptance_ajaxUCObj.store_browser_data_nonce || "",
      gateway_id: visa_acceptance_ajaxUCObj.gateway_id || "",
      screen_height: window.screen.height,
      screen_width: window.screen.width,
      color_depth: window.screen.colorDepth,
      tz_offset: new Date().getTimezoneOffset(),
      java_enabled: navigator.javaEnabled ? navigator.javaEnabled() : false,
      js_enabled: true,
    };

    $.ajax({
      url: visa_acceptance_ajaxUCObj.ajax_url,
      type: "POST",
      data: browserData,
      async: false,
    });
  }

  // Collect browser data on page load.
  $(document).ready(function () {
    collectBrowserData();
    var isPaymentMethodSelected = $(
      "input[name$='payment_method'][value$='" +
        visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
        "']",
    ).is(":checked");
    var isOrderReviewPage = $("form#order_review").length > 0;
    var isAddPaymentMethodPage = $("form#add_payment_method").length > 0;
    var isAdminOrderPage =
      (typeof visa_acceptance_ajaxUCObj !== "undefined" &&
        visa_acceptance_ajaxUCObj["is_admin_order_page"]) ||
      $("body").hasClass("post-type-shop_order") ||
      $("body").hasClass("post-type-shop_subscription");
    var isChangePaymentPage =
      (typeof visa_acceptance_ajaxUCObj !== "undefined" &&
        visa_acceptance_ajaxUCObj["is_change_payment_method"]) ||
      window.location.href.indexOf("change_payment_method") !== -1 ||
      window.location.pathname.indexOf("/change-payment-method/") !== -1;

    if (
      (isOrderReviewPage ||
        isAddPaymentMethodPage ||
        isAdminOrderPage ||
        isChangePaymentPage) &&
      isPaymentMethodSelected
    ) {
      handlePaymentSelection({ type: "ready" });
    }
  });

  jQuery(document).on("wc_checkout_form_error", function () {
    jQuery("form.checkout")
      .off("submit", handleFormSubmission)
      .on("submit", handleFormSubmission);
    // Show payment form back when there's a validation error after express pay click.
    hideExpressPayLoader();
    jQuery(".woocommerce-checkout-payment").show();
  });

  jQuery(document.body).on("checkout_error", function () {
    if (!_placeOrderAttempted) {
      return;
    }
    _placeOrderAttempted = false;
    // Note: previously forced a location.reload() here for new-card errors, which wiped the
    // error notice WooCommerce had just rendered (notices are flushed server-side after being
    // printed once, so the reloaded page showed no message). No reload needed to recover here.
  });
  jQuery(document.body).on("checkout_error", function () {
    setTimeout(function () {
      window.location.reload();
    }, 1500);
  });

  // Collects the billing address from the classic (non-block) checkout form fields.
  function collectClassicCheckoutBillingAddress() {
    var fieldMap = {
      first_name: "#billing_first_name",
      last_name: "#billing_last_name",
      company: "#billing_company",
      address_1: "#billing_address_1",
      address_2: "#billing_address_2",
      city: "#billing_city",
      state: "#billing_state",
      postcode: "#billing_postcode",
      country: "#billing_country",
      email: "#billing_email",
      phone: "#billing_phone",
    };
    var billingAddress = {};
    var hasValue = false;
    jQuery.each(fieldMap, function (field, selector) {
      var el = jQuery(selector);
      if (el.length) {
        var val = el.val();
        if (val) {
          hasValue = true;
        }
        billingAddress[field] = val || "";
      }
    });
    return hasValue ? billingAddress : null;
  }

  // Decodes the order info (billTo/amountDetails) from a capture context JWT. Returns null if the capture context is invalid or cannot be decoded.
  function decodeCaptureContextOrderInfo(captureContext) {
    if (typeof captureContext !== "string") {
      return null;
    }
    try {
      var parts = captureContext.split(".");
      if (parts.length !== 3) {
        return null;
      }
      var payload = JSON.parse(
        atob(parts[1].replace(/-/g, "+").replace(/_/g, "/")),
      );
      var ctxData =
        payload && payload.ctx && payload.ctx[0] && payload.ctx[0].data;
      return (ctxData && ctxData.orderInformation) || null;
    } catch (e) {
      return null;
    }
  }

  // Checks if the billing address in the capture context is stale compared to the live form values.
  function isCaptureContextAddressStale(captureContext) {
    var $billingAddress1 = jQuery("#billing_address_1");
    var $billingCity = jQuery("#billing_city");
    var liveAddress1 = $billingAddress1.length ? $billingAddress1.val() : "";
    var liveCity = $billingCity.length ? $billingCity.val() : "";
    if (!liveAddress1 && !liveCity) {
      return false;
    }
    var orderInfo = decodeCaptureContextOrderInfo(captureContext);
    var billTo = orderInfo && orderInfo.billTo;
    if (!billTo) {
      return true;
    }
    if (liveAddress1 && billTo.address1 !== liveAddress1) {
      return true;
    }
    if (liveCity && billTo.locality !== liveCity) {
      return true;
    }
    return false;
  }

  //Building Error Message.
  const errorMessagePara = document.createElement("p");
  errorMessagePara.setAttribute("style", "color:red");
  const errorMessageText = document.createTextNode(
    visa_acceptance_ajaxUCObj["form_load_error"],
  );
  errorMessagePara.appendChild(errorMessageText);
  // Guards the fallback "no captureContext yet" fetch inside handlePaymentSelection() against
  // duplicate concurrent API calls (see comment at the top of that function).
  var _ccCaptureContextFetchInFlight = false;

  var paymentMethodRow = $("tr.payment-method");
  var defaultPaymentMethodRow = $("tr.default-payment-method");
  var paymentMethodRowText = paymentMethodRow
    .find(
      "td.woocommerce-PaymentMethod.woocommerce-PaymentMethod--default.payment-method-default",
    )
    .text();
  var defaultPaymentMethodRowText = defaultPaymentMethodRow
    .find(
      "td.woocommerce-PaymentMethod.woocommerce-PaymentMethod--default.payment-method-default",
    )
    .text();

  jQuery(".woocommerce-MyAccount-paymentMethods").on(
    "click",
    ".woocommerce-PaymentMethod--actions .button.delete",
    function (e) {
      if (!confirm(visa_acceptance_ajaxUCObj["delete_card_text"])) {
        e.preventDefault();
        location.reload();
      }
      if (!navigator.onLine) {
        alert(visa_acceptance_ajaxUCObj["offline_text"]);
        e.preventDefault();
      }
    },
  );

  // Named function for form submission handling.
  async function handleFormSubmission(e) {
    _placeOrderAttempted = true;
    // Express pay bypasses all form validation (terms, CVV, etc.).
    if (isExpressPaySubmission) {
      isExpressPaySubmission = false;
      return true;
    }
    if (
      $(
        "input[name$='payment_method'][value$='" +
          visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
          "']",
      ).is(":checked")
    ) {
      var valueForTkn = jQuery(
        "input[name=wc-" +
          visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id_hyphen"] +
          "-payment-token]:checked",
      ).val();

      // If no token is selected, allow normal processing (new card).
      if (!valueForTkn) {
        var errorMessageEl = document.getElementById("errorMessage");
        if (errorMessageEl) {
          errorMessageEl.value = "no";
        }
        return true; // Allow form submission.
      }
      // TMS UC flow: widget completed and form is being submitted programmatically.
      if (window.tmsUcSubmissionInProgress) {
        window.tmsUcSubmissionInProgress = false;
        var errorMessageEl2 = document.getElementById("errorMessage");
        if (errorMessageEl2) {
          errorMessageEl2.value = "no";
        }
        return true;
      }
      // Check if token is eCheck - eCheck now goes through UC widget (no direct submission).
      var isEcheck =
        visa_acceptance_ajaxUCObj["token_type"] &&
        visa_acceptance_ajaxUCObj["token_type"][valueForTkn] === "eCheck";
      if (!visa_acceptance_ajaxUCObj["saved_card_cvv"] && !isEcheck) {
        var errorMessageEl4 = document.getElementById("errorMessage");
        if (errorMessageEl4) {
          errorMessageEl4.value = "no";
        }
        return true;
      }
      // CVV collected inside UC widget — block manual submission until UC completes.
      e.preventDefault();
      e.stopImmediatePropagation();
      return false;
    }
  }

  // Attach the named function to the form submit event.
  jQuery("form.checkout").on("submit", handleFormSubmission);

  if (
    paymentMethodRow.length &&
    defaultPaymentMethodRow.length &&
    paymentMethodRowText != defaultPaymentMethodRowText
  ) {
    defaultPaymentMethodRow
      .find(".woocommerce-PaymentMethod--actions .delete")
      .hide();
  }

  function addLoader() {
    var loaderHtml = '<div id="loader" style="display:none;">' + "</div>";
    jQuery("body").append(loaderHtml);
  }

  function showExpressPayLoader() {
    addLoader();
    const blockUIElement = document.querySelector(".blockUI.blockOverlay");
    if (blockUIElement) {
      var computedStyle = getComputedStyle(blockUIElement);
      var display = computedStyle.getPropertyValue("display");
      var visibility = computedStyle.getPropertyValue("visibility");
      if (display == "none" || visibility == "hidden") {
        var loader = document.getElementById("loader");
        if (loader) {
          loader.style.display = "block";
          const topWindow = window.top;
          topWindow.document.body.appendChild(loader);
        }
      }
    } else {
      var loader = document.getElementById("loader");
      if (loader) {
        loader.style.display = "block";
        const topWindow = window.top;
        topWindow.document.body.appendChild(loader);
      }
    }
  }

  function hideExpressPayLoader() {
    var loaderElement = document.getElementById("loader");
    if (loaderElement && loaderElement.style) {
      loaderElement.style.display = "none";
    }
  }

  function setCompleteResponsePayload(payload) {
    var serializedPayload = "";
    if (payload) {
      try {
        if (typeof payload === "string") {
          var trimmedPayload = payload.trim();
          if (
            (trimmedPayload.charAt(0) === "{" &&
              trimmedPayload.charAt(trimmedPayload.length - 1) === "}") ||
            (trimmedPayload.charAt(0) === "[" &&
              trimmedPayload.charAt(trimmedPayload.length - 1) === "]")
          ) {
            serializedPayload = trimmedPayload;
          } else {
            // Handle JWT responses — decode middle segment if it looks like a JWT.
            var jwtParts = trimmedPayload.split(".");
            if (jwtParts.length === 3) {
              try {
                var decoded = JSON.parse(atob(jwtParts[1]));
                serializedPayload = JSON.stringify(decoded);
              } catch (jwtErr) {
                serializedPayload = trimmedPayload;
              }
            } else {
              var parsedPayload = JSON.parse(trimmedPayload);
              serializedPayload =
                parsedPayload && typeof parsedPayload === "object"
                  ? JSON.stringify(parsedPayload)
                  : "";
            }
          }
        } else {
          serializedPayload = JSON.stringify(payload);
        }
      } catch (error) {
        serializedPayload = "";
      }
    }

    var forms = jQuery(
      "form.checkout, form#order_review, form#add_payment_method",
    );
    if (!forms.length) {
      forms = jQuery("form");
    }

    forms.each(function () {
      var $form = jQuery(this);
      var $field = $form.find('input[name="uc_complete_response"]');
      if (!$field.length) {
        $form.append(
          '<input type="hidden" name="uc_complete_response" value="">',
        );
        $field = $form.find('input[name="uc_complete_response"]');
      }
      $field.val(serializedPayload);
    });

    var globalField = document.getElementById("uc_complete_response");
    if (globalField) {
      globalField.value = serializedPayload;
    }
  }

  function completeAndContinue(up, transientToken) {
    return up
      .complete(transientToken)
      .then(function (completeResponse) {
        setCompleteResponsePayload(completeResponse);
      })
      .catch(function () {
        setCompleteResponsePayload(null);
      });
  }

  function populateCheckoutFieldsFromTransientToken(
    transientToken,
    isExpressPay,
  ) {
    return new Promise((resolve) => {
      var expressOnly = !!isExpressPay;

      if (!expressOnly) {
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
          action: "get_addresses_from_transient_token",
          transientToken: transientToken,
          nonce: visa_acceptance_ajaxUCObj["get_addresses_nonce"],
        },
        success: function (response) {
          if (response && response.success && response.data) {
            const billing = response.data.billing || {};
            const shipping = response.data.shipping || {};

            const billingMap = {
              first_name: "billing_first_name",
              last_name: "billing_last_name",
              company: "billing_company",
              address_1: "billing_address_1",
              address_2: "billing_address_2",
              city: "billing_city",
              state: "billing_state",
              postcode: "billing_postcode",
              country: "billing_country",
              email: "billing_email",
              phone: "billing_phone",
            };

            Object.keys(billingMap).forEach(function (key) {
              if (billing[key] !== undefined && billing[key] !== null) {
                const fieldName = billingMap[key];
                const $field = jQuery('[name="' + fieldName + '"]');
                if ($field.length) {
                  $field.val(billing[key]).trigger("change");
                }
              }
            });

            const shipDiffCheckbox = jQuery(
              "#ship-to-different-address-checkbox",
            );
            const shouldSetShipping = shipDiffCheckbox.length
              ? shipDiffCheckbox.is(":checked")
              : true;

            if (shouldSetShipping) {
              const shippingMap = {
                first_name: "shipping_first_name",
                last_name: "shipping_last_name",
                company: "shipping_company",
                address_1: "shipping_address_1",
                address_2: "shipping_address_2",
                city: "shipping_city",
                state: "shipping_state",
                postcode: "shipping_postcode",
                country: "shipping_country",
                phone: "shipping_phone",
              };

              Object.keys(shippingMap).forEach(function (key) {
                if (shipping[key] !== undefined && shipping[key] !== null) {
                  const fieldName = shippingMap[key];
                  const $field = jQuery('[name="' + fieldName + '"]');
                  if ($field.length) {
                    $field.val(shipping[key]).trigger("change");
                  }
                }
              });
            }

            jQuery(document.body).trigger("update_checkout");
          }
          resolve();
        },
        error: function () {
          resolve();
        },
      });
    });
  }

  function destroySavedCardCheckout() {
    try {
      if (tmsCheckoutInstance) {
        tmsCheckoutInstance.destroy();
      }
      if (tmsCheckoutClient) {
        tmsCheckoutClient.destroy();
      }
    } catch (e) {
      /* ignore */
    }
    tmsCheckoutInstance = null;
    tmsCheckoutClient = null;
    jQuery("#saved-card-pay-btn-container").html("").hide();
    jQuery("#saved-card-uc-form-container").html("").hide();
    jQuery("#saved-card-uc-error").hide();
    jQuery("#saved-card-uc-li-wrapper").hide(); // hide the <li> wrapper between cards and "Use a new card"
    jQuery("#place_order").hide();
  }

  async function mountSavedCardUcCheckout(tokenId, isEcheck) {
    destroySavedCardCheckout();

    var $newCardLi = jQuery(
      'input[name="wc-' +
        visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id_hyphen"] +
        '-payment-token"][value="new"]',
    ).closest("li");
    if (!$newCardLi.length) {
      $newCardLi = jQuery(".woocommerce-SavedPaymentMethods-new");
    }
    var $ucWrapper = jQuery("#saved-card-uc-li-wrapper");
    if (!$ucWrapper.length) {
      // First call: create the wrapper <li> and move containers inside it.
      $ucWrapper = jQuery(
        '<li id="saved-card-uc-li-wrapper" style="list-style:none;padding:0;"></li>',
      );
      $ucWrapper.append(jQuery("#saved-card-pay-btn-container").detach());
      $ucWrapper.append(jQuery("#saved-card-uc-form-container").detach());
      $ucWrapper.append(jQuery("#saved-card-uc-error").detach());
      if ($newCardLi.length) {
        $newCardLi.after($ucWrapper);
      } else {
        // Shortcode checkout fallback: no <li> structure — insert after "Use a new card"
        // div, or append to the payment box so containers remain in the DOM.
        var $newCardDiv = jQuery("#wc-credit-card-use-new-payment-method-div");
        if ($newCardDiv.length) {
          $newCardDiv.after($ucWrapper);
        } else {
          jQuery(
            ".payment_box.payment_method_" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
          ).append($ucWrapper);
        }
      }
    }
    $ucWrapper.show(); // was hidden by destroySavedCardCheckout

    jQuery("#saved-card-pay-btn-container").html("").show();
    jQuery(
      ".payment_box.payment_method_" +
        visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
    ).block({
      message: null,
      overlayCSS: { background: "#fff", opacity: 0.6 },
    });
    jQuery("#place_order").hide();

    jQuery.ajax({
      type: "POST",
      url: visa_acceptance_ajaxUCObj["ajax_url"],
      data: {
        action: "wc_get_saved_card_context",
        nonce: visa_acceptance_ajaxUCObj["get_saved_card_context_nonce"],
        token_id: tokenId,
      },
      success: async function (response) {
        jQuery(
          ".payment_box.payment_method_" +
            visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
        ).unblock();
        if (!response.success || !response.data || !response.data.jwt) {
          jQuery("#saved-card-pay-btn-container").html("").hide();
          jQuery("#saved-card-uc-error")
            .text(
              "Unable to load the payment form. Please contact customer care for any assistance.",
            )
            .show();
          return;
        }
        var jwt = response.data.jwt;
        jQuery("#saved-card-pay-btn-container").html("").show();
        jQuery("#saved-card-uc-form-container").show();

        try {
          // Dynamically load UC library if not already loaded. The real client library URL
          // is resolved server-side (from session) by the proxy endpoint, not read from
          // `response` here — only the SRI hash (not a script.src sink) comes from response.
          if (typeof VAS === "undefined") {
            await loadUCLibrary("saved_card", response.data.client_library_integrity);
          }

          tmsCheckoutClient = await VAS.UnifiedCheckout(jwt);
          tmsCheckoutInstance = await tmsCheckoutClient.createCheckout({
            autoProcessing: false,
          });

          // Embedded mode: capture context contains only TMS_TOKEN so UC renders
          // only the saved-card Pay button + CVV form, no other payment methods.
          var savedCardMountArgs = {
            paymentSelection: "#saved-card-pay-btn-container",
          };
          if (
            visa_acceptance_ajaxUCObj["checkout_display_mode"] === "embedded"
          ) {
            savedCardMountArgs.paymentScreen = "#saved-card-uc-form-container";
          }
          var tt = await tmsCheckoutInstance.mount(savedCardMountArgs);

          if (tt) {
            var completeResponse = await tmsCheckoutInstance.complete(tt);
            setCompleteResponsePayload(completeResponse);
            var $transientToken = jQuery("#transientToken");
            if ($transientToken.length) {
              $transientToken.val(tt);
            }
            var errorMessageEl3 = document.getElementById("errorMessage");
            if (errorMessageEl3) {
              errorMessageEl3.value = "no";
            }
            window.tmsUcSubmissionInProgress = true;
            // Find the appropriate form - includes support for modals, admin pages, and change payment pages
            var $form = null;
            if (jQuery("form.checkout").length) {
              $form = jQuery("form.checkout");
            } else if (jQuery("form#order_review").length) {
              $form = jQuery("form#order_review");
            } else if (jQuery("form#add_payment_method").length) {
              $form = jQuery("form#add_payment_method");
            } else if (jQuery("form.woocommerce-checkout").length) {
              $form = jQuery("form.woocommerce-checkout");
            } else if (jQuery('form[name="checkout"]').length) {
              $form = jQuery('form[name="checkout"]');
            } else {
              // Fallback: find any form containing the payment method input
              $form = jQuery('input[name="payment_method"]').closest("form");
            }
            if ($form && $form.length) {
              _placeOrderAttempted = true;
              $form.off("submit", handleFormSubmission);
              $form.removeClass("processing");
              $form.submit();
            }
          }
        } catch (err) {
          jQuery(
            ".payment_box.payment_method_" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
          ).unblock();
          jQuery("#saved-card-pay-btn-container").html("").hide();
          jQuery("#saved-card-uc-error")
            .text(
              "Unable to load the payment form. Please contact customer care for any assistance.",
            )
            .show();
          jQuery("#saved-card-uc-form-container").hide();
        }
      },
      error: function () {
        jQuery(
          ".payment_box.payment_method_" +
            visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
        ).unblock();
        jQuery("#saved-card-pay-btn-container").html("").hide();
        jQuery("#saved-card-uc-error")
          .text(
            "Unable to load the payment form. Please contact customer care for any assistance.",
          )
          .show();
      },
    });
  }

  // Check if Express Pay should be displayed based on browser support.
  function shouldShowExpressPay() {
    if (
      typeof visa_acceptance_ajaxUCObj === "undefined" ||
      !visa_acceptance_ajaxUCObj.enable_express_pay
    ) {
      return false;
    }
    return true;
  }

  function initExpressCheckout() {
    // Check if Express Pay should be shown based on browser and payment methods.
    if (!shouldShowExpressPay()) {
      destroyEpUcCheckout();
      jQuery("#wc-express-checkout-normal").hide();
      return;
    }
    var transientToken = document.getElementById("transientToken");
    var epJwtUpdatedEl = document.getElementById("ep_jwt_updated");
    var epJwtEl = document.getElementById("ep_jwt");
    var captureContext =
      _latestEpCaptureContext ||
      (epJwtUpdatedEl && epJwtUpdatedEl.value
        ? epJwtUpdatedEl.value
        : epJwtEl
          ? epJwtEl.value
          : null);
    if (typeof VAS !== "undefined" && captureContext) {
      jQuery("#wc-error-failure").hide();
      async function launchCheckout() {
        var mySeq = ++epMountSeq;
        destroyEpUcCheckout();
        let client;
        let checkout;

        try {
          client = await VAS.UnifiedCheckout(captureContext);
          if (mySeq !== epMountSeq) {
            client.destroy();
            return;
          }
          checkout = await client.createCheckout({ autoProcessing: false });
          if (mySeq !== epMountSeq) {
            checkout.destroy();
            client.destroy();
            return;
          }
          epUcClient = client;
          epUcCheckout = checkout;
          const tt = await checkout.mount("#expressPaymentListContainer");
          // Show express checkout div before rendering payment buttons
          jQuery("#wc-express-checkout-normal").show();
          const paymentMethodRadioId =
            "input[name='payment_method'][value='" +
            visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
            "']";
          if (!jQuery(paymentMethodRadioId).is(":checked")) {
            jQuery(paymentMethodRadioId).prop("checked", true).trigger("click");
          }
          const gatewayRadioId =
            "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method";
          jQuery(gatewayRadioId).prop("checked", true).trigger("click");

          // hide default payment method box.
          jQuery(".woocommerce-checkout-payment").hide();

          // show loader while processing.
          showExpressPayLoader();

          transientToken.value = tt;
          // Pass checkout as the "up" object — it has a .complete() method
          // that matches what completeAndContinue expects.
          return completeAndContinue(checkout, tt).then(function () {
            populateCheckoutFieldsFromTransientToken(tt, true).then(
              function () {
                // Find and submit the appropriate form for express pay
                if (jQuery("form#order_review").length > 0) {
                  _placeOrderAttempted = true;
                  jQuery("form#order_review").submit();
                } else if (jQuery("form#add_payment_method").length > 0) {
                  _placeOrderAttempted = true;
                  jQuery("form#add_payment_method").submit();
                } else {
                  var $termsCheckbox = jQuery('input[name="terms"]');
                  if ($termsCheckbox.length && !$termsCheckbox.is(":checked")) {
                    $termsCheckbox.prop("checked", true);
                  }
                  isExpressPaySubmission = true;
                  _placeOrderAttempted = true;
                  setTimeout(() => {
                    // Find the checkout form - support multiple form types
                    var $checkoutForm = null;
                    if (jQuery("form.checkout").length) {
                      $checkoutForm = jQuery("form.checkout");
                    } else if (jQuery("form.woocommerce-checkout").length) {
                      $checkoutForm = jQuery("form.woocommerce-checkout");
                    } else {
                      $checkoutForm = jQuery(
                        'input[name="payment_method"]',
                      ).closest("form");
                    }

                    if ($checkoutForm && $checkoutForm.length) {
                      $checkoutForm.removeClass("processing");
                      $checkoutForm.submit();
                    }
                  }, 200);
                  hideExpressPayLoader();
                }
              },
            );
          });
        } catch (error) {
          if (error.name === "UnifiedCheckoutError") {
            jQuery("#wc-error-failure").show();
            jQuery("#expressPaymentListContainer").hide();
            jQuery("#wc-express-checkout-normal").hide();
            // Show payment form back if express pay fails.
            hideExpressPayLoader();
            jQuery(".woocommerce-checkout-payment").show();
          }
          // Destroy session resources only on error to avoid tearing down
          // the checkout session before the form submission completes.
          if (checkout) {
            checkout.destroy();
          }
          if (client) {
            client.destroy();
          }
          if (epUcCheckout === checkout) {
            epUcCheckout = null;
          }
          if (epUcClient === client) {
            epUcClient = null;
          }
        }
      }
      launchCheckout();
    } else if (!captureContext) {
      destroyEpUcCheckout();
      jQuery("#wc-express-checkout-normal").hide();
      //jQuery('#express_payment_form_load_error').show();
    } else {
      jQuery("#wc-error-failure").show();
    }

    if (jQuery("#wc-express-checkout-section").is(":hidden")) {
      jQuery(".checkout #wc-error-failure #wc-failure-error").hide();
    }
  }
  // Listen for checkout validation errors and show payment form back.
  jQuery(document.body).on("checkout_error", function () {
    hideExpressPayLoader();
    jQuery(".woocommerce-checkout-payment").show();
  });
  var _epWidgetInitialized = false;
  var _epRefreshInFlight = false;
  function refreshAndInitExpressCheckout() {
    if (_epRefreshInFlight) {
      return;
    }
    _epRefreshInFlight = true;
    var isFirstLoad = !_epWidgetInitialized;

    if (isFirstLoad) {
      jQuery("#expressPaymentListContainer").html(
        '<div id="vas-ep-loader" style="text-align:center;padding:10px;"><span class="woocommerce-Price-amount" style="display:inline-block;width:24px;height:24px;border:5px solid #000000;border-top-color:#000000;border-radius:50%;animation:vas-spin 1s linear infinite;"></span></div>',
      );
      jQuery("#wc-express-checkout-normal").show();
      if (!document.getElementById("vas-ep-loader-style")) {
        var s = document.createElement("style");
        s.id = "vas-ep-loader-style";
        s.textContent = "@keyframes vas-spin{to{transform:rotate(360deg)}}";
        document.head.appendChild(s);
      }
    }
    jQuery.ajax({
      type: "POST",
      url: visa_acceptance_ajaxUCObj["ajax_url"],
      data: { action: "wc_call_uc_update_price_action", ep_only: "true" },
      nonce: visa_acceptance_ajaxUCObj["update_price_nonce"],
      success: function (response) {
        _epRefreshInFlight = false;
        jQuery("#wc-express-checkout-normal").unblock();
        // React to cart total changes (e.g. coupon apply/remove) without a full page reload.
        if (response && response.hide_express_pay) {
          jQuery("#wc-express-checkout-normal").hide();
        } else {
          jQuery("#wc-express-checkout-normal").show();
        }
        var hasNewJwt = !!(response && response.capture_context_ep_jwt);
        if (hasNewJwt) {
          _latestEpCaptureContext = response.capture_context_ep_jwt;
          updateHiddenTokenField("ep_jwt_updated", response.capture_context_ep_jwt);
        }
        if (isFirstLoad || hasNewJwt) {
          _epWidgetInitialized = true;
          jQuery("#vas-ep-loader").remove();
          initExpressCheckout();
        }
      },
      error: function () {
        _epRefreshInFlight = false;
        if (isFirstLoad) {
          jQuery("#vas-ep-loader").remove();
          initExpressCheckout();
        }
      },
    });
  }
  var debouncedRefreshAndInitExpressCheckout = debounce(
    refreshAndInitExpressCheckout,
    150,
  );
  if (visa_acceptance_ajaxUCObj["enable_express_pay"]) {
    jQuery(document.body).on(
      "updated_checkout",
      debouncedRefreshAndInitExpressCheckout,
    );
  }
  // Run Express Checkout initialization only on Pay for Order page.
  if (
    (window.location.href.indexOf("order-pay=") !== -1 ||
      window.location.pathname.indexOf("/order-pay/") !== -1) &&
    visa_acceptance_ajaxUCObj["enable_express_pay"]
  ) {
    initExpressCheckout();
  }

  async function launchUcCheckout(captureContext, transientTokenEl, mountArgs) {
    var mySeq = ++ccMountSeq;
    destroyCcUcCheckout();
    let client;
    let checkout;
    try {
      client = await VAS.UnifiedCheckout(captureContext);
      if (mySeq !== ccMountSeq) {
        client.destroy();
        return;
      }
      checkout = await client.createCheckout({ autoProcessing: false });
      if (mySeq !== ccMountSeq) {
        checkout.destroy();
        client.destroy();
        return;
      }
      ccUcClient = client;
      ccUcCheckout = checkout;
      const tt = await checkout.mount(mountArgs);
      transientTokenEl.value = tt;
      jQuery("body").css({ overflow: "auto" });
      await completeAndContinue(checkout, tt);
      await populateCheckoutFieldsFromTransientToken(tt, false);
      // Find and submit the appropriate form - supports all contexts
      _placeOrderAttempted = true;
      if (jQuery("form#order_review").length > 0) {
        jQuery("form#order_review").submit();
      } else if (jQuery("form#add_payment_method").length > 0) {
        jQuery("form#add_payment_method").submit();
      } else if (jQuery("form.checkout").length > 0) {
        jQuery("form.checkout").removeClass("processing");
        jQuery("form.checkout").submit();
      } else if (jQuery("form.woocommerce-checkout").length > 0) {
        jQuery("form.woocommerce-checkout").removeClass("processing");
        jQuery("form.woocommerce-checkout").submit();
      } else {
        // Fallback: find any form containing the payment method input
        var $fallbackForm = jQuery('input[name="payment_method"]').closest(
          "form",
        );
        if ($fallbackForm.length) {
          $fallbackForm.removeClass("processing");
          $fallbackForm.submit();
        }
      }
    } catch (error) {
      if (error.name === "UnifiedCheckoutError") {
        jQuery("#wc-error-failure").show();
        jQuery("#buttonPaymentListContainer").hide();
      }
      if (checkout) {
        checkout.destroy();
      }
      if (client) {
        client.destroy();
      }
      if (ccUcCheckout === checkout) {
        ccUcCheckout = null;
      }
      if (ccUcClient === client) {
        ccUcClient = null;
      }
    }
  }

  function handlePaymentSelection(event) {
    // Prevent re-entry if a capture-context fetch is already in progress.
    if (_ccCaptureContextFetchInFlight) {
      return;
    }

    // Hide any existing error messages when payment selection changes
    jQuery("#wc-error-failure").hide();

    // Remove both containers on every call so they are always re-created fresh.
    jQuery("#buttonPaymentListContainer").remove();

    // Remove any orphaned error message paragraphs
    if (
      typeof errorMessagePara !== "undefined" &&
      errorMessagePara.parentNode
    ) {
      errorMessagePara.parentNode.removeChild(errorMessagePara);
    }

    var buttonPaymentListContainer = $("<div>", {
      id: "buttonPaymentListContainer",
    });

    var tokencnt = visa_acceptance_ajaxUCObj["token_cnt"];
    var tknval = jQuery(
      "input[name=wc-" +
        visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id_hyphen"] +
        "-payment-token]:checked",
    ).val();
    var isEcheck =
      visa_acceptance_ajaxUCObj["token_type"] &&
      visa_acceptance_ajaxUCObj["token_type"][tknval] === "eCheck";
    if (visa_acceptance_ajaxUCObj["tokenization"] === "no") {
      // Force "use new card" option to be selected by default.
      jQuery("#wc-credit-card-use-new-payment-method-div").show();
      if (
        jQuery(
          "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method",
        ).length > 0
      ) {
        jQuery(
          "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method",
        ).prop("checked", true);
      }
      jQuery("#buttonPaymentListContainer").show();
      jQuery("#place_order").hide();
    } else {
      if (tokencnt != "0") {
        // Has saved cards - check which radio is selected
        if (
          jQuery(
            "#wc-" +
              visa_acceptance_ajaxUCObj[
                "visa_acceptance_solutions_uc_id_hyphen"
              ] +
              "-use-new-payment-method",
          ).is(":checked")
        ) {
          jQuery("#buttonPaymentListContainer").show();
          jQuery("#place_order").hide();
        } else {
          jQuery("#buttonPaymentListContainer").hide();
          jQuery(
            ".wc-payment-gateway-payment-form-manage-payment-methods",
          ).show();
          jQuery("#wc-credit-card-use-new-payment-method-div").show();
          jQuery("#place_order").hide();
          jQuery("#wc-unified-checkout-normal-save-token-div").hide();
        }
      } else {
        // No saved cards - "Use Other Payment Method" radio is checked by default
        // Show UC form directly
        jQuery("#wc-credit-card-use-new-payment-method-div").show();
        jQuery("#buttonPaymentListContainer").show();
        jQuery("#place_order").hide();
      }
    }
    // Hide CVV containers for ALL eCheck tokens on page load.
    if (visa_acceptance_ajaxUCObj["token_type"]) {
      jQuery.each(
        visa_acceptance_ajaxUCObj["token_type"],
        function (tokenId, tokenType) {
          if (tokenType === "eCheck") {
            jQuery("#token-" + tokenId).hide();
          }
        },
      );
    }

    // Mount UC TMS embedded checkout for the initially selected saved card or eCheck.
    if (tknval && !isEcheck) {
      mountSavedCardUcCheckout(tknval);
    } else if (tknval && isEcheck) {
      // eCheck saved card: load payment form via UC widget.
      mountSavedCardUcCheckout(tknval, true);
    }
    //Fetch token selected
    jQuery(
      "input[name=wc-" +
        visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id_hyphen"] +
        "-payment-token]",
    ).click(function () {
      // Hide any existing error messages when payment method selection changes
      jQuery("#wc-error-failure").hide();

      jQuery("#wc-unified-checkout-tokenize-payment-method").prop(
        "checked",
        false,
      );
      jQuery(".cvv-div").hide();
      jQuery(
        ".wc-unified-checkout-saved-card #wc-unified-checkout-saved-card-cvn",
      ).val("");

      //Fetch token selected.
      var tknval = jQuery(this).val();

      // Destroy previous UC instance then mount for newly selected card.
      var isEcheckToken =
        visa_acceptance_ajaxUCObj["token_type"] &&
        visa_acceptance_ajaxUCObj["token_type"][tknval] === "eCheck";
      destroySavedCardCheckout();
      if (tknval && !isEcheckToken) {
        mountSavedCardUcCheckout(tknval);
      } else if (tknval && isEcheckToken) {
        // eCheck: load payment form via UC widget.
        mountSavedCardUcCheckout(tknval, true);
      }
      jQuery(
        "input[name=wc-" +
          visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id_hyphen"] +
          "-payment-token]",
      )
        .not(":checked")
        .each(function () {
          var tknval = jQuery(this).val();
          jQuery("#token-" + encodeURI(tknval)).hide();
        });
      if (
        jQuery(
          "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method",
        ).is(":checked")
      ) {
        //Add at Checkout page below Use a new card.
        if (!jQuery("#buttonPaymentListContainer").length) {
          $("#wc-credit-card-use-new-payment-method-div").append(
            buttonPaymentListContainer,
          );
        }

        // Call credit card capture context API (similar to saved card TMS capture context)
        jQuery("#wc-error-failure").hide();
        jQuery(
          ".payment_box.payment_method_" +
            visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
        ).block({
          message: null,
          overlayCSS: { background: "#fff", opacity: 0.6 },
        });

        jQuery.ajax({
          type: "POST",
          url: visa_acceptance_ajaxUCObj["ajax_url"],
          data: (function () {
            var ajaxData = {
              action: "wc_call_uc_update_price_action",
              force_refresh: "true",
              cc_only: "true",
              nonce: visa_acceptance_ajaxUCObj["update_price_nonce"],
            };
            var billingAddress = collectClassicCheckoutBillingAddress();
            if (billingAddress) {
              ajaxData.billing_address = JSON.stringify(billingAddress);
            }
            return ajaxData;
          })(),
          success: async function (response) {
            jQuery(
              ".payment_box.payment_method_" +
                visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
            ).unblock();

            if (response && response.success && response.capture_context_jwt) {
              var captureContext = updateHiddenTokenField(
                "jwt_updated",
                response.capture_context_jwt,
              );
              var transientToken = document.getElementById("transientToken");
              if (typeof VAS === "undefined") {
                try {
                  await loadUCLibrary("cc", response.client_library_integrity);
                } catch (err) {
                  console.error("Failed to load UC library:", err);
                  jQuery("#wc-error-failure").show();
                  return;
                }
              }

              if (
                typeof VAS !== "undefined" &&
                typeof VAS.UnifiedCheckout === "function"
              ) {
                jQuery("#wc-error-failure").hide();
                if (
                  visa_acceptance_ajaxUCObj["checkout_display_mode"] ===
                  "embedded"
                ) {
                  jQuery("#buttonPaymentListContainer").after(
                    jQuery("<div>", { id: "embeddedPaymentContainer" }),
                  );
                }
                var mountArgs = {
                  paymentSelection: "#buttonPaymentListContainer",
                };
                if (
                  visa_acceptance_ajaxUCObj["checkout_display_mode"] ===
                  "embedded"
                ) {
                  mountArgs.paymentScreen = "#embeddedPaymentContainer";
                }
                
                launchUcCheckout(captureContext, transientToken, mountArgs);
              } else {
                jQuery("#wc-error-failure").show();
              }
            } else {
              buttonPaymentListContainer.append(errorMessagePara);
              // jQuery('#wc-error-failure').show();
            }
          },
          error: function (xhr, status, error) {
            jQuery(
              ".payment_box.payment_method_" +
                visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
            ).unblock();
            jQuery("#wc-error-failure").show();
          },
        });

        jQuery("#buttonPaymentListContainer").show();
        jQuery("#embeddedPaymentContainer").show();
        jQuery("#place_order").hide();
        jQuery("#wc-unified-checkout-normal-save-token-div").show();
      } else {
        if (!isEcheckToken) {
          jQuery("#place_order").hide();
        }
        jQuery("#buttonPaymentListContainer").hide();
        jQuery("#embeddedPaymentContainer").hide();
        jQuery("#wc-unified-checkout-normal-save-token-div").hide();
      }
    });

    if (
      $(
        "input[name$='payment_method'][value$='" +
          visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
          "']",
      ).is(":checked")
    ) {
      if (
        jQuery(
          "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method",
        ).length == 0 ||
        jQuery(
          "#wc-" +
            visa_acceptance_ajaxUCObj[
              "visa_acceptance_solutions_uc_id_hyphen"
            ] +
            "-use-new-payment-method",
        ).is(":checked")
      ) {
        $("#place_order").hide();
        if (event.type == "init_add_payment_method") {
          // At My account -> Add Payment page.
          if (!jQuery("#buttonPaymentListContainer").length) {
            jQuery(
              ".woocommerce-PaymentBox.woocommerce-PaymentBox--" +
                visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
                ".payment_box.payment_method_" +
                visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
            ).append(buttonPaymentListContainer);
          }
        }
        //checkout page - no saved payment method.
        var save_token_checkbox_div = $(
          "#wc-unified-checkout-normal-save-token-div",
        );
        if (save_token_checkbox_div.length) {
          //save card enabled, insert before checkbox div
          buttonPaymentListContainer.insertBefore(save_token_checkbox_div);
        } else {
          //If save card option not enabled, append directly to parent div
          if (!jQuery("#buttonPaymentListContainer").length) {
            $(
              ".payment_box.payment_method_" +
                visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
            ).append(buttonPaymentListContainer);
          }
        }
        var transientToken = document.getElementById("transientToken");
        var captureContext = document.getElementById("jwt_updated")
          ? document.getElementById("jwt_updated").value
          : document.getElementById("jwt")
            ? document.getElementById("jwt").value
            : null;
        if (captureContext && isCaptureContextAddressStale(captureContext)) {
          captureContext = null;
        }

        // If no capture context exists, call API to get fresh JWT (same pattern as saved card selection)
        if (!captureContext) {
          jQuery("#wc-error-failure").hide();
          jQuery(
            ".payment_box.payment_method_" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
          ).block({
            message: null,
            overlayCSS: { background: "#fff", opacity: 0.6 },
          });

          _ccCaptureContextFetchInFlight = true;
          jQuery.ajax({
            type: "POST",
            url: visa_acceptance_ajaxUCObj["ajax_url"],
            data: (function () {
              var ajaxData = {
                action: "wc_call_uc_update_price_action",
                force_refresh: "true",
                cc_only: "true",
                nonce: visa_acceptance_ajaxUCObj["update_price_nonce"],
              };
              var billingAddress = collectClassicCheckoutBillingAddress();
              if (billingAddress) {
                ajaxData.billing_address = JSON.stringify(billingAddress);
              }
              return ajaxData;
            })(),
            success: async function (response) {
              _ccCaptureContextFetchInFlight = false;
              jQuery(
                ".payment_box.payment_method_" +
                  visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
              ).unblock();

              if (
                response &&
                response.success &&
                response.capture_context_jwt
              ) {
                captureContext = updateHiddenTokenField(
                  "jwt_updated",
                  response.capture_context_jwt,
                );

                // Dynamically load UC library if not already loaded. Real URL resolved
                // server-side by the proxy (from session), not read from `response`.
                if (typeof VAS === "undefined") {
                  try {
                    await loadUCLibrary("cc", response.client_library_integrity);
                  } catch (err) {
                    console.error("Failed to load UC library:", err);
                    buttonPaymentListContainer.append(
                      errorMessagePara.cloneNode(true),
                    );
                    return;
                  }
                }

                if (
                  typeof VAS !== "undefined" &&
                  typeof VAS.UnifiedCheckout === "function"
                ) {
                  jQuery("#wc-error-failure").hide();
                  if (
                    visa_acceptance_ajaxUCObj["checkout_display_mode"] ===
                    "embedded"
                  ) {
                    jQuery("#buttonPaymentListContainer").after(
                      jQuery("<div>", { id: "embeddedPaymentContainer" }),
                    );
                  }
                  var mountArgs = {
                    paymentSelection: "#buttonPaymentListContainer",
                  };
                  if (
                    visa_acceptance_ajaxUCObj["checkout_display_mode"] ===
                    "embedded"
                  ) {
                    mountArgs.paymentScreen = "#embeddedPaymentContainer";
                  }
                  launchUcCheckout(captureContext, transientToken, mountArgs);
                } else {
                  buttonPaymentListContainer.append(
                    errorMessagePara.cloneNode(true),
                  );
                }
              } else {
                buttonPaymentListContainer.append(
                  errorMessagePara.cloneNode(true),
                );
              }
            },
            error: function (xhr, status, error) {
              _ccCaptureContextFetchInFlight = false;
              jQuery(
                ".payment_box.payment_method_" +
                  visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
              ).unblock();
              buttonPaymentListContainer.append(
                errorMessagePara.cloneNode(true),
              );
            },
          });
          return; // Exit and let AJAX callback handle the rest
        }

        if (
          typeof VAS !== "undefined" &&
          typeof VAS.UnifiedCheckout === "function" &&
          captureContext
        ) {
          jQuery("#wc-error-failure").hide();
          if (
            visa_acceptance_ajaxUCObj["checkout_display_mode"] === "embedded"
          ) {
            jQuery("#buttonPaymentListContainer").after(
              jQuery("<div>", { id: "embeddedPaymentContainer" }),
            );
          }
          var mountArgs = { paymentSelection: "#buttonPaymentListContainer" };
          if (
            visa_acceptance_ajaxUCObj["checkout_display_mode"] === "embedded"
          ) {
            mountArgs.paymentScreen = "#embeddedPaymentContainer";
          }
          launchUcCheckout(captureContext, transientToken, mountArgs);
        } else if (!captureContext) {
          jQuery("#wc-error-failure").show();
        } else {
          buttonPaymentListContainer.append(errorMessagePara);
        }
      }
    } else {
      $("#place_order").show();
      $(
        ".wc_payment_method.payment_method_" +
          visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
      )
        .find("#buttonPaymentListContainer")
        .empty();
    }
    if (event.type == "init_add_payment_method") {
      function checkAndTrigger() {
        if (
          $(
            "input[name$='payment_method'][value$='" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
              "']",
          ).is(":checked") &&
          !isEventTriggered
        ) {
          $(document.body).trigger("init_add_payment_method");
          isEventTriggered = true; // Set the flag to true once the event is triggered.
        } else if (
          !$(
            "input[name$='payment_method'][value$='" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
              "']",
          ).is(":checked")
        ) {
          isEventTriggered = false; // Reset the flag if the checkbox is unchecked.
        }
      }

      function checkAndRemove() {
        if (
          !$(
            "input[name$='payment_method'][value$='" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"] +
              "']",
          ).is(":checked")
        ) {
          $("#place_order").show();
          $(
            "li.payment_method_" +
              visa_acceptance_ajaxUCObj["visa_acceptance_solutions_uc_id"],
          )
            .find("#buttonPaymentListContainer")
            .empty();
        }
      }
      setInterval(checkAndTrigger, 1000);
      setInterval(checkAndRemove, 1000);
    }
  }
  $(document.body).on("payment_method_selected", function (event) {
    // For order-pay page, always handle payment selection
    // For order-pay page, admin order pages, change payment pages, always handle payment selection
    var isOrderReviewPage = $("form#order_review").length > 0;
    var isAdminOrderPage =
      (typeof visa_acceptance_ajaxUCObj !== "undefined" &&
        visa_acceptance_ajaxUCObj["is_admin_order_page"]) ||
      $("body").hasClass("post-type-shop_order") ||
      $("body").hasClass("post-type-shop_subscription");
    var isChangePaymentPage =
      (typeof visa_acceptance_ajaxUCObj !== "undefined" &&
        visa_acceptance_ajaxUCObj["is_change_payment_method"]) ||
      window.location.href.indexOf("change_payment_method") !== -1 ||
      window.location.pathname.indexOf("/change-payment-method/") !== -1;

    if (
      isOrderReviewPage ||
      _checkoutHasUpdated ||
      isAdminOrderPage ||
      isChangePaymentPage
    ) {
      handlePaymentSelection(event);
    }
  });
  var _ccWidgetInitialized = false;
  var _ccRefreshInFlight = false;
  var debouncedHandlePaymentSelectionOnUpdate = debounce(function (event) {
    _checkoutHasUpdated = true;

    if (_ccRefreshInFlight) {
      return;
    }
    var isFirstLoad = !_ccWidgetInitialized;
    _ccRefreshInFlight = true;
    jQuery.ajax({
      type: "POST",
      url: visa_acceptance_ajaxUCObj["ajax_url"],
      data: (function () {
        var ajaxData = { 
          action: "wc_call_uc_update_price_action", 
          cc_only: "true",
          nonce: visa_acceptance_ajaxUCObj["update_price_nonce"]
        };
        var billingAddress = collectClassicCheckoutBillingAddress();
        if (billingAddress) {
          ajaxData.billing_address = JSON.stringify(billingAddress);
        }
        return ajaxData;
      })(),
      success: function (response) {
        _ccRefreshInFlight = false;
        _ccWidgetInitialized = true;
        var hasNewJwt = !!(response && response.success && response.capture_context_jwt);
        if (hasNewJwt) {
          updateHiddenTokenField("jwt_updated", response.capture_context_jwt);
        }
        // First mount always happens, using the fresh JWT when available or
        // falling back to the PHP-rendered one. Later updates only rebuild
        // the widget when the amount/shipping/address actually changed.
        if (isFirstLoad || hasNewJwt) {
          handlePaymentSelection(event);
          movePaymentMethodAfterTerms();
        }
      },
      error: function () {
        _ccRefreshInFlight = false;
        if (isFirstLoad) {
          // Fall back to the PHP-rendered capture context so checkout isn't blocked entirely.
          _ccWidgetInitialized = true;
          handlePaymentSelection(event);
          movePaymentMethodAfterTerms();
        }
      },
    });
  }, 150);
  $(document.body).on(
    "updated_checkout",
    debouncedHandlePaymentSelectionOnUpdate,
  );
  var debouncedTriggerUpdateCheckout = debounce(function () {
    jQuery(document.body).trigger("update_checkout");
  }, 500);
  $(document.body).on(
    "input change",
    "#billing_address_1, #billing_address_2, #billing_city, #billing_state, #billing_postcode, #billing_country",
    debouncedTriggerUpdateCheckout,
  );
  $("body").on("init_add_payment_method", function (event) {
    handlePaymentSelection(event);
  });

  // Function to move Visa Acceptance payment method after terms and conditions
  function movePaymentMethodAfterTerms() {
    var $termsWrapper = $(".woocommerce-terms-and-conditions-wrapper");
    var $visaPaymentMethod = $(
      ".payment_box.payment_method_visa_acceptance_solutions_unified_checkout",
    );
    var $paymentMethods = $(".wc_payment_methods.payment_methods.methods");

    // Check if both elements exist
    if ($termsWrapper.length && $visaPaymentMethod.length) {
      // Check if it's not already moved
      if (
        !$visaPaymentMethod.hasClass("moved-after-terms") &&
        $paymentMethods.length
      ) {
        // Add a class to track that it's been moved
        $visaPaymentMethod.addClass("moved-after-terms");

        // Move the actual payment method element after terms and conditions
        $visaPaymentMethod.detach().insertAfter($termsWrapper);
        var $form = $("form.checkout");
        $visaPaymentMethod
          .find(
            "#transientToken, #errorMessage, #jwt, #jwt_updated, #ep_jwt, #ep_jwt_updated",
          )
          .each(function () {
            $form.append($(this).detach());
          });

        // Setup radio button synchronization
        setupPaymentMethodRadioSync();
      }
    }
  }

  // Function to ensure only one payment method radio is selected at a time
  function setupPaymentMethodRadioSync() {
    // Handle clicks on the moved Visa Acceptance radio button
    $(document).on(
      "change",
      '.payment_box.payment_method_visa_acceptance_solutions_unified_checkout.moved-after-terms > input[type="radio"][name="payment_method"]',
      function () {
        if ($(this).is(":checked")) {
          // Uncheck all other payment method radios in the original list
          $(
            '.wc_payment_methods.payment_methods.methods input[type="radio"][name="payment_method"]',
          ).prop("checked", false);

          // Show the payment box
          $(
            ".payment_box.payment_method_visa_acceptance_solutions_unified_checkout.moved-after-terms .payment_box",
          ).slideDown();

          // Trigger WooCommerce payment method selected event
          $(document.body).trigger("payment_method_selected");
        }
      },
    );

    // Handle clicks on other payment method radios in the main payment methods list
    $(document).on(
      "change",
      '.wc_payment_methods.payment_methods.methods input[type="radio"][name="payment_method"]',
      function () {
        if ($(this).is(":checked")) {
          // Uncheck the moved Visa Acceptance main radio button
          $(
            '.payment_box.payment_method_visa_acceptance_solutions_unified_checkout.moved-after-terms > input[type="radio"][name="payment_method"]',
          ).prop("checked", false);

          // Uncheck all nested token selection radios (saved cards and "use new card")
          $(
            '.payment_box.payment_method_visa_acceptance_solutions_unified_checkout.moved-after-terms input[type="radio"]',
          )
            .not('[name="payment_method"]')
            .prop("checked", false);

          // Hide the payment box
          $(
            ".payment_box.payment_method_visa_acceptance_solutions_unified_checkout.moved-after-terms .payment_box",
          ).slideUp();
        }
      },
    );
  }
})(jQuery);
