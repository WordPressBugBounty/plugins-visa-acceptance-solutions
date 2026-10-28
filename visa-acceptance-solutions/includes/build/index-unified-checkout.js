var { registerPaymentMethod, registerExpressPaymentMethod } =
  wc.wcBlocksRegistry;
var ucBlocksSettings = wc.wcSettings.getSetting(
  "visa_acceptance_solutions_unified_checkout_data",
);
var ucExpressPaySettings = wc.wcSettings.getSetting(
  "express_pay_unified_checkout_data",
);

var { decodeEntities } = wp.htmlEntities;

var { __ } = wp.i18n;

var { useSelect } = window.wp.data;
var { CHECKOUT_STORE_KEY } = window.wc.wcBlocksData;
var { ValidatedTextInput } = window.wc.blocksCheckout;
var uc_token, req_data;
var uc_blocks_complete_response = "";
var uc_blocks_saved_token_active = false;
var ucBlocksCcClient = null;
var ucBlocksCcCheckout = null;
var ucBlocksCcMountSeq = 0;
var ucBlocksEpClient = null;
var ucBlocksEpCheckout = null;
var ucBlocksEpMountSeq = 0;
var ccCaptureContextDebounceTimer = null;
var epCaptureContextDebounceTimer = null;

function destroyUcBlocksCcCheckout() {
  try {
    if (ucBlocksCcCheckout) {
      ucBlocksCcCheckout.destroy();
    }
    if (ucBlocksCcClient) {
      ucBlocksCcClient.destroy();
    }
  } catch (e) {
    /* ignore */
  }
  ucBlocksCcCheckout = null;
  ucBlocksCcClient = null;
}

function destroyUcBlocksEpCheckout() {
  try {
    if (ucBlocksEpCheckout) {
      ucBlocksEpCheckout.destroy();
    }
    if (ucBlocksEpClient) {
      ucBlocksEpClient.destroy();
    }
  } catch (e) {
    /* ignore */
  }
  ucBlocksEpCheckout = null;
  ucBlocksEpClient = null;
}
function waitMs(ms) {
  return new Promise(function (resolve) {
    setTimeout(resolve, ms);
  });
}

function reloadAfterError() {
  setTimeout(() => {
    window.location.reload();
  }, 5000);
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
  var loader = document.getElementById("loader");
  if (loader && loader.style) {
    loader.style.display = "none";
  }
}

/**
 * Calls up.complete() for blocks checkout.
 */
function blocksCompleteAndContinue(up, transientToken) {
  return up
    .complete(transientToken)
    .then(function (completeResponse) {
      try {
        var serialized = "";
        if (typeof completeResponse === "string") {
          var trimmed = completeResponse.trim();
          // If it looks like a plain JSON object/array, use it directly.
          if (
            (trimmed.charAt(0) === "{" &&
              trimmed.charAt(trimmed.length - 1) === "}") ||
            (trimmed.charAt(0) === "[" &&
              trimmed.charAt(trimmed.length - 1) === "]")
          ) {
            serialized = trimmed;
          } else {
            // Treat as JWT — decode the middle (payload) segment.
            var jwtParts = trimmed.split(".");
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
        uc_blocks_complete_response = serialized;
      } catch (e) {
        uc_blocks_complete_response = "";
      }
    })
    .catch(function () {
      uc_blocks_complete_response = "";
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

    if (!expressOnly) {
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
        action: "get_addresses_from_transient_token",
        transientToken: transientToken,
        nonce:
          (ucBlocksSettings && ucBlocksSettings.get_addresses_nonce) ||
          (ucExpressPaySettings && ucExpressPaySettings.get_addresses_nonce) ||
          "",
      },
      success: function (response) {
        if (response && response.success && response.data) {
          const billing = response.data.billing;
          const shipping = response.data.shipping;

          // Update WooCommerce Blocks checkout store with billing address
          if (billing && Object.keys(billing).length > 0) {
            const billingAddress = {
              first_name: billing.first_name || "",
              last_name: billing.last_name || "",
              company: billing.company || "",
              address_1: billing.address_1 || "",
              address_2: billing.address_2 || "",
              city: billing.city || "",
              state: billing.state || "",
              postcode: billing.postcode || "",
              country: billing.country || "",
              email: billing.email || "",
              phone: billing.phone || "",
            };

            try {
              // Update billing address in checkout store
              window.wp.data
                .dispatch("wc/store/cart")
                .setBillingAddress(billingAddress);
            } catch (e) {
              console.log("Failed to set billing address:", e);
            }
          }

          // Update shipping address
          if (shipping && Object.keys(shipping).length > 0) {
            const shippingAddress = {
              first_name: shipping.first_name || "",
              last_name: shipping.last_name || "",
              company: shipping.company || "",
              address_1: shipping.address_1 || "",
              address_2: shipping.address_2 || "",
              city: shipping.city || "",
              state: shipping.state || "",
              postcode: shipping.postcode || "",
              country: shipping.country || "",
              phone: shipping.phone || "",
            };

            try {
              // Update shipping address in checkout store
              window.wp.data
                .dispatch("wc/store/cart")
                .setShippingAddress(shippingAddress);
            } catch (e) {
              console.log("Failed to set shipping address:", e);
            }
          }
        }
        // Wait for store to sync before resolving
        setTimeout(() => resolve(), 100);
      },
      error: function (error) {
        resolve(); // Resolve anyway to allow order submission
      },
    });
  });
}

// Hide "(expires /)" text from eCheck token labels in blocks checkout.
(function removeEcheckExpiryLabel() {
  if (!ucBlocksSettings.token_type) return;
  var eCheckIds = Object.keys(ucBlocksSettings.token_type).filter(
    function (id) {
      return ucBlocksSettings.token_type[id] === "eCheck";
    },
  );
  if (!eCheckIds.length) return;
  function stripEcheckExpiry() {
    document
      .querySelectorAll(".wc-block-components-payment-methods__token")
      .forEach(function (el) {
        var radio = el.querySelector('input[type="radio"]');
        if (!radio) return;
        var tokenId = radio.value;
        if (eCheckIds.indexOf(tokenId) === -1) return;
        var label = el.querySelector("label");
        if (label && label.textContent.includes("(expires")) {
          label.textContent = label.textContent
            .replace(/\s*\(expires[^)]*\)/g, "")
            .trim();
        }
      });
  }
  var observer = new MutationObserver(stripEcheckExpiry);
  observer.observe(document.body, { childList: true, subtree: true });
  stripEcheckExpiry();
})();

