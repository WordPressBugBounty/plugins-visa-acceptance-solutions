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

// Load translation constants for API error messages.
require_once __DIR__ . '/visa-acceptance-response-constants.php';

/**
 *
 * Visa Acceptance Api Response Class
 * Handles Api responses
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
abstract class Visa_Acceptance_Response {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * Order object
	 *
	 * @var \WC_Order order associated with the response, if any
	 * */
	protected $order;

	/**
	 * Gateway object
	 *
	 * @var object
	 */
	public $gateway;


	/**
	 * Response Constructor
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Adds the transaction data to the order.
	 *
	 * @param mixed $order order.
	 * @param mixed $payment_response_array transaction response.
	 *
	 * @return void
	 */
	public function add_transaction_data( $order, $payment_response_array ) {
		if ( $payment_response_array['transaction_id'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID, $payment_response_array['transaction_id'] );
			$order->set_transaction_id( $payment_response_array['transaction_id'] );
		}
		// transaction date.
		$this->update_order_meta( $order, 'transaction_date', current_time( 'mysql' ) );
	}
}
