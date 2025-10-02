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
require_once __DIR__ . '/../class-visa-acceptance-request.php';
require_once __DIR__ . '/../class-visa-acceptance-api-client.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-refund-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-refund-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

/**
 * Visa_Acceptance Refund Request Class
 *
 * Handles Refund requests
 */
class Visa_Acceptance_Refund extends Visa_Acceptance_Request {

	/**
	 * Gateway
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * Path
	 *
	 * @var string $path */
	public $path;

	/**
	 * Refund_Request constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Handles refund transaction
	 *
	 * @param object $order order object.
	 * @param string $amount refund amount.
	 * @param string $reason reason for refund.
	 *
	 * @return string|boolean
	 */
	public function do_refund( $order, $amount, $reason ) {
		$transaction_id 	   = $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID );
		$response       	   = false;
		$refund_response_obj   = new Visa_Acceptance_Refund_Response( $this->gateway );
		try {
			if ( VISA_ACCEPTANCE_VAL_ZERO !== $order->get_total() && $order->get_total() >= $amount ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( $transaction_id ) {
					$refund_response       = $this->get_refund_response( $order, $amount, $transaction_id );
					$http_code             = $refund_response['http_code'];
					$refund_body = $refund_response['body'];
					if(VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code) {
						$refund_body = wp_json_encode($refund_response['body']);
					}
					$refund_response_array = $this->get_payment_response_array(
						$http_code,
						$refund_body,
						VISA_ACCEPTANCE_REFUND
					);
					$status                = $refund_response_array['status'];
					if ( $refund_response_obj->is_transaction_approved( $refund_response, $status ) ) {
						$this->add_refund_data( $order, $refund_response_array );
						$this->add_refund_order_note( $order, $reason, $refund_response_array );
						$this->mark_order_as_refunded( $order );
						$response = true;
					} else {
						$response = $this->get_refund_failed_wp_error( $http_code, $refund_response_array['reason'] );
						$order->add_order_note( $response->get_error_message() );
					}
				} else {
					$response = $this->get_refund_failed_wp_error( 'Invalid transaction ID', VISA_ACCEPTANCE_INVALID_REQUEST );
					$order->add_order_note( $response->get_error_message() );
				}
			} else {
				$response = $this->get_refund_failed_wp_error( VISA_ACCEPTANCE_INVALID_AMOUNT, VISA_ACCEPTANCE_INVALID_AMOUNT_ERROR );
				$order->add_order_note( $response->get_error_message() );
			}
			return $response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get refund transaction', true );
		}
	}

	/**
	 * Updates order status as refunded after successful refund
	 *
	 * @param \WC_Order $order order object.
	 */
	public function mark_order_as_refunded( $order ) {
		$order_note = sprintf(
			/* translators: %s - payment gateway title */
			esc_html__( 'Order refunded.', 'visa-acceptance-solutions' ),
		);
		$order->add_order_note( $order_note );
	}

	/**
	 * Adds refund data to order meta
	 *
	 * @param \WC_Order $order order object.
	 * @param array     $refund_response_array refund transaction response.
	 */
	protected function add_refund_data( \WC_Order $order, $refund_response_array ) {
		try {
			// indicate the order was refunded along with the refund amount.
			$this->add_order_meta( $order, VISA_ACCEPTANCE_REFUND_AMOUNT, $refund_response_array['amount'] );

			// add refund transaction ID.
			if ( $refund_response_array && $refund_response_array['transaction_id'] ) {
				$this->add_order_meta( $order, VISA_ACCEPTANCE_REFUND_TRANSACTION_ID, $refund_response_array['transaction_id'] );
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to add data to order meta', true );
		}
	}

	/**
	 * Handles order notes for refund
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $reason reason.
	 * @param array     $refund_response_array response for refund transaction.
	 */
	protected function add_refund_order_note( \WC_Order $order, $reason, $refund_response_array ) {
		$message = sprintf(
		/* translators: %1$s - payment gateway title , %2$s - a monetary amount */
			esc_html__( 'Refund amount of %1$s approved.', 'visa-acceptance-solutions' ),
			wc_price(
				$refund_response_array['amount'],
				array(
					'currency' => $order->get_currency(),
				)
			)
		);

		if ( ! empty( $reason ) ) {
			/* translators: %1$s - reason tag */
			$reason_message = sprintf(
				VISA_ACCEPTANCE_REFUND_MESSAGE,
				$reason,
			);
			$message        = $message . $reason_message;
		}

		// adds the transaction id (if any) to the order note.
		if ( $refund_response_array['transaction_id'] ) {
			$message .= VISA_ACCEPTANCE_SPACE . sprintf(
				/* translators: %s - transaction id */
				esc_html__( '(Transaction ID %s)', 'visa-acceptance-solutions' ),
				$refund_response_array['transaction_id']
			);
		}
		$order->add_order_note( $message );
	}

	/**
	 * Updates order notes if refund fails
	 *
	 * @param string $error_code error code.
	 * @param string $error_message error message.
	 */
	protected function get_refund_failed_wp_error( $error_code, $error_message ) {
		try {
			if ( $error_code ) {
				$message = sprintf(
					/* translators: %1$s - payment gateway title , %2$s - error message*/
					esc_html__( '%1$s Refund Failed. %2$s', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					$error_message
				);
			}
			else {
			$message = sprintf(
					/* translators: %1$s - payment gateway title , %2$s - error message*/
					esc_html__( '%1$s Refund Failed. %2$s', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					$error_message
				);	
			}
			return new \WP_Error( VISA_ACCEPTANCE_WC_UNDERSCORE . $this->gateway->get_id() . '_refund_failed', $message );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, ' Unable to get updates order notes if refund fails', true );
		}
	}

	/**
	 * Generate refund request payload for a payment gateway
	 *
	 * @param object $order order object.
	 * @param string $amount refund amount.
	 * @param string $transaction_id transaction id.
	 *
	 * @return array
	 */
	public function get_refund_response( $order, $amount, $transaction_id ) {
		$request     = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client  = $request->get_api_client();
		$refund_api  = new \CyberSource\Api\RefundApi( $api_client );
		$request_obj = new \CyberSource\Model\RefundPaymentRequest();

		$client_reference_information_partner = new \CyberSource\Model\Ptsv2paymentsClientReferenceInformationPartner(
			array(
				'developerId' => VISA_ACCEPTANCE_DEVELOPER_ID,
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			)
		);

		$client_reference_information = new \CyberSource\Model\Ptsv2paymentsidrefundsClientReferenceInformation(
			array(
				'code' 					=> $order->get_id(),
				'partner'            	=> $client_reference_information_partner,
				'applicationName'    	=> VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' 	=> VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		$request_obj->setClientReferenceInformation( $client_reference_information );

		$processing_information = new \CyberSource\Model\Ptsv2paymentsidrefundsProcessingInformation(
			array(
				'paymentSolution' => $request->get_payment_solution( $order ),
			)
		);
		$request_obj->setProcessingInformation( $processing_information );

		$order_information = new \CyberSource\Model\Ptsv2paymentsidrefundsOrderInformation(
			array(
				'amountDetails' => array(
					'totalAmount' => $amount,
					'currency'    => $order->get_currency(),
				),
			)
		);
		$request_obj->setOrderInformation( $order_information );

		$this->gateway->add_logs_data( $request_obj, true, VISA_ACCEPTANCE_REFUND );
		try {
			if ( VISA_ACCEPTANCE_UC_ID === $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) ) {
				$api_response = $refund_api->refundPayment( $request_obj, $transaction_id );
			}
			$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, VISA_ACCEPTANCE_REFUND );
			$return_array = array(
				'http_code' => $api_response[1],
				'body'      => $api_response[0],
			);
			return $return_array;
		} catch ( \CyberSource\ApiException $e ) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, VISA_ACCEPTANCE_REFUND );
		}
	}
}