var ucblockssavedcomponent = (props) => {
  // Check if tokenization is enabled, then show saved card CVV field else hide.
  if (!ucBlocksSettings.tokenization) {
    (function () {
      var tokenizationEnabled =
        typeof ucBlocksSettings !== "undefined"
          ? !!ucBlocksSettings.enable_tokenization
          : true;

      if (tokenizationEnabled) return;

      function findUseAnotherElement() {
        const nodes = Array.from(
          document.querySelectorAll(
            ".wc-block-components-radio-control, .wc-block-components-checkout__payment-method, .wc-block-components-checkout-step__description, legend, label",
          ),
        );
        return (
          nodes.find((n) => {
            try {
              return (
                n.textContent &&
                n.textContent
                  .trim()
                  .toLowerCase()
                  .includes("use another payment method")
              );
            } catch (e) {
              return false;
            }
          }) || null
        );
      }

      function hideSavedCardsAndUseAnother() {
        const useAnother = findUseAnotherElement();
        if (!useAnother) return false;

        const parent = useAnother.parentElement;
        if (!parent) return false;

        const children = Array.from(parent.children);
        for (let i = 0; i < children.length; i++) {
          const el = children[i];
          if (
            el.classList.contains("wc-block-components-radio-control") ||
            el.className.indexOf("payment-method") !== -1 ||
            el.querySelector?.('input[type="radio"]')
          ) {
            el.style.display = "none";
            el.setAttribute("data-hidden-by-tokenization", "1");
          }
          if (el === useAnother) {
            el.style.display = "none";
            el.setAttribute("data-hidden-by-tokenization", "1");
            break;
          }
        }
        let prev = useAnother.previousElementSibling;
        while (prev) {
          if (
            prev.querySelector &&
            prev.querySelector(".wc-block-components-radio-control")
          ) {
            prev.style.display = "none";
            prev.setAttribute("data-hidden-by-tokenization", "1");
          }
          prev = prev.previousElementSibling;
        }
        window.wp.data
          .dispatch("wc/store/payment")
          .__internalSetActivePaymentMethod(
            ucBlocksSettings.visa_acceptance_solutions_uc_id,
          );

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
      window.__showSavedCards_by_tokenization = function () {
        document
          .querySelectorAll("[data-hidden-by-tokenization]")
          .forEach((el) => {
            el.style.display = "";
            el.removeAttribute("data-hidden-by-tokenization");
          });
      };
    })();
    return null;
  }

  var { token, onSubmit, eventRegistration, emitResponse } = props;
  var { onPaymentSetup, onCheckoutFail } = eventRegistration;

  var cvvRef = React.useRef("");

  // TMS UC saved card flow state
  var tmsTransientTokenRef = React.useRef(null);
  var [tmsUcLoading, setTmsUcLoading] = React.useState(false);
  var [tmsUcError, setTmsUcError] = React.useState(null);
  var tmsClientRef = React.useRef(null);
  var tmsCheckoutRef = React.useRef(null);

  React.useEffect(() => {
    if (token) {
      uc_blocks_saved_token_active = true;
      jQuery(".wc-block-components-checkout-place-order-button").hide();
    } else {
      uc_blocks_saved_token_active = false;
      jQuery(".wc-block-components-checkout-place-order-button").show();
    }
    return function () {
      uc_blocks_saved_token_active = false;
      jQuery(".wc-block-components-checkout-place-order-button").show();
    };
  }, [token]);

  React.useEffect(() => {
    if (!cvvRef.current) return;
    cvvRef.current = "";
  }, [token]);

  React.useEffect(() => {
    var unsubscribe = onPaymentSetup(async () => {
      if (token) {
        // Check if token is eCheck - skip CVV validation for eCheck.
        var isEcheck =
          ucBlocksSettings.token_type &&
          ucBlocksSettings.token_type[token] === "eCheck";

        // TMS UC flow: UC widget captures CVV → transient token stored in ref
        if (
          typeof VAS !== "undefined" &&
          (ucBlocksSettings.saved_card_cvv || isEcheck)
        ) {
          var tmsToken = tmsTransientTokenRef.current;
          // Only validate if widget was initialized but token is still null (widget not completed yet)
          // For TMS_TOKEN flow (subscriptions/saved cards), widget may complete without returning new token
          if (
            tmsToken === null &&
            tmsClientRef.current !== null &&
            tmsUcLoading
          ) {
            return {
              type: emitResponse.responseTypes.ERROR,
              message: __(
                "Please complete the card security verification step.",
                "visa-acceptance-solutions",
              ),
              messageContext: emitResponse.noticeContexts.PAYMENTS,
            };
          }
          var pmData = {
            token: token,
            uc_complete_response: uc_blocks_complete_response,
          };
          if (tmsToken) {
            pmData.transientToken = tmsToken;
          }
          return {
            type: emitResponse.responseTypes.SUCCESS,
            meta: { paymentMethodData: pmData },
          };
        }
      } else {
        console.error("No token was provided for processing the payment.");
      }
    });

    var ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
      var errorResponse = processingResponse;
      var paymentDetails =
        errorResponse.processingResponse &&
        errorResponse.processingResponse.paymentDetails
          ? errorResponse.processingResponse.paymentDetails
          : null;
      // Wallet buttons (e.g. Apple Pay) embedded in the card section can return the message
      // at the top level instead of nested under paymentDetails; fall back to that.
      var fallbackMessage =
        errorResponse?.processingResponse?.message || errorResponse?.message;
      if (
        paymentDetails &&
        paymentDetails.reload &&
        paymentDetails.authRequired
      ) {
        var scaMsg =
          paymentDetails.message ||
          "Your bank requires additional authentication. Please re-enter your card details.";
        window.wp.data
          .dispatch("core/notices")
          .createErrorNotice(scaMsg, {
            id: "sca-retry",
            context: "wc/checkout",
          });
        setTimeout(function () {
          window.location.reload();
        }, 1500);
        return {
          type: emitResponse.responseTypes.ERROR,
          message: scaMsg,
          messageContext: emitResponse.noticeContexts.PAYMENTS,
        };
      }

      if (!paymentDetails && fallbackMessage) {
        return {
          type: emitResponse.responseTypes.ERROR,
          message: fallbackMessage,
          messageContext: emitResponse.noticeContexts.PAYMENTS,
        };
      }
      reloadAfterError();
      return true;
    });
    jQuery(".wc-block-components-notice-banner.is-error").hide().text("");
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
  ]);

  // Check if the current token is an eCheck - don't show CVV for eCheck tokens.
  var isEcheck =
    ucBlocksSettings.token_type &&
    ucBlocksSettings.token_type[token] === "eCheck";

  // TMS UC flow is enabled if saved_card_cvv is on or it's an eCheck (VAS loads dynamically now)
  var isTmsUcFlow = ucBlocksSettings.saved_card_cvv || isEcheck;

  // Always render container if there's an error to display
  var savedCardCvv =
    ucBlocksSettings.saved_card_cvv || isEcheck || tmsUcError
      ? React.createElement(
          "div",
          { className: "saved-card-container" },
          isTmsUcFlow
            ? React.createElement(
                "div",
                { className: "blocks-saved-card-uc-wrapper" },
                tmsUcLoading
                  ? React.createElement("div", {
                      className: "wc-block-components-spinner",
                      "aria-hidden": "true",
                    })
                  : null,
                tmsUcError
                  ? React.createElement(
                      "div",
                      {
                        className: "wc-block-components-notice-banner is-error",
                        style: { marginTop: "8px" },
                      },
                      tmsUcError,
                    )
                  : null,
                React.createElement("div", {
                  id: "blocks-saved-card-pay-btn-container",
                }),
                React.createElement("div", {
                  id: "blocks-saved-card-uc-form-container",
                }),
              )
            : // Show error even when not in TMS flow
              tmsUcError
              ? React.createElement(
                  "div",
                  { className: "blocks-saved-card-uc-wrapper" },
                  React.createElement(
                    "div",
                    {
                      className: "wc-block-components-notice-banner is-error",
                      style: { marginTop: "8px" },
                    },
                    tmsUcError,
                  ),
                )
              : React.createElement(
                  "div",
                  { className: "saved-card-flex-container" },
                  React.createElement(
                    "label",
                    {
                      htmlFor: "flex-cvv-field",
                      className: "saved-card-cvv-label",
                    },
                    __("Security Code", "visa-acceptance-solutions"),
                  ),
                ),
          React.createElement("div", { className: "saved-card-alignment-fix" }),
        )
      : null;

  // TMS UC saved card flow: fetch capture context and mount UC widget.
  React.useEffect(() => {
    var isEcheck =
      ucBlocksSettings.token_type &&
      ucBlocksSettings.token_type[token] === "eCheck";

    // Check if required conditions are not met
    if (!token || (!ucBlocksSettings.saved_card_cvv && !isEcheck)) {
      // No token selected or conditions not met, clear any errors
      setTmsUcError(null);
      setTmsUcLoading(false);
      return;
    }

    // Clear any previous errors since conditions are now met
    setTmsUcError(null);

    // Clean up any previous instance.
    tmsTransientTokenRef.current = null;
    if (tmsClientRef.current) {
      try {
        tmsClientRef.current.destroy();
      } catch (e) {}
      tmsClientRef.current = null;
    }
    tmsCheckoutRef.current = null;
    jQuery("#blocks-saved-card-uc-form-container").empty();
    jQuery("#blocks-saved-card-pay-btn-container").empty();
    setTmsUcError(null);
    setTmsUcLoading(true);

    var mounted = true;

    // Function to dynamically load the VAS library if it is not already loaded. This is necessary for mounting the UC widget for saved card payments.
    function loadVASLibrary(integrity) {
      return new Promise((resolve, reject) => {
        if (typeof VAS !== "undefined") {
          resolve();
          return;
        }

        var nonce = ucBlocksSettings.uc_library_proxy_nonce;
        var ajaxUrl = ucBlocksSettings.ajax_url;
        if (!nonce || !ajaxUrl) {
          reject(new Error("Invalid UC library proxy configuration"));
          return;
        }
        var separator = ajaxUrl.indexOf("?") === -1 ? "?" : "&";
        var proxyUrl =
          ajaxUrl +
          separator +
          "action=vas_uc_library_proxy&mode=saved_card&_wpnonce=" +
          encodeURIComponent(nonce);

        var script = document.createElement("script");
        script.src = proxyUrl;
        if (integrity) {
          script.integrity = integrity;
          script.crossOrigin = "anonymous";
        }
        script.onload = function () {
          resolve();
        };
        script.onerror = function () {
          reject(new Error("Failed to load VAS library"));
        };
        document.head.appendChild(script);
      });
    }

    jQuery.ajax({
      type: "POST",
      url: ucBlocksSettings.ajax_url,
      data: {
        action: "wc_get_saved_card_context",
        nonce: ucBlocksSettings.get_saved_card_context_nonce,
        token_id: token,
      },
      success: async function (response) {
        if (!mounted) return;
        if (!response.success || !response.data || !response.data.jwt) {
          setTmsUcError(
            __(
              "Unable to load the payment form. Please contact customer care for any assistance.",
              "visa-acceptance-solutions",
            ),
          );
          setTmsUcLoading(false);
          return;
        }
        try {
          await loadVASLibrary(response.data.client_library_integrity);

          // Wait for DOM containers to be ready
          var containerCheckAttempts = 0;
          var maxAttempts = 20;
          while (containerCheckAttempts < maxAttempts) {
            var payBtnContainer = document.getElementById(
              "blocks-saved-card-pay-btn-container",
            );
            var formContainer = document.getElementById(
              "blocks-saved-card-uc-form-container",
            );

            if (payBtnContainer && formContainer) {
              break;
            }

            await waitMs(100);
            containerCheckAttempts++;
          }

          if (containerCheckAttempts >= maxAttempts) {
            throw new Error("Payment form containers not found in DOM");
          }

          var client = await VAS.UnifiedCheckout(response.data.jwt);
          if (!mounted) {
            try {
              client.destroy();
            } catch (e) {}
            return;
          }
          tmsClientRef.current = client;
          var checkout = await client.createCheckout({ autoProcessing: false });
          tmsCheckoutRef.current = checkout;
          setTmsUcLoading(false);
          var savedCardBlocksMountArgs = {
            paymentSelection: "#blocks-saved-card-pay-btn-container",
          };
          if (ucBlocksSettings.checkout_display_mode === "embedded") {
            savedCardBlocksMountArgs.paymentScreen =
              "#blocks-saved-card-uc-form-container";
          }
          var tt = await checkout.mount(savedCardBlocksMountArgs);
          if (!mounted) return;
          if (tt) {
            await blocksCompleteAndContinue(checkout, tt);
          }
          // Store tt — may be falsy for TMS_TOKEN (widget confirms without returning a new token).
          tmsTransientTokenRef.current = typeof tt === "string" && tt ? tt : "";
          // Trigger WC Blocks checkout submission.
          if (typeof onSubmit === "function") {
            onSubmit();
          } else {
            var placeOrderBtn = document.querySelector(
              ".wc-block-components-checkout-place-order-button button",
            );
            if (placeOrderBtn) {
              placeOrderBtn.dispatchEvent(
                new MouseEvent("click", { bubbles: true, cancelable: true }),
              );
            }
          }
        } catch (err) {
          if (!mounted) return;
          console.error("TMS UC Error:", err);
          setTmsUcError(
            __(
              "Unable to load the payment form. Please contact customer care for any assistance.",
              "visa-acceptance-solutions",
            ),
          );
          setTmsUcLoading(false);
        }
      },
      error: function (xhr, status, error) {
        if (!mounted) return;
        console.error("AJAX Error:", {
          xhr: xhr,
          status: status,
          error: error,
        });
        setTmsUcError(
          __(
            "Unable to load the payment form. Please contact customer care for any assistance.",
            "visa-acceptance-solutions",
          ),
        );
        setTmsUcLoading(false);
      },
    });

    return function () {
      mounted = false;
      tmsTransientTokenRef.current = null;
      if (tmsClientRef.current) {
        try {
          tmsClientRef.current.destroy();
        } catch (e) {}
        tmsClientRef.current = null;
      }
      tmsCheckoutRef.current = null;
      // Clear DOM containers on unmount / token change.
      jQuery("#blocks-saved-card-uc-form-container").empty();
      jQuery("#blocks-saved-card-pay-btn-container").empty();
      // Clear error state
      setTmsUcError(null);
      setTmsUcLoading(false);
    };
  }, [token]);
  return [savedCardCvv];
};

