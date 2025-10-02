<?php
/**
 * Plugin Name: Visa Acceptance Solutions
 * Description: Accept payments in WooCommerce with Visa Acceptance Solutions.
 * Version: 2.0.0
 * Author: Visa Acceptance Solutions
 * Author URI: https://visaacceptance.com
 * Developer: Visa Acceptance Solutions
 * Requires Plugins: woocommerce
 * Requires at least: 6.1
 * Tested up to: 6.8
 * Requires PHP: 8.0.0
 * WC requires at least: 7.6.0
 * WC tested up to: 10.0.4
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
define( 'VISA_ACCEPTANCE_PLUGIN_VERSION', '2.0.0' );

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
 * Payer Auth validation callback hearer to js.
 */
define('VISA_ACCEPTANCE_CONTENT_TYPE_HEADER','Content-Type: text/html');

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_NAME', 'Visa-Acceptance-Solutions' );

/**
 * Name of the current plugin.
 */
define( 'VISA_ACCEPTANCE_PLUGIN_DISPLAY_NAME', 'Visa Acceptance Solutions' );

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
* Hyphen constant.
*/
define('VISA_ACCEPTANCE_WC_UNDERSCORE','wc_');

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
 * String payer auth response slug
 */

 define('VISA_ACCEPTANCE_PAYER_AUTH_RESPONSE_SLUG', VISA_ACCEPTANCE_GATEWAY_ID_UNDERSCORE.'payer_auth_response');

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
* Woocommerce constant.
*/
define('VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT', 'woocommerce');

/**
 * Partner Solution ID.
 */
define( 'VISA_ACCEPTANCE_SOLUTION_ID', 'KL93A2PB' );

/**
 * Developer ID.
 */
define( 'VISA_ACCEPTANCE_DEVELOPER_ID', '999' );

/**
 *
 * Flex domain for Test Mode.
 */
define( 'VISA_ACCEPTANCE_FLEX_TEST_DOMAIN', 'https://testflex.cybersource.com' );

/**
 *
 * Flex domain for Production Mode.
 */
define( 'VISA_ACCEPTANCE_FLEX_PROD_DOMAIN', 'https://flex.cybersource.com' );

/**
 *
 * API domain for Test Mode.
 */
define( 'VISA_ACCEPTANCE_API_TEST_DOMAIN', 'https://apitest.cybersource.com' );

/**
 *
 * API domain for Production Mode.
 */
define( 'VISA_ACCEPTANCE_API_PROD_DOMAIN', 'https://api.cybersource.com' );

/**
 *
 * Host domain for Test Mode.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HOST_APITEST', 'apitest.cybersource.com' );

/**
 *
 * Host domain for Production Mode.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION', 'api.cybersource.com' );

/**
 *
 * Default Resource for Payments API.
 */
define( 'VISA_ACCEPTANCE_PAYMENT_RESOURCE', '/pts/v2/payments' );

/**
 *
 * API Resource for fetching transaction details.
 */
define( 'VISA_ACCEPTANCE_TRANSACTION_DETAILS_RESOURCE', '/tss/v2/transactions/' );

/**
 *
 * API Resource for fetching payment details.
 */
define('VISA_ACCEPTANCE_TRANSIENT_TOKEN_DETAILS', '/up/v1/payment-details/' );

/**
 *
 * API Resource for capture context.
 */
define('VISA_ACCEPTANCE_CAPTURE_CONTEXTS', '/up/v1/capture-contexts');

/**
 *
 * API Resource for TMS.
 */
define( 'VISA_ACCEPTANCE_TMS_RESOURCE', '/tms/v2/customers/' );

/**
 *
 * Payment Instruments connector API String.
 */
define( 'VISA_ACCEPTANCE_PAYMENT_INSTRUMENT_RESOURCE', '/payment-instruments/' );


/**
 *
 * API Resource for Conversion Detail Reporting.
 */
define( 'VISA_ACCEPTANCE_REPORTING_RESOURCE', '/reporting/v3/conversion-details?startTime=' );

/**
 *
 * API Resource for Payer Authentication Setup Call.
 */
define( 'VISA_ACCEPTANCE_PAYER_AUTH_SETUP_RESOURCE', '/risk/v1/authentication-setups' );

/**
 *
 * Cardinal Cruise Test Library.
 */
define( 'VISA_ACCEPTANCE_CARDINAL_TEST_LIBRARY', 'https://centinelapistag.cardinalcommerce.com' );

/**
 *
 * Cardinal Cruise Test Library.
 */
define( 'VISA_ACCEPTANCE_CARDINAL_PRODUCTION_LIBRARY', 'https://centinelapi.cardinalcommerce.com' );

/**
 *
 * Cardinal Cruise Test Library.
 */
define( 'VISA_ACCEPTANCE_ONLINE_METRIX','https://h.online-metrix.net/fp/tags');

/**
 *
 * Used to set the priority parameter for add_action() method.
 */
define( 'VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY', 10 );

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
 * The decision manager toggle setting name Constant.
 */
define('VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER', 'enable_decision_manager');

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
 * Sends through a request for funds to be "reserved" on the cardholder's credit card. 
 * A standard authorization is reserved for 2-5 days. 
 * Reservation times are determined by cardholder's bank.
 */
define('VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION', 'authorization');

/**
 *
 * 200 Status Code Constant.
 */
define('VISA_ACCEPTANCE_TWO_ZERO_ZERO', 200);

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
 * 404 Status Code Constant.
 */
define('VISA_ACCEPTANCE_FOUR_ZERO_FOUR', 404);

/**
 *
 * Sca challange Code Constant.
 */
