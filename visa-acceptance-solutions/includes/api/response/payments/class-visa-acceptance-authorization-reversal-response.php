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
 * Visa Acceptance Authorization Reversal Response Class
 * Handles Authorization Reversal response
 * Provides common functionality to Credit Card & other transaction response classes
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Authorization_Reversal_Response extends Visa_Acceptance_Response {

	/**
	 * Gateway object
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Authorization_Reversal_Response Constructor
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Update approved transaction status for auth-reversal
	 *
	 * @param array  $payment_response payment response.
	 * @param string $status transaction status.
	 *
	 * @return boolean
	 */
	public function is_transaction_approved( $payment_response, $status ) {
		return ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $payment_response['http_code'] && strtoupper( VISA_ACCEPTANCE_RESERVED ) === $status ) ? true : false;
	}
}
