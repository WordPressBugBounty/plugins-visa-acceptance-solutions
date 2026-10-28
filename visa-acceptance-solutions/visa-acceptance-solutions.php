<?php
/**
 * Plugin Name: Visa Acceptance Solutions
 * Description: Accept payments in WooCommerce with Visa Acceptance Solutions.
 * Version: 3.0.0
 * Author: Visa Acceptance Solutions
 * Author URI: https://visaacceptance.com
 * Developer: Visa Acceptance Solutions
 * Requires Plugins: woocommerce
 * Requires at least: 7.0
 * Requires PHP: 8.2.0
 * WC tested up to: 11.2.1
 * WC requires at least: 10.3.7
 * Text Domain: visa-acceptance-solutions
 * Domain Path: /languages
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Visa_Acceptance_Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_VERSION', '3.0.0' );

/**
 * Fallback Plugin version.
 */
define( 'VISA_ACCEPTANCE_FALLBACK_VERSION', '1.0.0' );

/**
 * WC version from which idle state feature added.
 */
define( 'VISA_ACCEPTANCE_WC_VERSION_EIGHT_SIX_ZERO', '8.6.0');

/**
 * WC version from which pre delete filter available used to delete token.
 */
define( 'VISA_ACCEPTANCE_WC_VERSION_EIGHT_ONE_ZERO', '8.1.0');

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_NAME', 'Visa-Acceptance-Solutions' );

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_DISPLAY_NAME', 'Visa Acceptance Solutions' );

/**
 * Name of device channel browser.
 */
define('VISA_ACCEPTANCE_DEVICE_CHANNEL_BROWSER', 'Browser');

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME', 'WooCommerce' );

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_API_TYPE', '(REST)' );

/**
* String gateway id constant.
*/
define('VISA_ACCEPTANCE_GATEWAY_ID','visa_acceptance_solutions');

/**
* String gateway id constant.
*/
define('VISA_ACCEPTANCE_SOLUTION_TEXT','solutions');

/**
*
* String gateway id constant.
*/
define('VISA_ACCEPTANCE_GATEWAY_ID_HYPHEN','visa-acceptance-solutions');

/**
*
* Hyphen constant.
*/
define('VISA_ACCEPTANCE_HYPHEN','-');

/**
*
* Underscore constant.
*/
define('VISA_ACCEPTANCE_UNDERSCORE','_');

/**
*
* Slash constant.
*/
define('VISA_ACCEPTANCE_SLASH','/');

/**
*
* Colon slash constant.
*/
define('VISA_ACCEPTANCE_COLON_SLASH','://');

/**
*
* Colon constant.
*/
define('VISA_ACCEPTANCE_COLON',':');

/**
*
* Space constant.
*/
define('VISA_ACCEPTANCE_SPACE',' ');

/**
 *
 * Woocommerce Underscore constant.
 */
define('VISA_ACCEPTANCE_WC_UNDERSCORE', 'wc_');

/**
 *
 * Greater than equal to String Constant.
 */
define('VISA_ACCEPTANCE_GREATER_THAN_OR_EQUAL_TO', '>=');

/**
 *
 * Question mark String Constant.
 */
define('VISA_ACCEPTANCE_QUESTION_MARK', '?');

/**
 *
 * Plugin Domain Name.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_DOMAIN', VISA_ACCEPTANCE_GATEWAY_ID_HYPHEN );

/**
* 
* String gateway id constant.
*/
define('VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE', VISA_ACCEPTANCE_GATEWAY_ID . VISA_ACCEPTANCE_UNDERSCORE);

/**
*
* Unified Checkout id constant.
*/
define('VISA_ACCEPTANCE_GATEWAY_UC', 'unified_checkout');

/**
*
* Unified Checkout id with underscore constant.
*/
define('VISA_ACCEPTANCE_UC_ID', VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE . VISA_ACCEPTANCE_GATEWAY_UC);
 
/**
*
* Express Pay Unified Checkout id.
*/
define('VISA_ACCEPTANCE_EXPRESS_PAY_UC_ID', 'express_pay_unified_checkout');
 
/**
*
* Unified Checkout id with hyphen constant.
*/
define('VISA_ACCEPTANCE_UC_ID_HYPHEN', VISA_ACCEPTANCE_GATEWAY_ID_HYPHEN . '-unified-checkout');

/**
*
* Unified Checkout id constant.
*/
define('VISA_ACCEPTANCE_WC_UC_ID', '_wc_' . VISA_ACCEPTANCE_UC_ID . VISA_ACCEPTANCE_UNDERSCORE);

/**
 *
 * WooCommerce variable product type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_VARIABLE', 'variable');

/**
 *
 * WooCommerce Subscriptions variable subscription product type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_VARIABLE_SUBSCRIPTION', 'variable-subscription');

/**
 *
 * WooCommerce subscription product type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_SUBSCRIPTION', 'subscription');

/**
 *
 * Subscription token meta key String Constant.
 */
define('VISA_ACCEPTANCE_SUBSCRIPTION_TOKEN', 'subscription_token');

/**
 *
 * WooCommerce grouped product type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_GROUPED', 'grouped');

/**
 *
 * WooCommerce product variation type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_VARIATION', 'variation');

/**
 *
 * WooCommerce virtual product type constant.
 */
define('VISA_ACCEPTANCE_PRODUCT_TYPE_VIRTUAL', 'virtual');