var ucComponents = (props) => {
  jQuery(".wc-block-components-checkout-place-order-button").hide();

  var { onSubmit, eventRegistration, emitResponse, billing, components } =
    props;
  var { onPaymentSetup, onCheckoutFail } = eventRegistration;

  var { LoadingMask } = components;
  var isIdle = useSelect(CHECKOUT_STORE_KEY).isIdle();
  var orderId = useSelect(CHECKOUT_STORE_KEY).getOrderId();

  // Get shipping rates to track changes.
  var shippingRates = useSelect((select) => {
    try {
      return select("wc/store/cart").getShippingRates();
    } catch (e) {
      return [];
    }
  });

  var { cartTotal, shippingAddress, billingAddress } = billing || {};
  var [isLoadingCC, setLoadingCC] = React.useState(true);
  var [captureContext, setCaptureContext] = React.useState(null);

  // Restore payment method after reload (for regular UC component).
  React.useEffect(() => {
    var savedPaymentMethod = sessionStorage.getItem("visa_last_payment_method");
    if (savedPaymentMethod) {
      try {
        window.wp.data
          .dispatch("wc/store/payment")
          .__internalSetActivePaymentMethod(savedPaymentMethod);
        sessionStorage.removeItem("visa_last_payment_method");
      } catch (e) {
        console.log("Failed to restore payment method:", e);
      }
    }
  }, []);
  var lastCcSignatureRef = React.useRef(null);
  var ccFetchInFlightRef = React.useRef(false);

  React.useEffect(() => {
    // Skip CC context refresh only when a saved card is actively selected.
    if (uc_blocks_saved_token_active) {
      setLoadingCC(false);
      return;
    }
    var signature;
    try {
      signature = JSON.stringify({
        total: cartTotal ? cartTotal.value : null,
        shipping: shippingAddress,
        billing: billingAddress,
        rates: (shippingRates || []).map((rate) => ({
          rate_id: rate && rate.rate_id,
          price: rate && rate.price,
        })),
      });
    } catch (e) {
      signature = null;
    }
    if (signature !== null && signature === lastCcSignatureRef.current) {
      return;
    }
    setLoadingCC(true);
    clearTimeout(ccCaptureContextDebounceTimer);
    ccCaptureContextDebounceTimer = setTimeout(() => {
      if (ccFetchInFlightRef.current) {
        setLoadingCC(false);
        return;
      }
      lastCcSignatureRef.current = signature;
      ccFetchInFlightRef.current = true;
      getCaptureContext(orderId, false, billingAddress)
        .then((response) => {
          response && setCaptureContext(response);
        })
        .catch((error) => {
          setCaptureContext("Invalid Capture context");
        })
        .finally(() => {
          ccFetchInFlightRef.current = false;
          setLoadingCC(false);
        });
    }, 150);
    return () => clearTimeout(ccCaptureContextDebounceTimer);
  }, [cartTotal.value, shippingAddress, billingAddress, shippingRates]);

  /**
   *
   * @param {string} orderId - current orderid
   * @returns {Promise<string>}
   */
  var getCaptureContext = React.useCallback(
    async (orderId, forceRefresh, currentBillingAddress) => {
      return new Promise((resolve, reject) => {
        try {
          var ajaxData = {
            action: "wc_call_uc_update_price_action",
            nonce: ucBlocksSettings["update_price_nonce"],
            order_id: orderId,
            cc_only: "true",
          };

          // Add force_refresh parameter only when explicitly requested
          if (forceRefresh === true) {
            ajaxData.force_refresh = "true";
          }
          if (currentBillingAddress) {
            ajaxData.billing_address = JSON.stringify(currentBillingAddress);
          }

          jQuery.ajax({
            type: "POST",
            url: ucBlocksSettings["ajax_url"],
            cache: false,
            async: true,
            data: ajaxData,
            success: function (data) {
              if (!data) {
                return reject("Invalid data");
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
              if (
                data.success &&
                !data.capture_context &&
                !data.capture_context_jwt
              ) {
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
    },
    [cartTotal.value, shippingAddress, shippingRates],
  );

  React.useEffect(() => {
    var ucPaymentComponent = onPaymentSetup(async () => {
      var blocks_token = uc_token;
      if (blocks_token) {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              blocks_token,
              uc_complete_response: uc_blocks_complete_response,
            },
          },
        };
      }

      return {
        type: emitResponse.responseTypes.ERROR,
        message: __("There was an error", "visa-acceptance-solutions"),
      };
    });

    //Can be Used Later.
    var ucPaymentComponentfail = onCheckoutFail((processingResponse) => {
      if (jQuery("#embeddedPaymentContainer").find("iframe").length > 0) {
        jQuery("#embeddedPaymentContainer").children().remove();
      }
      reloadAfterError();
      return true;
    });
    jQuery(".wc-block-components-notice-banner.is-error").hide().text("");
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

  // Add listener for radio button changes to fetch capture context
  React.useEffect(() => {
    var radioButtonId =
      "#radio-control-wc-payment-method-options-" +
      ucBlocksSettings.visa_acceptance_solutions_uc_id;

    function handleRadioChange(e) {
      if (e.target.checked) {
        if (ccFetchInFlightRef.current) {
          return;
        }
        setLoadingCC(true);
        ccFetchInFlightRef.current = true;
        getCaptureContext(orderId, true)
          .then((response) => {
            if (response) {
              setCaptureContext(response);
            } else {
              console.warn("Capture context is null or empty");
            }
          })
          .catch((error) => {
            console.error("Error fetching capture context:", error);
            setCaptureContext("Invalid Capture context");
          })
          .finally(() => {
            ccFetchInFlightRef.current = false;
            setLoadingCC(false);
          });
      }
    }

    // Attach event listener
    var radioButton = document.querySelector(radioButtonId);
    if (radioButton) {
      radioButton.addEventListener("change", handleRadioChange);
    } else {
      console.warn("Radio button not found:", radioButtonId);
    }

    // Cleanup
    return () => {
      if (radioButton) {
        radioButton.removeEventListener("change", handleRadioChange);
      }
    };
  }, [orderId, isIdle]);

  React.useEffect(() => {
    if ((!isIdle && ucBlocksSettings.isVersionSupported) || isLoadingCC) {
      return;
    }
    if (
      jQuery(
        "#radio-control-wc-payment-method-options-" +
          ucBlocksSettings.visa_acceptance_solutions_uc_id,
      ).is(":checked")
    ) {
      jQuery(".wc-block-components-checkout-place-order-button").hide();
      var transientToken = document.getElementById("transientToken");
      var cc = document.getElementById("jwt")
        ? document.getElementById("jwt").value
        : "";

      if (captureContext) {
        cc = captureContext;
      } else {
        console.warn("captureContext is null or undefined");
      }

      if (!cc) {
        // Capture context not fetched yet; wait for it instead of showing a premature error.
        console.warn("No capture context available yet - skipping UC init until it arrives");
        return;
      }

      //clear in case of reinitialising or any error happens.
      jQuery("#buttonPaymentListContainer").empty();
      jQuery("#embeddedPaymentContainer").empty();

      if (
        typeof VAS !== "undefined" &&
        typeof VAS.UnifiedCheckout === "function"
      ) {
        jQuery(".blocks-error-div-uc").hide();
        jQuery(".blocks-failure-error-div-uc").hide();

        async function launchBlocksCheckout() {
          var mySeq = ++ucBlocksCcMountSeq;
          destroyUcBlocksCcCheckout();
          let client;
          let checkout;
          try {
            var mountArgs = { paymentSelection: "#buttonPaymentListContainer" };
            if (ucBlocksSettings.checkout_display_mode === "embedded") {
              mountArgs.paymentScreen = "#embeddedPaymentContainer";
            }
            client = await VAS.UnifiedCheckout(cc);
            if (mySeq !== ucBlocksCcMountSeq) {
              client.destroy();
              return;
            }
            checkout = await client.createCheckout({ autoProcessing: false });
            if (mySeq !== ucBlocksCcMountSeq) {
              checkout.destroy();
              client.destroy();
              return;
            }
            ucBlocksCcClient = client;
            ucBlocksCcCheckout = checkout;
            const tt = await checkout.mount(mountArgs);

            transientToken.value = tt;
            jQuery("#transientToken").val(tt);
            uc_token = tt;
            jQuery("#embeddedPaymentContainer").empty();
            return blocksCompleteAndContinue(checkout, tt).then(function () {
              populateCheckoutFromTransientToken(tt, false).then(function () {
                onSubmit();
              });
            });
          } catch (error) {
            console.error("Error in launchBlocksCheckout:", error);
            console.error("Capture context that caused error:", cc);
            jQuery("#buttonPaymentListContainer").empty();
            jQuery("#embeddedPaymentContainer").empty();
            jQuery(".blocks-failure-error-div-uc").show();
            if (checkout) {
              checkout.destroy();
            }
            if (client) {
              client.destroy();
            }
            if (ucBlocksCcCheckout === checkout) {
              ucBlocksCcCheckout = null;
            }
            if (ucBlocksCcClient === client) {
              ucBlocksCcClient = null;
            }
          }
        }
        launchBlocksCheckout();
      } else {
        console.error("VAS.UnifiedCheckout is not available");
        jQuery(".blocks-error-div-uc").show();
      }
    }
  }, [captureContext, isIdle, isLoadingCC]);

  var loader = React.createElement(LoadingMask, {
    isLoading: true,
    screenReaderLabel: __(
      "Loading Capture Context",
      "visa-acceptance-solutions",
    ),
    showSpinner: true,
  });

  if (isLoadingCC) {
    return [loader];
  }

  const trasientToken = React.createElement(
    "div",
    null,
    React.createElement("input", {
      type: "hidden",
      id: "transientToken",
      name: "transientToken",
    }),
  );

  var newCardDiv = React.createElement(
    "div",
    {
      id: "buttonPaymentListContainer",
    },
    React.createElement("div", {
      type: "button",
      id: "checkoutEmbedded",
      disabled: "disabled",
    }),
    React.createElement("div", {
      type: "button",
      id: "checkoutSidebar",
      disabled: "disabled",
    }),
  );
  var embed =
    ucBlocksSettings.checkout_display_mode === "embedded"
      ? React.createElement("div", { id: "embeddedPaymentContainer" })
      : null;
  var errorDiv = React.createElement(
    "p",
    {
      className: "blocks-error-div-uc",
      style: {
        display: "none",
        color: "red",
      },
    },
    ucBlocksSettings.form_load_error,
  );
  var errorFailure = React.createElement(
    "p",
    {
      className: "blocks-failure-error-div-uc",
      style: {
        display: "none",
        color: "red",
      },
    },
    ucBlocksSettings.failure_error,
  );

  // Adding description at checkout.
  var description = React.createElement(
    "div",
    {},
    ucBlocksSettings.description,
  );
  var notice = React.createElement(
    window.wc.blocksCheckout.StoreNoticesContainer,
    {
      context: "visa_acceptance_solutions_notice_container",
    },
  );

  return [
    description,
    newCardDiv,
    trasientToken,
    embed,
    errorDiv,
    errorFailure,
    notice,
  ];
};

var ucExpressPayComponents = (props) => {
  var { onSubmit, eventRegistration, emitResponse, billing, components } =
    props;
  var { onPaymentSetup, onCheckoutFail } = eventRegistration;

  var { LoadingMask } = components;
  var isIdle = useSelect(CHECKOUT_STORE_KEY).isIdle();
  var orderId = useSelect(CHECKOUT_STORE_KEY).getOrderId();

  // Get shipping rates to track changes.
  var shippingRates = useSelect((select) => {
    try {
      return select("wc/store/cart").getShippingRates();
    } catch (e) {
      return [];
    }
  });

  var { cartTotal, shippingAddress } = billing || {};
  var [isLoadingCC, setLoadingCC] = React.useState(true);
  var [captureContext, setCaptureContext] = React.useState(null);
  var [isExpressCheckout, setExpressCheckout] = React.useState(null);
  var lastEpSignatureRef = React.useRef(null);

  React.useEffect(() => {
    var signature;
    try {
      signature = JSON.stringify({
        total: cartTotal ? cartTotal.value : null,
        rates: (shippingRates || []).map((rate) => ({
          rate_id: rate && rate.rate_id,
          price: rate && rate.price,
        })),
      });
    } catch (e) {
      signature = null;
    }
    if (signature !== null && signature === lastEpSignatureRef.current) {
      return;
    }
    setLoadingCC(true);

    //get the updated captureContext if any cart value is changed or shipping changes.
    clearTimeout(epCaptureContextDebounceTimer);
    epCaptureContextDebounceTimer = setTimeout(() => {
      lastEpSignatureRef.current = signature;
      getCaptureContext(orderId)
        .then((response) => {
          response && setCaptureContext(response);
        })
        .catch((error) => {
          setCaptureContext("Invalid Capture context");
        })
        .finally(() => setLoadingCC(false));
    }, 150);
    return () => clearTimeout(epCaptureContextDebounceTimer);
  }, [cartTotal.value, shippingRates]);

  /**
   *
   * @param {string} orderId - current orderid
   * @returns {Promise<string>}
   */
  var getCaptureContext = React.useCallback(
    async (orderId) => {
      return new Promise((resolve, reject) => {
        try {
          jQuery.ajax({
            type: "POST",
            url: ucExpressPaySettings["ajax_url"],
            cache: false,
            async: true,
            data: {
              action: "wc_call_uc_update_price_action",
              nonce: ucExpressPaySettings["update_price_nonce"],
              order_id: orderId,
              ep_only: "true",
            },
            success: function (data) {
              if (!data) {
                return reject("Invalid data");
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
              if (
                data.success &&
                !data.capture_context &&
                !data.capture_context_ep_jwt
              ) {
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
    },
    [cartTotal.value, shippingAddress, shippingRates],
  );

  React.useEffect(() => {
    if (!isExpressCheckout) return;
    var expressPayPaymentComponent = onPaymentSetup(async () => {
      // Here we can do any processing we need, and then emit a response.
      // For example, we might validate a custom field, or perform an AJAX request, and then emit a response indicating it is valid or not.
      var blocks_token = uc_token;
      if (blocks_token) {
        return {
          type: emitResponse.responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              blocks_token,
              uc_complete_response: uc_blocks_complete_response,
            },
          },
        };
      }

      return {
        type: emitResponse.responseTypes.ERROR,
        message: __("There was an error", "visa-acceptance-solutions"),
      };
    });

    //Can be Used Later.
    var expressPayPaymentComponentfail = onCheckoutFail(
      (processingResponse) => {
        if (
          jQuery("#embeddedExpressPaymentContainer").find("iframe").length > 0
        ) {
          jQuery("#embeddedExpressPaymentContainer").children().remove();
        }

        var errorResponse = processingResponse;
        // Wallet buttons can return the message at the top level instead of nested under paymentDetails.
        var fallbackMessage =
          errorResponse?.processingResponse?.message || errorResponse?.message;
        if (!paymentDetails && fallbackMessage) {
          hideExpressPayLoader();
          jQuery("#payment-method").show();
          jQuery(".wc-block-components-checkout-place-order-button").show();
          return {
            type: emitResponse.responseTypes.ERROR,
            message: fallbackMessage,
            messageContext: emitResponse.noticeContexts.PAYMENTS,
          };
        }
        // Show payment method section back on any checkout failure.
        hideExpressPayLoader();
        jQuery("#payment-method").show();
        jQuery(".wc-block-components-checkout-place-order-button").show();
        reloadAfterError();
        return true;
      },
    );
    jQuery(".wc-block-components-notice-banner.is-error").hide().text("");
    return () => {
      expressPayPaymentComponent();
      expressPayPaymentComponentfail();
    };
  }, [
    emitResponse.responseTypes.ERROR,
    emitResponse.responseTypes.SUCCESS,
    onPaymentSetup,
    onCheckoutFail,
    isExpressCheckout,
  ]);

  // Watch for validation errors and show payment form back.
  React.useEffect(() => {
    if (!isExpressCheckout) return;

    const observer = new MutationObserver(() => {
      // Check if validation errors are present in the DOM.
      const hasValidationErrors =
        document.querySelector(".wc-block-components-validation-error") ||
        document.querySelector(".wc-block-error-message");

      if (hasValidationErrors && jQuery("#payment-method").is(":hidden")) {
        hideExpressPayLoader();
        jQuery("#payment-method").show();
        jQuery(".wc-block-components-checkout-place-order-button").show();
      }
    });

    // Observe the checkout form for changes.
    const checkoutForm = document.querySelector(".wc-block-checkout__form");
    if (checkoutForm) {
      observer.observe(checkoutForm, { childList: true, subtree: true });
    }

    return () => observer.disconnect();
  }, [isExpressCheckout]);

  React.useEffect(() => {
    if ((!isIdle && ucExpressPaySettings.isVersionSupported) || isLoadingCC) {
      return;
    }
    // Check if browser/device supports Apple Pay
    var isSafariOrAppleDevice = function () {
      var ua = navigator.userAgent.toLowerCase();
      var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
      var isIOS = /iphone|ipad|ipod/.test(ua);
      var isMac = /macintosh|mac os x/.test(ua);

      // macOS: Only Safari supports Apple Pay, not Chrome/Edge/Firefox
      if (isMac && !isSafari) {
        return false;
      }

      // iOS devices: All browsers support Apple Pay (iOS 18+)
      // Safari on any OS: Supports Apple Pay
      return isSafari || isIOS;
    };
    if (ucExpressPaySettings.enable_express_pay) {
      if (jQuery(".wc-block-components-express-payment__content")) {
        var transientToken = document.getElementById("transientToken");
        var cc = document.getElementById("ep_jwt")?.value ?? null;

        if (captureContext) {
          cc = captureContext;
        }
        //clear in case of reinitialising or any error happens.
        jQuery("#expressPaymentListContainer").empty();
        jQuery("#embeddedExpressPaymentContainer").empty();

        if (
          typeof VAS !== "undefined" &&
          typeof VAS.UnifiedCheckout === "function" &&
          cc
        ) {
          jQuery(".blocks-error-div-express-uc").hide();
          jQuery(".blocks-failure-error-div-express-uc").hide();

          // UC v1: use VAS.UnifiedCheckout instead of deprecated Accept() API.
          async function launchBlocksExpressCheckout() {
            var mySeq = ++ucBlocksEpMountSeq;
            destroyUcBlocksEpCheckout();
            let client;
            let checkout;
            try {
              client = await VAS.UnifiedCheckout(cc);
              if (mySeq !== ucBlocksEpMountSeq) {
                client.destroy();
                return;
              }
              checkout = await client.createCheckout({ autoProcessing: false });
              if (mySeq !== ucBlocksEpMountSeq) {
                checkout.destroy();
                client.destroy();
                return;
              }
              ucBlocksEpClient = client;
              ucBlocksEpCheckout = checkout;
              const tt = await checkout.mount("#expressPaymentListContainer");
              // Check if any buttons actually rendered (handles case where Apple Pay fails but Google Pay succeeds)
              setTimeout(function () {
                if (
                  jQuery("#expressPaymentListContainer").children().length > 0
                ) {
                  jQuery(".wc-block-components-express-payment").attr(
                    "style",
                    "display: block !important",
                  );
                  jQuery(".wc-block-components-express-payment__content").attr(
                    "style",
                    "display: block !important",
                  );
                  jQuery(
                    ".wc-block-components-express-payment-continue-rule",
                  ).attr("style", "display: block !important");
                }
              }, 200);

              jQuery("#payment-method").hide();
              setExpressCheckout(true);
              // Show loading overlay.
              showExpressPayLoader();
              // Ensure the UC payment method radio button is selected.
              try {
                var ucRadioId =
                  "radio-control-wc-payment-method-options-" +
                  (ucBlocksSettings.visa_acceptance_solutions_uc_id ||
                    "visa_acceptance_solutions_uc");
                var ucRadio = document.getElementById(ucRadioId);
                if (ucRadio && !ucRadio.checked) {
                  ucRadio.click();
                }
              } catch (e) {
                /* ignore */
              }
              transientToken.value = tt;
              jQuery("#transientToken").val(tt);
              uc_token = tt;
              window.uc_token_global = tt;
              // For infinite page loading at checkout.
              jQuery("#embeddedExpressPaymentContainer").empty();
              return blocksCompleteAndContinue(checkout, tt).then(function () {
                populateCheckoutFromTransientToken(tt, true).then(function () {
                  setTimeout(() => {
                    onSubmit();
                    // Monitor if checkout remains idle after submission attempt (validation failed).
                    setTimeout(() => {
                      const checkoutStatus =
                        window.wp.data.select("wc/store/checkout");
                      if (
                        checkoutStatus &&
                        typeof checkoutStatus.isIdle === "function" &&
                        checkoutStatus.isIdle()
                      ) {
                        // Checkout is still idle, meaning validation failed - show form back.
                        hideExpressPayLoader();
                        jQuery("#payment-method").show();
                        jQuery(
                          ".wc-block-components-checkout-place-order-button",
                        ).show();
                      }
                    }, 500);
                  }, 200);
                });
                hideExpressPayLoader();
              });
            } catch (error) {
              jQuery("#expressPaymentListContainer").empty();
              jQuery("#embeddedExpressPaymentContainer").empty();
              jQuery(".wc-block-components-express-payment").hide();
              jQuery(
                ".wc-block-components-express-payment-continue-rule",
              ).hide();
              // Show payment method section back if express pay fails.
              hideExpressPayLoader();
              jQuery("#payment-method").show();
              jQuery(".wc-block-components-checkout-place-order-button").show();
              if (checkout) {
                checkout.destroy();
              }
              if (client) {
                client.destroy();
              }
              if (ucBlocksEpCheckout === checkout) {
                ucBlocksEpCheckout = null;
              }
              if (ucBlocksEpClient === client) {
                ucBlocksEpClient = null;
              }
            }
          }
          launchBlocksExpressCheckout();
        } else {
          jQuery(".wc-block-components-express-payment").hide();
          jQuery(".wc-block-components-express-payment-continue-rule").hide();
          if (cc != null) {
            reloadAfterError();
          }
        }
      }
    } else {
      jQuery(".wc-block-components-express-payment").hide();
      jQuery(".wc-block-components-express-payment-continue-rule").hide();
    }
  }, [captureContext, isIdle, isLoadingCC]);

  var loaderCC = React.createElement(LoadingMask, {
    isLoading: true,
    screenReaderLabel: __(
      "Loading Capture Context",
      "visa-acceptance-solutions",
    ),
    showSpinner: true,
  });

  if (isLoadingCC) {
    return [loaderCC];
  }

  var trasientToken = React.createElement(
    "div",
    null,
    React.createElement("input", {
      type: "hidden",
      id: "transientToken",
      name: "transientToken",
    }),
  );

  var expressPayDiv = React.createElement(
    "div",
    {
      id: "expressPaymentListContainer",
    },
    React.createElement("div", {
      type: "button",
      id: "checkoutEmbedded",
      disabled: "disabled",
    }),
    React.createElement("div", {
      type: "button",
      id: "checkoutSidebar",
      disabled: "disabled",
    }),
  );

  var embed = React.createElement("div", {
    id: "embeddedExpressPaymentContainer",
  });

  var errorDiv = React.createElement(
    "p",
    {
      className: "blocks-error-div-express-uc",
      style: {
        display: "none",
        color: "red",
      },
    },
    ucExpressPaySettings.form_load_error,
  );

  var errorFailure = React.createElement(
    "p",
    {
      className: "blocks-failure-error-div-express-uc",
      style: {
        display: "none",
        color: "red",
      },
    },
    ucExpressPaySettings.failure_error,
  );

  // Adding description at checkout.
  var description = React.createElement(
    "div",
    {},
    ucExpressPaySettings.description,
  );
  var notice = React.createElement(
    window.wc.blocksCheckout.StoreNoticesContainer,
    {
      context: "visa_acceptance_solutions_notice_container",
    },
  );

  return [
    description,
    expressPayDiv,
    trasientToken,
    embed,
    errorDiv,
    errorFailure,
    notice,
  ];
};

// Add function to show place order button on checkout.

function checkAndRemove() {
  if (
    !jQuery(
      "#radio-control-wc-payment-method-options-" +
        ucBlocksSettings.visa_acceptance_solutions_uc_id,
    ).is(":checked")
  ) {
    // Keep hidden when a saved token is active — uc_blocks_saved_token_active is set by the saved card component.
    if (!uc_blocks_saved_token_active) {
      jQuery(".wc-block-components-checkout-place-order-button").show();
    }
  }
}

// Check the condition every 1000 milliseconds (1 second).
setInterval(checkAndRemove, 500);

var canMakePayment = () => {
  return true;
};

var UCId =
  typeof ucBlocksSettings.visa_acceptance_solutions_uc_id === "string" &&
  ucBlocksSettings.visa_acceptance_solutions_uc_id.length
    ? ucBlocksSettings.visa_acceptance_solutions_uc_id
    : "visa_acceptance_solutions_uc";
var ucOptions = {
  name: UCId,
  label: React.createElement("div", {}, ucBlocksSettings.title),
  content: React.createElement(ucComponents, {}, null),
  edit: React.createElement("div", {}, null),
  canMakePayment,
  paymentMethodId: ucBlocksSettings.visa_acceptance_solutions_uc_id,
  ariaLabel: __("Unified Checkout", "visa-acceptance-solutions"),
  supports: {
    showSaveOption: ucBlocksSettings.enable_tokenization,
    features: ucBlocksSettings?.supports ?? [],
  },
  savedTokenComponent: React.createElement(ucblockssavedcomponent, {}, null),
};
registerPaymentMethod(ucOptions);

if (
  ucExpressPaySettings.subscription_order &&
  !ucExpressPaySettings.is_subscriptions_tokenization_enabled
) {
} else {
  if (ucExpressPaySettings && ucExpressPaySettings.enable_express_pay) {
    var expressPayUCId =
      typeof ucExpressPaySettings.express_pay_uc_id === "string" &&
      ucExpressPaySettings.express_pay_uc_id.length
        ? ucExpressPaySettings.express_pay_uc_id
        : "express_pay_uc";
    var expressCheckoutForm = {
      name: expressPayUCId,
      label: React.createElement("div", {}, ucExpressPaySettings.title),
      content: React.createElement(ucExpressPayComponents, {}, null),
      edit: React.createElement("div", {}, null),
      canMakePayment,
      paymentMethodId: ucExpressPaySettings.express_pay_uc_id,
      ariaLabel: __("Express Checkout", "visa-acceptance-solutions"),
      supports: {
        features: ucExpressPaySettings?.supports ?? [],
      },
    };
    registerExpressPaymentMethod(expressCheckoutForm);
  }
}

if (
  ucExpressPaySettings.force_tokenization ||
  ucBlocksSettings.force_tokenization
) {
  window.wp.data
    .dispatch("core/notices")
    .createInfoNotice(
      __(
        "One or more items in your order is a subscription/recurring purchase. By continuing with payment, you agree that your payment method will be automatically charged at the price and frequency listed here until it ends or you cancel.",
        "visa-acceptance-solutions",
      ),
      {
        id: "checkout",
        context: "visa_acceptance_solutions_notice_container",
        isDismissible: false,
      },
    );
}
