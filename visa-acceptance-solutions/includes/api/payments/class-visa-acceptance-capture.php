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
require_once __DIR__ . '/../response/payments/class-visa-acceptance-capture-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

/**
 * Visa Acceptance Capture Class
 * Handles Capture requests
 */
class Visa_Acceptance_Capture extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * Capture constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Handles the Capture Response
	 *
	 * @param \WC_Order $order order object.
	 * @param array     $response_data array containing response data.
	 *
	 * @return array
	 */
	public function perform_capture( $order, $response_data ) {
		$capture_response_obj   = new Visa_Acceptance_Capture_Response( $this->gateway );
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		$authorization_amount = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'authorization_amount' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT );
		if ( ( ! $this->is_order_ready_for_capture( $order ) ) || $this->is_order_fully_captured( $order ) || $order->get_total() > $authorization_amount ) {
			$response_data['error_message'] = __( 'Order cannot be captured', 'visa-acceptance-solutions' );
		} else {
			$transaction_id         = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'trans_id' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );
			$capture_response       = $this->execute_capture( $order, $transaction_id );
			$http_code              = $capture_response['http_code'];
			$capture_body 			= $capture_response['body'];
			if(VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code) {
				$capture_body = wp_json_encode($capture_body['body']);
			}
			$capture_response_array = $this->get_payment_response_array(
				$http_code,
				$capture_body,
				VISA_ACCEPTANCE_CAPTURE
			);
			$status                 = $capture_response_array['status'];
			if ( $capture_response_obj->is_transaction_approved( $capture_response, $status ) ) {
				$message = sprintf(
					/* translators: %1$s - payment gateway title , %2$s - transaction amount. Definitions: Capture, as in capture funds from a credit card. */
					__( '%1$s Capture of %2$s Approved', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					wc_price(
						$order->get_total(),
						array(
							'currency' => $order->get_currency(),
						)
					)
				);
				if ( $capture_response_array['transaction_id'] ) {
					$message .= VISA_ACCEPTANCE_SPACE . sprintf(
						/* translators: %1$s - payment gateway title , %2$s - transaction amount. Definitions: Capture, as in capture funds from a credit card. */
						esc_html__( '(Transaction ID %s)', 'visa-acceptance-solutions' ),
						$capture_response_array['transaction_id']
					);
					$alert_message = VISA_ACCEPTANCE_CAPTURE_OF . VISA_ACCEPTANCE_SPACE . $order->get_total() . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_APPROVED_TRANSACTION . $capture_response_array['transaction_id'] . ')';
				}
				if ( $capture_response_array['transaction_id'] && ( strtoupper( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ) === $capture_response_array['status'] ) ) {
					$order->add_order_note( $message );
					$this->do_capture_success( $order, $capture_response_array );
					if ( $order->get_status() === VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD ) {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_STRING_EMPTY );
					}
					$response_data[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_YES;
					$response_data['alert_message']           = $alert_message;
				}
			} else {
				$response_data[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_NO;
				$response_data['error_message']           = VISA_ACCEPTANCE_ORDER_NOT_CAPTURED;
			}
		}
		return $response_data;
	}

	/**
	 * Handles the order status if Capture Transaction is successful
	 *
	 * @param \WC_Order $order order object.
	 * @param array     $capture_response_array array containing response data.
	 */
	public function do_capture_success( $order, $capture_response_array ) {

		$total_captured = $capture_response_array['amount'];
		$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $total_captured );
		$this->update_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES );

		// add capture transaction ID.
		if ( $capture_response_array && $capture_response_array['transaction_id'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $capture_response_array['transaction_id'] );
		}
	}

	/**
	 * Checks whether the order is fully captured
	 *
	 * @param \WC_Order $order order object.
	 *
	 * @return boolean
	 */
	public function is_order_ready_for_capture( $order ) {
		try {
			return ! in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED ), true ) && ( VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'trans_id' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check order is fully captured', true );
		}
	}

	/**
	 * Gets Capture Response payload
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transaction_id transaction id.
	 *
	 * @return array
	 */
	public function execute_capture( $order, $transaction_id ) {
		$request     = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client  = $request->get_api_client();
		$capture_api = new \CyberSource\Api\CaptureApi( $api_client );
		
		$request_obj = new \CyberSource\Model\CapturePaymentRequest();
		$request_obj->setClientReferenceInformation( $request->client_reference_information( $order ) );

		$processing_information = new \CyberSource\Model\Ptsv2paymentsidcapturesProcessingInformation(
			array(
				'paymentSolution' => $request->get_payment_solution( $order ),
			)
		);
		$request_obj->setProcessingInformation( $processing_information );

		$order_information = new \CyberSource\Model\Ptsv2paymentsidcapturesOrderInformation(
			array(
				'amountDetails' => $request->order_information_amount_details( $order ),
				'lineItems'     => $request->get_line_items_information( $order ),
			)
		);
		$request_obj->setOrderInformation( $order_information );
		$this->gateway->add_logs_data( $request_obj, true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );

		try {
			if ( in_array( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ), array( VISA_ACCEPTANCE_UC_ID, VISA_ACCEPTANCE_SV_GATEWAY_ID ), true ) ) {
				$api_response = $capture_api->capturePayment( $request_obj, $transaction_id );
			}

			$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );

			$return_array = array(
				'http_code' => $api_response[1],
				'body'      => $api_response[0],
			);
			return $return_array;
		} catch ( \CyberSource\ApiException $e ) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );
		}
	}
}