/**
*
* Woocommerce constant.
*/
define('VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT', 'woocommerce');

/**
 * Partner Solution ID.
 */
define( 'VISA_ACCEPTANCE_SOLUTION_ID', 'VVQC7VGM');

/**
*
* Network error constant.
*/
define('VISA_ACCEPTANCE_NETWORK_ERROR','NETWORK_ERROR');

/**
 *
 * Host domain for Test Mode.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HOST_APITEST', 'apitest.visaacceptance.com' );

/**
 *
 * Host domain for Production Mode.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION', 'api.visaacceptance.com' );

/**
 *
 * Cardinal Cruise Test Library.
 */
define( 'VISA_ACCEPTANCE_ONLINE_METRIX','https://h.online-metrix.net/fp/tags');

/**
 *
 * Used to set the priority parameter for add_action() method.
 */
define( 'VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY', 11 );

/**
 *
 * Authorization constant.
 */
define( 'VISA_ACCEPTANCE_AUTHORIZATION', 'Authorization' );
/**
 *
 * The production environment identifier Constant.
 */
define('VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION', 'production');

/**
 *
 * The test environment identifier Constant.
 */
define('VISA_ACCEPTANCE_ENVIRONMENT_TEST', 'test');

/**
 *
 * The fraud webhook subscription toggle setting name Constant.
 */
define('VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK', 'enable_fraud_webhook');

/**
 *
 * The organization ID setting name Constant.
 */
define('VISA_ACCEPTANCE_SETTING_ORGANIZATION_ID', 'organization_id');

/**
 *
 * Sends through sale and request for funds to be charged to cardholder's credit card Constant.
 */
define('VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE', 'charge');

/**
 *
 * Sends through an authorization request to verify that the cardholder has sufficient funds available on their credit card. Funds are not captured until a separate capture request is sent.
 */
define('VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION', 'authorization');

/**
 *
 * Complete Mandate type for auth + capture flow.
 */
define('VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE', 'CAPTURE');

/**
 *
 * Complete Mandate type for auth only flow.
 */
define('VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_PREFER_AUTH', 'PREFER_AUTH');

/**
 *
 * Complete Mandate  string.
 */
define('VISA_ACCEPTANCE_COMPLETE_MANDATE_STRING', 'Complete');

/**
 *
 * APM string.
 */
define('VISA_ACCEPTANCE_APM_STRING', 'apm');

/**
 *
 * 200 Status Code Constant.
 */
define('VISA_ACCEPTANCE_TWO_ZERO_ZERO', 200);

/**
 *
 * 200 Status Code Constant.
 */
define('VISA_ACCEPTANCE_THREE_ZERO_ZERO', 300);

/**
 *
 * 201 Status Code Constant.
 */
define('VISA_ACCEPTANCE_TWO_ZERO_ONE', 201);

/**
 *
 * 204 Status Code Constant.
 */
define('VISA_ACCEPTANCE_TWO_ZERO_FOUR', 204);

/**
 *
 * 401 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_ZERO_ONE', 401);

/**
 *
 * 403 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_ZERO_THREE', 403);

/**
 *
 * 502 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FIVE_ZERO_TWO', 502);

/**
 *
 * 203 Status Code Constant.
 */
define('VISA_ACCEPTANCE_TWO_ZERO_THREE', 203);

/**
 *
 * 400 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_ZERO_ZERO', 400);

/**
 *
 * 500 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FIVE_ZERO_ZERO', 500);

/**
 *
 * 404 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_ZERO_FOUR', 404);

/**
 *
 * 410 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_TEN', 410);

/**
 *
 * Sca challange Code Constant.
 */
define('VISA_ACCEPTANCE_SCA_CHALLANGE_CODE', '04');

/**
 *
 * SCA Cookie: tracks whether a 3DS challenge retry is needed for the complete mandate flow (CC).
 */
define( 'VISA_ACCEPTANCE_COOKIE_USE_3DS_CHALLENGE', 'use3DSChallenge' );

/**
 *
 * Constant for 3DS ExpressPay Challenge (SCA)
 */
define( 'VISA_ACCEPTANCE_COOKIE_USE_3DS_EP_CHALLENGE', 'use3DSChallengeEP' );

/**
 *
 * Constant for 3DS TMS Challenge (SCA)
 */
define( 'VISA_ACCEPTANCE_COOKIE_USE_3DS_TMS_CHALLENGE', 'use3DSChallengeTMS' );

/**
 *
 * Constant for SCA retry count
 */
define( 'VISA_ACCEPTANCE_COOKIE_SCA_COUNT', 'scaCount' );

/**
 *
 * Constant for SCA error message
 */
define( 'VISA_ACCEPTANCE_COOKIE_ERROR_MSG', 'errorMsg' );

/**
 *
 * Success constant.
 */
define('VISA_ACCEPTANCE_SUCCESS', 'success');

/**
 *
 * Success constant.
 */
define('VISA_ACCEPTANCE_FAILURE', 'failure');

/**
 *
 * Card Constant.
 */
define('VISA_ACCEPTANCE_CARD', 'card');

/**
 *
 * Card Constant.
 */
define('VISA_ACCEPTANCE_CC_PLAIN', 'cc-plain');

/**
 *
 * Card Constant.
 */
define('VISA_ACCEPTANCE_SVG_EXTENSION', '.svg');

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO', 0.00 );

