<?php
/**
 * Translation constants for order notes and messages.
 *
 * This file contains the function that defines all translatable constants used
 * throughout the plugin for order notes, status messages, and error messages.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define translation constants for order notes and messages.
 * 
 * This function defines all translatable constants used throughout the plugin
 * for order notes, status messages, and error messages. It includes safety checks
 * to prevent duplicate definitions and ensures translations are loaded before use.
 * 
 * The function is automatically called on WordPress 'init' hook or immediately
 * if init has already fired.
 *
 * @since 3.0.0
 * @return void
 */
 function visa_acceptance_translation_constants() {
	/**
	 *
	 * Authorization Approved order note.
	 */
	/* translators: Placeholders: %1$s - <a> tag*/
	define( 'VISA_ACCEPTANCE_AUTH_APPROVED', __( 'Authorization Approved: (Transaction ID %s)', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Charge Approved order note.
	 */
	/* translators: Placeholders: %1$s - <a> tag*/
	define( 'VISA_ACCEPTANCE_CHARGE_APPROVED', __( 'Charge Approved: (Transaction ID %s).', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Transaction Authorized order note.
	 */
	define( 'VISA_ACCEPTANCE_CHARGE_TRANSACTION', __( 'Transaction Authorized and Charged (Charge only transaction).', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Transaction Authorized order note.
	 */
	define( 'VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION', __( 'Transaction Authorized (Authorization only transaction).', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * DM Review order note.
	 */
	define( 'VISA_ACCEPTANCE_REVIEW_MESSAGE', __( 'Order requires manual review in Visa Acceptance Solutions Case Management system.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Transaction Held order note.
	 */
	define( 'VISA_ACCEPTANCE_REVIEW_TRANSACTION', __( 'Transaction held for Review (Authorization only transaction).', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Authorization Rejected order note.
	 */
	/* translators: Placeholders: %1$s - <a> tag*/
	define( 'VISA_ACCEPTANCE_AUTH_REJECT', __( 'Authorization Rejected by Decision Manager: (Transaction ID %s)', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Transaction Rejection order note.
	 */
	define( 'VISA_ACCEPTANCE_REJECT_TRANSACTION', __( 'Transaction Rejected (Authorization only transaction).', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Void Approved order note.
	 */
	/* translators: Placeholders: %1$s - <a> tag*/
	define( 'VISA_ACCEPTANCE_VOID_APPROVED', __( 'Void Approved with Transaction ID %s', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Capture String order note.
	 */
	define( 'VISA_ACCEPTANCE_CAPTURE_OF', __( 'Capture of', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Approved String order note.
	 */
	define( 'VISA_ACCEPTANCE_APPROVED_TRANSACTION', __( 'Approved (Transaction ID ', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Order not captured error message.
	 */
	define( 'VISA_ACCEPTANCE_ORDER_NOT_CAPTURED', __( 'Failed to capture the order.', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Invalid Request error message.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_REQUEST', __( 'Invalid request', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Invalid Amount error message.
	 */
	define( 'VISA_ACCEPTANCE_INVALID_AMOUNT_ERROR', __( 'Enter valid amount', 'visa-acceptance-solutions' ) );

	/**
	 *
	 * Refund reason message.
	 */
	/* translators: Placeholders: %1$s - <a> tag*/
	define( 'VISA_ACCEPTANCE_REFUND_MESSAGE', __( 'Refunded because of reason - %1$s.', 'visa-acceptance-solutions' ) );
 }
 add_action( 'init', 'visa_acceptance_translation_constants');
