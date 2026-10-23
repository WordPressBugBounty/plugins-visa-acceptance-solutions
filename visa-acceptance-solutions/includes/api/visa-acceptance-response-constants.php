<?php
/**
 * Translation constants for API error messages and response codes.
 *
 * This file contains the function that defines all translatable error message
 * constants and response code constants used for API responses throughout the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes/api
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define translation constants for API error messages and response codes.
 * 
 * This function defines all translatable error message constants and response
 * code constants used for API responses throughout the plugin. It includes
 * safety checks to prevent duplicate definitions and ensures the translation
 * function is available before defining translatable constants.
 * 
 * The function is automatically called on WordPress 'init' hook or immediately
 * if init has already fired.
 *
 * @since 2.2.2
 * @return void
 */
function visa_acceptance_define_translation_constants_data() {
	/**
	 *
	 * Unauthorized Error Message.
	 */
	define( 'VISA_ACCEPTANCE_UNAUTHORIZED_ERROR', __( 'Unauthorized error encountered. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ) );


	/**
	 *
	 * Unauthorized Error Message.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_MID_CREDENTIAL', __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ) );


	/**
	 *
	 * Server Error Message.
	 */
	define( 'VISA_ACCEPTANCE_SERVER_ERROR', __( 'We are unable to process your order. Please try a different payment method or try again later.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Process Request Error Message.
	 */
	define( 'VISA_ACCEPTANCE_PROCESS_REQUEST_ERROR', __( 'Unable to process your request. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Payment Load Error Message.
	 */
	define( 'VISA_ACCEPTANCE_PAYMENT_LOAD_ERROR', __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Invalid Payment Detail Error Message.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR', __( 'We were unable to complete your order. Please check your payment details and try again.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Unexpected Error Message.
	 */
	define( 'VISA_ACCEPTANCE_UNEXPECTED_OCCURED_ERROR', __( 'Unexpected error occurred, please contact customer care for any assistance.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Invalid Merchant Configuration Error Message.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION_ERROR', __( 'We encountered an error. Please try again later.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Timeout Error Message.
	 */
	define( 'VISA_ACCEPTANCE_TIMEOUT_ERROR', __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Session Expired Error Message.
	 */
	define( 'VISA_ACCEPTANCE_SESSION_EXPIRED_ERROR', __( 'Session expired. Please try again.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Internal Server Error Message.
	 */
	define( 'VISA_ACCEPTANCE_INTERNAL_SERVER_ERROR', __( 'Internal server error. Please try again later.', 'visa-acceptance-solutions' ) );


	/**
	 *
	 * Auth Failed reason string.
	 */
	define( 'VISA_ACCEPTANCE_AUTHENTICATION_FAILED', 'AUTHENTICATION_FAILED' );

	/**
	 *
	 * Expired Card reason string.
	 */
	define( 'VISA_ACCEPTANCE_EXPIRED_CARD', 'EXPIRED_CARD' );

	/**
	 *
	 * Unexpected error reason string.
	 */
	define( 'VISA_ACCEPTANCE_UNEXPECTED_ERROR', 'UNEXPECTED_ERROR' );

	/**
	 *
	 * Invalid merchant config reason string.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION', 'INVALID_MERCHANT_CONFIGURATION' );

	/**
	 *
	 * Processor Timeout reason string.
	 */
	define( 'VISA_ACCEPTANCE_PROCESSOR_TIMEOUT', 'PROCESSOR_TIMEOUT' );

	/**
	 *
	 * CSRF expired reason string.
	 */
	define( 'VISA_ACCEPTANCE_CSRF_EXPIRED', 'CSRF_EXPIRED' );

	/**
	 *
	 * CSRF invalid reason string.
	 */
	define( 'VISA_ACCEPTANCE_CSRF_INVALID', 'CSRF_INVALID' );

	/**
	 *
	 * CSRF validation error reason string.
	 */
	define( 'VISA_ACCEPTANCE_CSRF_VALIDATION_ERROR', 'CSRF_VALIDATION_ERROR' );

	/**
	 *
	 * Invalid data reason string.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_DATA_ERROR', 'INVALID_DATA' );
}

add_action( 'init', 'visa_acceptance_define_translation_constants_data');