/**
 *
 * Dummy Amount Constant.
 */
define('VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT', '0.01');

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_ZERO', 0 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_ONE', 1 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_TWO', 2 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_THREE', 3 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_FOUR', 4 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_FIVE', 5 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_NINE', 9 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_TEN', 10 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_ELEVEN', 11 );

/**
 * Integer value constant.
 *
 */
define( 'VISA_ACCEPTANCE_VAL_SIX_ZERO', 60 );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_STRING_ALL', 'all' );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_STRING_CUSTOMER_AUTHENTICATION_REQUIRED', 'CUSTOMER_AUTHENTICATION_REQUIRED' );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_ERROR', 'error' );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_WARNING', 'warning' );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_STRING_EMPTY', '' );

/**
 * string value constant.
 *
 */
define( 'VISA_ACCEPTANCE_FULL_STOP', '.' );

/**
 * HTML line break String Constant.
 *
 */
define( 'VISA_ACCEPTANCE_BR', '<br>' );

/**
 *
 * VISA_ACCEPTANCE_PANENTRY Constant.
 */
define('VISA_ACCEPTANCE_PANENTRY', 'PANENTRY');

/**
 *
 * VISA_ACCEPTANCE_CHECK Constant.
 */
define('VISA_ACCEPTANCE_CHECK', 'CHECK');

/**
 *
 * VISA_ACCEPTANCE_PAYMENT_TYPE_CARD Constant.
 */
define('VISA_ACCEPTANCE_PAYMENT_TYPE_CARD', 'CARD');

/**
 *
 * Api Response for eCheck/ACH Transmitted Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED', 'TRANSMITTED' );

/**
 *
 * Api Response for eCheck/ACH pending Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS', 'PENDING' );

/**
 *
 * Api Response for eCheck/ACH DM pending Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS', 'PENDING_REVIEW' );

/**
 *
 * eCheck SEC Code for internet-initiated transactions.
 */
define( 'VISA_ACCEPTANCE_ECHECK_SEC_CODE_WEB', 'WEB' );

/**
 *
 * eCheck token type constant.
 */
define( 'VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK', 'eCheck' );

/**
 *
 * GooglePay Constant.
 */
define('VISA_ACCEPTANCE_GOOGLEPAY', 'GOOGLEPAY');

/**
 *
 * ApplePay Constant.
 */
define('VISA_ACCEPTANCE_APPLEPAY', 'APPLEPAY');

/**
 *
 * Paze Constant.
 */
define('VISA_ACCEPTANCE_PAZE', 'PAZE');

/**
 *
 * VCO Constant for higher versions.
 */
define('VISA_ACCEPTANCE_CLICKTOPAY', 'CLICKTOPAY');

/**
 *
 * PayPal Constant.
 */
define('VISA_ACCEPTANCE_PAYPAL', 'PAYPAL');
 
/**
 *
 * Venmo Constant.
 */
define('VISA_ACCEPTANCE_VENMO', 'VENMO');
 
/**
 *
 * Afterpay Constant.
 */
define('VISA_ACCEPTANCE_AFTERPAY', 'AFTERPAY');
 
/**
 * Refresh Payment Status Action.
 */
define('VISA_ACCEPTANCE_WC_REFRESH_STATUS_ACTION', 'wc_refresh_payment_status_action');

/**
 *
 * iDEAL Constant.
 */
define('VISA_ACCEPTANCE_IDEAL', 'IDEAL');
 
/**
 *
 * Multibanco Constant.
 */
define('VISA_ACCEPTANCE_MULTIBANCO', 'MULTIBANCO');
 
/**
 *
 * Przelewy24 Constant.
 */
define('VISA_ACCEPTANCE_PRZELEWY24', 'PRZELEWY24');
 
/**
 *
 * MyBank Constant.
 */
define('VISA_ACCEPTANCE_MYBANK', 'MYBANK');
 
/**
 *
 * Dragonpay Constant.
 */
define('VISA_ACCEPTANCE_DRAGONPAY', 'DRAGONPAY');
 
/**
 *
 * Bancontact Constant.
 */
define('VISA_ACCEPTANCE_BANCONTACT', 'BANCONTACT');
 
/**
 *
 * Tink Pay by Bank Constant.
 */
define('VISA_ACCEPTANCE_TINKPAYBYBANK', 'TINKPAYBYBANK');
 
/**
 *
 * Konbini Constant.
 */
define('VISA_ACCEPTANCE_KONBINI', 'KONBINI');
 

/**
 *
 * Zero Amount Constant.
 */
define('VISA_ACCEPTANCE_ZERO_AMOUNT', '0.00');

/**
 *
 * Unified Checkout Client Version(Default).
 */
define('VISA_ACCEPTANCE_UC_CLIENT_VERSION', VISA_ACCEPTANCE_STRING_EMPTY);

/**
 *
 * $1 card amount Constant.
 */
define('VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT', '1.00');

/**
 *
 * CUP card type Constant.
 */
define('VISA_ACCEPTANCE_CUP_CARD_TYPE', '062');

/**
 *
 * CUP GPAY card type Constant.
 */
define('VISA_ACCEPTANCE_GPAY_PAYMENTSOLUTION_VALUE', '012');

/**
 *
 * JAYWAN card type Constant.
 */
define('VISA_ACCEPTANCE_JAYWAN_CARD_TYPE', '081');

