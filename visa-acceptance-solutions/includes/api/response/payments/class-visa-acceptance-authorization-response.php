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
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../../class-visa-acceptance-response.php';

/**
 * Visa Acceptance Authorization Response Class
 * Handles Authorization responses
 * Provides common functionality to Credit Card & other transaction response classes
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Authorization_Response extends Visa_Acceptance_Response {


	/**
	 * Gateway object
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Authorization_Response constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Update approved transaction status for authorization
	 *
	 * @param array  $payment_response payment response.
	 * @param string $status transaction status.
	 *
	 * @return boolean
	 */
	public function is_transaction_approved( $payment_response, $status ) {
		return ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $payment_response['http_code'] && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_RISK_DECLINED, VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW, VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT, VISA_ACCEPTANCE_API_RESPONSE_STATUS_DECISION_REJECT ), true ) ) ? true : false;
	}

	/**
	 * Checks whether the transaction status is authorized
	 *
	 * @param string $status transaction status.
	 *
	 * @return boolean
	 */
	public function is_transaction_status_approved( $status ) {
		return in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW ), true ) ? true : false;
	}
}
