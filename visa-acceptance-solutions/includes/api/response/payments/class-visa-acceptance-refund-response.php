<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Includes all required dependencies
 */
require_once __DIR__ . '/../../class-visa-acceptance-response.php';

/**
 * Visa Acceptance Capture Response Class
 * Handles Capture responses
 * Provides common functionality to Credit Card & other transaction response classes
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Refund_Response extends Visa_Acceptance_Response {

	/**
	 * Gateway object
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Refund_Response Constructor
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Validates whether the transation is approved
	 *
	 * @param object $payment_response transaction response.
	 * @param string $status transaction status.
	 *
	 * @return boolean
	 */
	public function is_transaction_approved( $payment_response, $status ) {
		return ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $payment_response['http_code'] && strtoupper( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ) === $status ) ? true : false;
	}
}