/**
 *
 * China UnionPay card network name Constant.
 */
define('VISA_ACCEPTANCE_CHINA_UNION_PAY', 'China UnionPay');

/**
 *
 * JAYWAN card network name Constant.
 */
define('VISA_ACCEPTANCE_JAYWAN', 'JAYWAN');

/**
 *
 * Unified Checkout Billing Type.
 */
define('VISA_ACCEPTANCE_UC_BILLING_TYPE', 'NONE');

/**
 *
 * Unified Checkout Billing Type Full.
 */
define('VISA_ACCEPTANCE_UC_BILLING_TYPE_FULL', 'FULL');

/**
 *
 * Unified Checkout Billing Type PARTIAL.
 */
define('VISA_ACCEPTANCE_UC_BILLING_TYPE_PARTIAL', 'PARTIAL');

/**
 *
 * WooCommerce Underscore String Constant.
 */
define('VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE', 'woocommerce_');

/**
 *
 * Reverse Tokenisation Constant.
 */
define('VISA_ACCEPTANCE_REVERSE_TOKENISATION_AMOUNT', 'Reverse Tokenization Amount');

/**
 *
 * Reverse Tokenisation Constant.
 */
define('VISA_ACCEPTANCE_V_C_CORRELATION_ID', 'v-c-correlation-id');

/**
 *
 * Settings Underscore String Constant.
 */
define('VISA_ACCEPTANCE_UNDERSCORE_SETTINGS', '_settings');

/**
 *
 * Edit string Constant.
 */
define('VISA_ACCEPTANCE_EDIT','edit');

/**
 *
 * Edit post string Constant.
 */
define('VISA_ACCEPTANCE_EDIT_POST','editpost');

/**
 *
 * Edit order string Constant.
 */
define('VISA_ACCEPTANCE_EDIT_ORDER','edit_order');

/**
 *
 * Shop order string Constant.
 */
define('VISA_ACCEPTANCE_SHOP_ORDER','shop_order');

/**
 *
 * Mark processing String Constant.
 */
define('VISA_ACCEPTANCE_MARK_PROCESSING', 'mark_processing');

/**
 *
 * Mark completed String Constant.
 */
define('VISA_ACCEPTANCE_MARK_COMPLETED', 'mark_completed');

/**
 *
 * wc orders String Constant.
 */
define('VISA_ACCEPTANCE_WC_ORDERS', 'wc-orders');


/**
 *
 * Add payment method String Constant.
 */
define('VISA_ACCEPTANCE_ADD_PAYMENT_METHOD', 'add_payment_method');

/**
 *
 * Add card String Constant.
 */
define('VISA_ACCEPTANCE_ADD_CARD', 'ADD_CARD');

/**
 *
 * Tokenization String Constant.
 */
define('VISA_ACCEPTANCE_TOKENIZATION', 'tokenization');


/**
 *
 * Refunds String Constant.
 */
define('VISA_ACCEPTANCE_REFUNDS', 'refunds');

/**
 *
 * Edit shop order String Constant.
 */
define('VISA_ACCEPTANCE_EDIT_SHOP_ORDER', 'edit_shop_order');

/**
 *
 * Auth transaction id String Constant.
 */
define('VISA_ACCEPTANCE_TRANSACTION_ID', 'transaction_id');

/**
 *
 * Capture transaction id String Constant.
 */
define('VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID', 'capture_transaction_id');

/**
 *
 * Refund transaction id String Constant.
 */
define('VISA_ACCEPTANCE_REFUND_TRANSACTION_ID', 'refund_transaction_id');

/**
 *
 * Auth reversal transaction id String Constant.
 */
define('VISA_ACCEPTANCE_VOID_TRANSACTION_ID', 'void_transaction_id');

/**
 *
 * Request String Constant.
 */
define('VISA_ACCEPTANCE_REQUEST', ' Request');

/**
 *
 * Response String Constant.
 */
define('VISA_ACCEPTANCE_RESPONSE', ' Response');


/**
 *
 * void amount String Constant.
 */
define('VISA_ACCEPTANCE_VOID_AMOUNT', 'void_amount');

/**
 *
 * Refund amount String Constant.
 */
define('VISA_ACCEPTANCE_REFUND_AMOUNT', 'refund_amount');

/**
 *
 * Charge captured String Constant.
 */
define('VISA_ACCEPTANCE_CHARGE_CAPTURED', 'charge_captured');

/**
 *
 * Merchant reference code String Constant.
 */
define('VISA_ACCEPTANCE_MERCHANT_REF_CODE', 'merchant_ref_code');

/**
 *
 * Get transaction String Constant.
 */
define('VISA_ACCEPTANCE_GET_TRANSACTION', 'get_transaction');

/**
 *
 * wc capture action String Constant.
 */
define('VISA_ACCEPTANCE_WC_CAPTURE_ACTION', 'wc_capture_action');

/**
 *
 * Nonce String Constant.
 */
define('VISA_ACCEPTANCE_NONCE', 'nonce');

/**
 *
 * Partial String Constant.
 */
define('VISA_ACCEPTANCE_PARTIAL', 'partial');

/**
 *
 * Update card String Constant.
 */
define('VISA_ACCEPTANCE_UPDATECARD', 'updateCard');

/**
 *
 * Capture String Constant.
 */
define('VISA_ACCEPTANCE_CAPTURE', 'capture');

