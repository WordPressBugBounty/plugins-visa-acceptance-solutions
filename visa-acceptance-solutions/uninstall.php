<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @package    Visa_Acceptance_Solutions
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
require_once  dirname(__FILE__) .'/visa-acceptance-solutions.php';

remove_action( 'plugins_loaded', 'visa_acceptance_extension_initialize', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY);
remove_action( 'woocommerce_blocks_loaded', 'visa_acceptance_block_support_for_gateway');
remove_action( 'woocommerce_update_options_payment_gateways_', 'process_admin_options');
remove_action( 'init', 'schedule_order_updates');
remove_action( 'wc_payment_gateway_update_orders', 'handle_order_updates');
remove_action( 'woocommerce_order_status_changed', 'prevent_order_status_change', 10, 4 );
remove_action( 'handle_bulk_actions-edit-shop_order', 'prevent_bulk_action', 10, 3 );
remove_action( 'admin_init', 'prevent_bulk_action_hpos', 10, 3 );
remove_action( 'admin_enqueue_scripts', 'enqueue_styles' );
remove_action( 'admin_enqueue_scripts', 'enqueue_scripts' );
remove_action( 'woocommerce_order_item_add_action_buttons', 'add_capture_button', VISA_ACCEPTANCE_ACTION_HOOK_DEFAULT_PRIORITY );
remove_action( 'wp_ajax_wc_capture_action', 'ajax_process_capture' );
remove_action( 'wp_enqueue_scripts', 'enqueue_styles' );
remove_action( 'wp_enqueue_scripts', 'enqueue_scripts' );
remove_action( 'woocommerce_account_payment_methods_column_title', 'uc_add_payment_method_title' );
remove_action( 'woocommerce_account_payment_methods_column_details', 'uc_add_payment_method_details' );
remove_action( 'woocommerce_account_payment_methods_column_default', 'uc_add_payment_method_default' );
remove_action( 'wp_ajax_wc_call_uc_payer_auth_setup_action', 'call_setup_action' );
remove_action( 'wp_ajax_wc_call_uc_payer_auth_enrollment_action', 'call_enrollment_action' );
remove_action( 'wp_ajax_wc_call_uc_payer_auth_validation_action', 'call_validation_action' );
remove_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_setup_action', 'call_setup_action' );
remove_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_enrollment_action', 'call_enrollment_action' );
remove_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_validation_action', 'call_validation_action' );
remove_action( 'rest_api_init', 'payment_gateway_register_endpoint' );
remove_action( 'wp_ajax_wc_call_uc_payer_auth_error_handler', 'call_error_handler' );
remove_action( 'wp_ajax_nopriv_wc_call_uc_payer_auth_error_handler', 'call_error_handler' );

remove_filter( 'woocommerce_pre_delete_data', 'wp_kama_woocommerce_pre_delete_object_type_filter', 10);
remove_filter( 'woocommerce_payment_token_set_default', 'wp_kama_woocommerce_set_default', 10);
remove_filter( 'woocommerce_account_payment_methods_columns', 'uc_add_payment_methods_columns' );
remove_filter( 'woocommerce_gateway_icon', 'custom_gateway_icon', 10 );
remove_filter( 'woocommerce_payment_gateways', 'load_gateways' );
remove_filter( 'woocommerce_order_fully_refunded_status', 'cancel_voided_order', 10 );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare(
	 "DELETE FROM $wpdb->actionscheduler_actions WHERE args LIKE %s",
	"%" . VISA_ACCEPTANCE_ACTION_SCHEDULER_ID . "%"
));

delete_option(VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE. VISA_ACCEPTANCE_UC_ID . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS);

wp_cache_flush();