define('VISA_ACCEPTANCE_SCA_CHALLANGE_CODE', '04');

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
define( 'VISA_ACCEPTANCE_STRING_ERROR', 'error' );

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
 *
 * VISA_ACCEPTANCE_PANENTRY Constant.
 */
define('VISA_ACCEPTANCE_PANENTRY', 'PANENTRY');

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
 * VCO Constant for higher versions.
 */
define('VISA_ACCEPTANCE_CLICKTOPAY', 'CLICKTOPAY');

/**
 *
 * Zero Amount Constant.
 */
define('VISA_ACCEPTANCE_ZERO_AMOUNT', '0.00');

/**
 *
 * Dummy Amount Constant.
 */
define('VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT', '0.01');

/**
 *
 * Unified Checkout Client Version.
 */
define('VISA_ACCEPTANCE_UC_CLIENT_VERSION', '0.23');

/**
 *
 * Unified Checkout Billing Type.
 */
define('VISA_ACCEPTANCE_UC_BILLING_TYPE', 'NONE');

/**
 *
 * WooCommerce Underscore String Constant.
 */
define('VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE', 'woocommerce_');

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
 * admin.php String Constant.
 */
define('VISA_ACCEPTANCE_ADMIN_PHP', 'admin.php');

/**
 *
 * mark_ String Constant.
 */
define('VISA_ACCEPTANCE_MARK_UNDERSCORE', 'mark_');

/**
 *
 * Add payment method String Constant.
 */
define('VISA_ACCEPTANCE_ADD_PAYMENT_METHOD', 'add_payment_method');

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
 * Charge captured String Constant.
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
 * FlexForm String Constant.
 */
define('VISA_ACCEPTANCE_FLEXFORM', 'flexForm');

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
 * Setup String Constant.
 */
define( 'VISA_ACCEPTANCE_SETUP', 'Setup' );

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
 *
 * Card delete String Constant.
 */
define('VISA_ACCEPTANCE_CARD_DELETE', 'Card Delete');

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
 * Environment sale String Constant.
 */
define('VISA_ACCEPTANCE_ENROLLMENT_CHARGE', 'Enrollment Charge');

/**
 *
 * Environment authorization String Constant.
 */
define('VISA_ACCEPTANCE_ENROLLMENT_AUTHORIZATION', 'Enrollment Authorization');

/**
 *
 * Validation sale String Constant.
 */
define('VISA_ACCEPTANCE_VALIDATION_CHARGE', 'Validation Charge');

/**
 *
 * Validation authorization String Constant.
 */
define('VISA_ACCEPTANCE_VALIDATION_AUTHORIZATION', 'Validation Authorization');

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
 * _Payer auth String Constant.
 */
define('VISA_ACCEPTANCE_UNDERSCORE_PAYER_AUTH', '_payer_auth');

/**
 *
 * _Payer auth PARAM String Constant.
 */
define('VISA_ACCEPTANCE_UC_PAYER_AUTH_PARAM', 'visa_acceptance_uc_payer_auth_param');

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
 * Mozilla/5.0 String Constant.
 */
define('VISA_ACCEPTANCE_MOZILLA_FIVE_ZERO', 'Mozilla/5.0');

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
 * Order Pay Constant.
 */
define('VISA_ACCEPTANCE_ORDER_PAY', '/order-pay/');

/**
 *
 * ONE Constant.
 */
define('VISA_ACCEPTANCE_ONE', 1);

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
 * Payer Auth blocks string Constant.
 */
define('VISA_ACCEPTANCE_PAYER_AUTH_BLOCKS', '#order_blocks_');

/**
 *
 * Payer auth with tokenization string Constant.
 */
define('VISA_ACCEPTANCE_PAYER_AUTH_WITH_TOKEN', '#order_bolcks_');

/**
 *
 * Payer auth normal string Constant.
 */
define('VISA_ACCEPTANCE_PAYER_AUTH_NORMAL', '#order_change_');

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
 * Api Response (EBC) Authorized Status.
 */
define( 'VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED', 'AUTHORIZED' );

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
 * Request Headers Parameters for Content Type.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HEADER_PARAM_CONTENT_TYPE_UTF', 'application/json;charset=utf-8' );

/**
 *
 * Request Headers Parameters for Accept.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HEADER_PARAM_ACCEPT_UTF', 'application/hal+json;charset=utf-8');

/**
 *
 * Request Headers Parameters without UTF.
 */
define( 'VISA_ACCEPTANCE_REQUEST_HEADER_PARAM', 'application/json;');

/**
 *
 * UTF-8 Encoding Constant.
 */
define( 'VISA_ACCEPTANCE_UTF_8', 'UTF-8');

/**
 *
 * VISA_ACCEPTANCE_ISO Encoding Constant.
 */
define( 'VISA_ACCEPTANCE_ISO', 'ISO-8859-1');

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
 * GMT constant.
 */
define('VISA_ACCEPTANCE_GMT', 'GMT');

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
	if ( class_exists( VISA_ACCEPTANCE_WOOCOMMERCE_CONSTANT ) ) {
	    $GLOBALS[VISA_ACCEPTANCE_GATEWAY_ID] = Visa_Acceptance_Solutions::instance();
	} else
	{
		return;
	}

}

add_action( 'plugins_loaded', 'visa_acceptance_extension_initialize', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY );

add_action( 'woocommerce_blocks_loaded', 'visa_acceptance_block_support_for_gateway' );

add_action( 'before_woocommerce_init', 'hpos_compatibility' );

/**
 * Handles hpos compatibility
 *
 * @return boolean
 */
function hpos_compatibility() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
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
function handle_hpos_compatibility() {
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
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Visa_Acceptance_Blocks_Handler_Unified_Checkout() );
			}
		);
	}
}