/**
 *
 * Capture String Constant.
 */
define('VISA_ACCEPTANCE_PARTIAL_CAPTURE', 'partial capture');

/**
 *
 * Auth reversal String Constant.
 */
define('VISA_ACCEPTANCE_AUTH_REVERSAL', 'authReversal');

/**
 *
 * Refund String Constant.
 */
define('VISA_ACCEPTANCE_REFUND', 'Refund');

/**
 *
 * Capture total String Constant.
 */
define('VISA_ACCEPTANCE_CAPTURE_TOTAL', 'capture_total');

/**
 *
 * _payment method String Constant.
 */
define('VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_METHOD', '_payment_method');

/**
 *
 * _payment status String Constant.
 */
define('VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_STATUS', '_payment_status');

/**
 *
 * _void failed String Constant.
 */
define('VISA_ACCEPTANCE_UNDERSCORE_VOID_FAILED', '_void_failed');

/**
 *
 * Auth amount String Constant.
 */
define('VISA_ACCEPTANCE_AUTH_AMOUNT', 'auth_amount');

/**
 *
 * Trans date String Constant.
 */
define('VISA_ACCEPTANCE_TRANSACTION_DATE', 'transaction_date');

/**
 *
 * Payment Acceptance service String Constant.
 */
define('VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE', 'payment_acceptance_service');

/**
 *
 * Shipping and hadeling String Constant.
 */
define('VISA_ACCEPTANCE_SHIPPING_AND_HANDELING', 'shipping_and_handling');

/**
 *
 * Invalid amount String Constant.
 */
define('VISA_ACCEPTANCE_INVALID_AMOUNT', 'Invalid amount');

/**
 *
 * Authorization reversal String Constant.
 */
define('VISA_ACCEPTANCE_AUTHORIZATION_REVERSAL', 'Authorization Reversal');

/**
 *
 * /reversalS String Constant.
 */
define('VISA_ACCEPTANCE_SLASH_REVERSALS', '/reversals');

/**
 *
 * /captures String Constant.
 */
define('VISA_ACCEPTANCE_SLASH_CAPTURES', '/captures');

/**
 *
 * Capture context String Constant.
 */
define('VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT', 'UC Capture Context');

/**
 * Credit Card label String Constant.
 */
define( 'VISA_ACCEPTANCE_CREDIT_CARD', 'Credit Card' );

/**
 * Express Pay label String Constant.
 */
define( 'VISA_ACCEPTANCE_EXPRESS_PAY', 'Express Pay' );

/**
 * N/A String Constant.
 */
define( 'VISA_ACCEPTANCE_NOT_APPLICABLE', 'N/A' );

/**
 *
 * Card delete String Constant.
 */
define('VISA_ACCEPTANCE_CARD_DELETE', 'Card Delete');

/**
 *
 * Credit auth code String Constant.
 */
define('VISA_ACCEPTANCE_CREDIT_AUTH_CODE', 'credit_auth_code');
 
/**
 *
 * Credit auth response String Constant.
 */
define('VISA_ACCEPTANCE_CREDIT_AUTH_RESPONSE', 'credit_auth_response');
 
/**
 *
 * Network transaction id String Constant.
 */
define('VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID', 'network_transaction_id');

/**
 *
 * Environment String Constant.
 */
define('VISA_ACCEPTANCE_ENVIRONMENT', 'environment');

/**
 *
 * Is default String Constant.
 */
define('VISA_ACCEPTANCE_IS_DEFAULT', 'is_default');

/**
 *
 * Active String Constant.
 */
define('VISA_ACCEPTANCE_ACTIVE', 'ACTIVE');

/**
 *
 * Decision skip String Constant.
 */
define('VISA_ACCEPTANCE_DECISION_SKIP', 'DECISION_SKIP');

/**
 *
 * Fraud webhook REST API namespace.
 */
define( 'VISA_ACCEPTANCE_NAMESPACE', 'visa-acceptance/v2' );
 
/**
 *
 * Fraud webhook REST API route.
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_ROUTE', '/fraud-webhook' );
 
/**
 *
 * WordPress option key for storing the CyberSource fraud webhook subscription ID (Decision Manager).
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION', 'visa_acceptance_fraud_webhook_id' );
 
/**
 *
 * WordPress option key for storing the CyberSource Fraud Management Essentials webhook subscription ID.
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION', 'visa_acceptance_fraud_webhook_fme_id' );
 
/**
 *
 * WordPress option key for storing the CyberSource fraud webhook HMAC signing secret.
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_SECRET_OPTION', 'visa_acceptance_fraud_webhook_secret' );
 
/**
 *
 * WordPress option key for storing the CyberSource fraud webhook signing key ID (from /kms/egress/v2/keys-sym).
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_KEY_ID_OPTION', 'visa_acceptance_fraud_webhook_key_id' );
 
/**
 *
 * WordPress option key for storing the random URL token used to secure the webhook endpoint.
 */
define( 'VISA_ACCEPTANCE_FRAUD_WEBHOOK_TOKEN_OPTION', 'visa_acceptance_fraud_webhook_token' );
 
 
/**
 *
 * CyberSource notification subscriptions API resource path.
 */
define( 'VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH', '/notification-subscriptions/v2/webhooks' );
 
/**
 * UC webhook REST API route.
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_ROUTE', '/uc-webhook' );
 
/**
 * APM webhook REST API route.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_ROUTE', '/apm-webhook' );
 
/**
 * CyberSource event type for APM payment updates (bank redirect / async payment completion).
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_EVENT', 'payments.payments.updated' );
 
/**
 * WordPress option key for storing the APM webhook subscription ID.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION', 'visa_acceptance_apm_webhook_id' );

/**
 * Network Tokens webhook REST route (appended to namespace).
 */
define( 'VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_ROUTE', '/network-tokens-webhook' );
 
/**
 * WordPress option key for storing the Network Tokens webhook subscription ID.
 */
define( 'VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_ID_OPTION', 'visa_acceptance_network_tokens_webhook_id' );
 
/**
 * WordPress option key for storing the asymmetric MLE key ID used to encrypt APM webhook payloads.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_MLE_KEY_ID_OPTION', 'visa_acceptance_apm_webhook_mle_key_id' );
 
/**
 * WordPress option key for storing the RSA private key PEM used to decrypt APM webhook JWE payloads.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_PRIVATE_KEY_OPTION', 'visa_acceptance_apm_webhook_private_key' );
 
/**
 * WordPress option key for storing the APM webhook HMAC shared-secret key (base64) for v-c-signature validation.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_OPTION', 'visa_acceptance_apm_webhook_sig_key' );
 
/**
 * WordPress option key for storing the APM webhook HMAC key ID for v-c-signature validation.
 */
define( 'VISA_ACCEPTANCE_APM_WEBHOOK_SIG_KEY_ID_OPTION', 'visa_acceptance_apm_webhook_sig_key_id' );
 
/**
 * WordPress option key for storing the UC webhook subscription ID.
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION', 'visa_acceptance_uc_webhook_id' );
 
/**
 * WordPress option key for storing the asymmetric MLE key ID (from /kms/egress/v2/keys-asym).
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_MLE_KEY_ID_OPTION', 'visa_acceptance_uc_webhook_mle_key_id' );
 
/**
 * WordPress option key for storing the RSA private key PEM used to decrypt UC webhook JWE payloads.
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_PRIVATE_KEY_OPTION', 'visa_acceptance_uc_webhook_private_key' );
 
/**
 * WordPress option key for storing the UC webhook HMAC shared-secret key (base64) for v-c-signature validation.
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_OPTION', 'visa_acceptance_uc_webhook_sig_key' );
 
/**
 * WordPress option key for storing the UC webhook HMAC key ID for v-c-signature validation.
 */
define( 'VISA_ACCEPTANCE_UC_WEBHOOK_SIG_KEY_ID_OPTION', 'visa_acceptance_uc_webhook_sig_key_id' );
 
/**
 * WordPress option key for storing the Network Tokens webhook subscription token (query parameter).
 */
define( 'VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_TOKEN_OPTION', 'visa_acceptance_network_tokens_webhook_token' );
 
/**
 * WordPress option key for storing the Network Tokens webhook HMAC shared-secret key (base64).
 */
define( 'VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_SIG_KEY_OPTION', 'visa_acceptance_network_tokens_webhook_sig_key' );
 
/**
 * WordPress option key for storing the Network Tokens webhook HMAC key ID.
 */
define( 'VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_SIG_KEY_ID_OPTION', 'visa_acceptance_network_tokens_webhook_sig_key_id' );

/**
 *
 * Order meta key flagging whether a DM-review order was a charge (sale) or auth-only.
 */
define( 'VISA_ACCEPTANCE_DM_REVIEW_IS_CHARGE_META', '_dm_review_is_charge' );

/**
 *
 * Token create String Constant.
 */
define('VISA_ACCEPTANCE_TOKEN_CREATE', 'TOKEN_CREATE');

/**
 *
 * Accept String Constant.
 */
define('VISA_ACCEPTANCE_ACCEPT', 'ACCEPT');

/**
 *
 * Card settlement String Constant.
 */
define('VISA_ACCEPTANCE_CARD_SETTLEMENT_SUCCEEDED', 'Card Settlement succeeded');

/**
 *
 * Is String Constant.
 */
define('VISA_ACCEPTANCE_IS', 'is');

/**
 *
 * Consumer authentication String Constant.
 */
define('VISA_ACCEPTANCE_CONSUMER_AUTHENTICATION', 'CONSUMER_AUTHENTICATION');

/**
 *
 * Validate Consumer authentication String Constant.
 */
define('VISA_ACCEPTANCE_VALIDATE_CONSUMER_AUTHENTICATION', 'VALIDATE_CONSUMER_AUTHENTICATION');

/**
 *
 * ISC auth reversal String Constant.
 */
define('VISA_ACCEPTANCE_ISC_AUTH_REVERSAL', 'ics_auth_reversal');

/**
 *
 * Auto auth reversal String Constant.
 */
define('VISA_ACCEPTANCE_AUTO_AUTH_REVERSAL', 'auto_auth_reversal');

/**
 *
 * Reserved String Constant.
 */
define('VISA_ACCEPTANCE_RESERVED', 'REVERSED');

/**
 *
 * Payment methods String Constant.
 */
define('VISA_ACCEPTANCE_PAYMENT_METHODS', 'payment-methods');

/**
 *
 * Card deletion String Constant.
 */
define('VISA_ACCEPTANCE_CARD_DELETION', 'Card Deletion');

/**
 *
 * Update Card String Constant.
 */
define('VISA_ACCEPTANCE_INVALID_DATA', 'Invalid data');

/**
 *
 * sha256 String Constant.
 */
define('VISA_ACCEPTANCE_ALGORITHM_SHA256', 'sha256'); 

/**
 *
 * Start time String Constant.
 */
define('VISA_ACCEPTANCE_REPORT_START_TIME', '-1 days 0 hours');

/**
 *
 * End time String Constant.
 */
define('VISA_ACCEPTANCE_REPORT_END_TIME', '0 hours');

/**
 *
 * Date year time String Constant.
 */
define('VISA_ACCEPTANCE_DATE_Y_M_D_TH_I_S', 'Y-m-d\TH:i:s');

/**
 *
 * Date time format String Constant.
 */
define('VISA_ACCEPTANCE_DATE_Y_M_D_H_I_S', 'Y-m-d H:i:s');

/**
 *
 * Order Pay Constant.
 */
define('VISA_ACCEPTANCE_ORDER_PAY', '/order-pay/');

/**
 *
 * YES Constant.
 */
define('VISA_ACCEPTANCE_YES', 'yes');

/**
 *
 * No Constant.
 */
define('VISA_ACCEPTANCE_NO', 'no');

/**
 *
 * Callback Invalid ID error Constant.
 */
define('VISA_ACCEPTANCE_CALLBACK_INVALID_ID_ERROR', 'Invalid order ID');

/**
 *
 * Callback Invalid Permissions error Constant.
 */
define('VISA_ACCEPTANCE_CALLBACK_INVALID_PERMISSIONS_ERROR', 'Invalid permissions');

/**
 *
 * Callback Invalid Payment Method error Constant.
 */
define('VISA_ACCEPTANCE_CALLBACK_INVALID_PAYMENT_METHOD_ERROR', 'Invalid payment method');

/**
 *
 * ADMIN Constant.
 */
define('VISA_ACCEPTANCE_ADMIN', 'admin');

/**
 *
 * Completed Constant.
 */
define('VISA_ACCEPTANCE_STRING_COMPLETED', 'COMPLETED');

/**
 *
 * Pending authentication status.
 */
define('VISA_ACCEPTANCE_PENDING_AUTHENTICATION', 'PENDING_AUTHENTICATION');
/**
 *
 * Payment Solution string Constant.
 */
define('VISA_ACCEPTANCE_PAYMENT_SOLUTION', 'payment_solution');

/**
 *
 * Saved card blocks string Constant.
 */
define('VISA_ACCEPTANCE_SAVED_CARD_BLOCKS', 'is_save_card_blocks');

/**
 *
 * Saved card normal string Constant.
 */
define('VISA_ACCEPTANCE_SAVED_CARD_NORMAL', VISA_ACCEPTANCE_GATEWAY_ID . '_saved_token_');

/**
 *
 * WooCommerce Order Status after Settlement.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING', 'processing' );

/**
 *
 * WooCommerce Order Status after Authorization.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD', 'on-hold' );

/**
 *
 * WooCommerce Order Status after Auth Reversal.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED', 'cancelled' );

/**
 *
 * WooCommerce Order Status after Refund.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED', 'refunded' );

/**
 *
 * WooCommerce Order Status after Error.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED', 'failed' );

/**
 *
 * WooCommerce Default Order Status.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING', 'pending' );
/**
 *
 * WooCommerce Order Status for captured order.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED', 'completed' );

/**
 *
 * WooCommerce Subscription Status for active subscriptions.
 */
define( 'VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ACTIVE', 'active' );

/**
 *
 * Api Response (EBC) Authorized Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED', 'AUTHORIZED' );

/**
 *
 * Trusted host for CyberSource-hosted UC client library redirects.
 */
define( 'VISA_ACCEPTANCE_TRUSTED_HOST_CYBERSOURCE', 'cybersource.com' );
/**
 *
 * Trusted host for Visa-hosted UC client library redirects.
 */
define( 'VISA_ACCEPTANCE_TRUSTED_HOST_VISA', 'visa.com' );

/**
 *
 * Api Response (EBC) DM Authorized Decline Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_RISK_DECLINED', 'AUTHORIZED_RISK_DECLINED' );

/**
 *
 * Api Response (EBC) DM Review Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW', 'AUTHORIZED_PENDING_REVIEW' );

/**
 *
 * Api Response (EBC) DM Decision Reject.
 */
define('VISA_ACCEPTANCE_API_RESPONSE_STATUS_DECISION_REJECT', 'DECISION_REJECT');

/**
 *
 * Api Response (EBC) DM Profile Reject.
 */
define('VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT', 'DECISION_PROFILE_REJECT'); 

/**
 *
 * Payment adapter internet constant.
 */
define('VISA_ACCEPTANCE_INTERNET', 'internet');

/**
 *
 * Payment adapter internet constant.
 */
define('VISA_ACCEPTANCE_RECURRING', 'recurring');

/**
 *
 * Device Fingerprint Org ID for Test Environment.
 */
define( 'VISA_ACCEPTANCE_DF_ORG_ID_TEST', '1snn5n9w');

/**
 *
 * Device Fingerprint Org ID for Production Environment.
 */
define( 'VISA_ACCEPTANCE_DF_ORG_ID_PROD', 'k8vif92e');

/**
 *
 * UTF-8 Encoding Constant.
 */
define( 'VISA_ACCEPTANCE_UTF_8', 'UTF-8');

/**
 *
 * AVS Failed Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_AVS_FAILED', 'AVS_FAILED');

/**
 *
 * Define POST constant.
 */
define('VISA_ACCEPTANCE_REQUEST_METHOD_POST', 'POST');

/**
 *
 * Define PATCH constant.
 */
define('VISA_ACCEPTANCE_REQUEST_METHOD_PATCH', 'PATCH');

/**
 *
 * Define GET constant.
 */
define('VISA_ACCEPTANCE_REQUEST_METHOD_GET', 'GET');

/**
 *
 * Define DELETE constant.
 */
define('VISA_ACCEPTANCE_REQUEST_METHOD_DELETE', 'DELETE');

/**
 *
 * Contact Processor Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_CONTACT_PROCESSOR', 'CONTACT_PROCESSOR');

/**
 *
 * Expired Card Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_EXPIRED_CARD', 'EXPIRED_CARD');

/**
 *
 * Processor Declined Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_PROCESSOR_DECLINED', 'PROCESSOR_DECLINED');

/**
 *
 * Insufficient Fund Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_INSUFFICIENT_FUND', 'INSUFFICIENT_FUND');

/**
 *
 * Stolen lost card Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_STOLEN_LOST_CARD', 'STOLEN_LOST_CARD');

/**
 *
 * CVN not match Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_CVN_NOT_MATCH', 'CVN_NOT_MATCH');

/**
 *
 * Exceeds credit limit Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_EXCEEDS_CREDIT_LIMIT', 'EXCEEDS_CREDIT_LIMIT');

/**
 *
 * Invalid CVN Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_INVALID_CVN', 'INVALID_CVN');

/**
 *
 * Declined check Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_DECLINED_CHECK', 'DECLINED_CHECK');

/**
 *
 * CV Failed Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_CV_FAILED', 'CV_FAILED');

/**
 *
 * Invalid Account Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_INVALID_ACCOUNT', 'INVALID_ACCOUNT');

/**
 *
 * Date time constant.
 */
define('VISA_ACCEPTANCE_DATE_TIME', 'D, d M Y G:i:s ');

/**
 *
 * All Allowed card types for UC capture context.
 */
define('VISA_ACCEPTANCE_DEFAULT_CARD_TYPES', array('VISA','MASTERCARD','AMEX','DISCOVER'));
 
/**
 *
 * For Uninstall Module.
 */
define('VISA_ACCEPTANCE_ACTION_SCHEDULER_ID','visa-acceptance-solutions');

/**
 *
 * General Decline Detailed message.
 */
define('VISA_ACCEPTANCE_REASON_GENERAL_DECLINE', 'GENERAL_DECLINE');

/**
 *
 * SV gateway id.
 */
define( 'VISA_ACCEPTANCE_SV_GATEWAY_ID', 'cybersource_credit_card' );

/**
 *
 * Registers the activation hook for our plugin.
 */
register_activation_hook( __FILE__, 'visa_acceptance_solutions_activate' );

/**
 *
 * Registers the deactivation hook for our plugin.
 */
register_deactivation_hook( __FILE__, 'visa_acceptance_solutions_deactivate' );
 
/**
 * The code that runs during plugin activation.
 */
function visa_acceptance_solutions_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-visa-acceptance-payment-gateway-activator.php';
	Visa_Acceptance_Payment_Gateway_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function visa_acceptance_solutions_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-visa-acceptance-payment-gateway-deactivator.php';
	Visa_Acceptance_Payment_Gateway_Deactivator::deactivate();
}

/**
 * Function for delaying initialization of the extension until after WooComerce is loaded.
 */
function visa_acceptance_extension_initialize() {
	require_once 'includes/class-visa-acceptance-solutions.php';
	if ( file_exists (__DIR__ . '/vendor-prefixed/autoload.php'))
        {
        require __DIR__ . '/vendor-prefixed/autoload.php';
    }
	if ( class_exists( VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT ) ) {
	    $GLOBALS['visa_acceptance_' . VISA_ACCEPTANCE_SOLUTION_TEXT] = Visa_Acceptance_Solutions::instance();
	} else
	{
		return;
	}

}

add_action( 'plugins_loaded', 'visa_acceptance_extension_initialize', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY );

add_action( 'woocommerce_blocks_loaded', 'visa_acceptance_block_support_for_gateway' );

add_action( 'before_woocommerce_init', 'visa_acceptance_hpos_compatibility' );

/**
 * Handles hpos compatibility
 *
 * @return boolean
 */
function visa_acceptance_hpos_compatibility() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
	}
}

/**
 * Handles hpos compatibility
 *
 * @return boolean
 */
function visa_acceptance_handle_hpos_compatibility() {
	$status = false;
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        if(class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $status = true;
            }
        }
    }
	return $status;
}

/**
 * Woocommerce block support for the gateway.
 *
 * @return void
 */
function visa_acceptance_block_support_for_gateway() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once 'includes/gateway/class-visa-acceptance-blocks-handler-unified-checkout.php';
		require_once 'includes/gateway/class-visa-acceptance-express-pay-unified-checkout.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Visa_Acceptance_Blocks_Handler_Unified_Checkout() );
				$payment_method_registry->register( new Visa_Acceptance_Express_Pay_Unified_Checkout() );
			}
		);
	}
}
