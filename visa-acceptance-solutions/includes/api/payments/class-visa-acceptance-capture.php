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
		
		// Check if this is a free trial order (authorized with placeholder $0.01 or $0).
		$is_free_trial_order = ( (float) $authorization_amount <= (float) VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT );
		
		if ( $is_free_trial_order ) {
			$response_data[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_YES;
		}
		else {
			// Block capture if a full settlement was already initiated.
			// Do not block when capture_transaction_id was set only by a prior partial capture.
			$existing_capture_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID );
			$charge_captured     = $this->get_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED );
			if ( ! empty( $existing_capture_id ) && in_array( $charge_captured, array( VISA_ACCEPTANCE_YES, 'pending' ), true ) ) {
				$response_data['error_message'] = sprintf(
					/* translators: %s: existing capture/settlement transaction ID */
					__( 'A settlement has already been initiated for this order (Transaction ID: %s). Manual capture blocked to prevent duplicate settlement.', 'visa-acceptance-solutions' ),
					esc_html( $existing_capture_id )
				);
				return $response_data;
			}

			// Account for amounts already captured via partial capture so we only request the remaining balance.
			$already_captured_amount = $this->get_previously_captured_amount( $order );
			$capture_amount          = round( (float) $order->get_total() - $already_captured_amount, VISA_ACCEPTANCE_VAL_TWO );
			$capture_sequence_number = $this->get_next_capture_sequence_number( $order );

			if ( ( ! $this->is_order_ready_for_capture( $order ) ) || $this->is_order_fully_captured( $order ) || $order->get_total() > $authorization_amount || $capture_amount <= VISA_ACCEPTANCE_VAL_ZERO ) {
				$response_data['error_message'] = __( 'Order cannot be captured', 'visa-acceptance-solutions' );
			} else {
				$transaction_id         = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'trans_id' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );
				$capture_response       = $this->execute_capture( $order, $transaction_id, $capture_amount, $already_captured_amount > VISA_ACCEPTANCE_VAL_ZERO, $capture_sequence_number );
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
						__( '%1$s - Capture of %2$s Approved:', 'visa-acceptance-solutions' ),
						$this->gateway->get_title(),
						wc_price(
							$capture_amount,
							array(
								'currency' => $order->get_currency(),
							)
						)
					);
					if ( $capture_response_array['transaction_id'] ) {
						$credit_fields = $this->build_response_credit_fields( $capture_response_array );
						if ( ! empty( $credit_fields ) ) {
							$message .= "\n" . implode( "\n", $credit_fields );
						}
						$formatted_capture_amount = html_entity_decode(
							wp_strip_all_tags( wc_price( $capture_amount, array( 'currency' => $order->get_currency() ) ) ),
							ENT_QUOTES
						);
						$alert_message = VISA_ACCEPTANCE_CAPTURE_OF . VISA_ACCEPTANCE_SPACE . $formatted_capture_amount . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_APPROVED_TRANSACTION . $capture_response_array['transaction_id'] . ')';
					}
					$approved_capture_status = array( strtoupper( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), VISA_ACCEPTANCE_STRING_COMPLETED );
                    if ( $capture_response_array['transaction_id'] && in_array( $capture_response_array['status'], $approved_capture_status, true ) ) {
						$order->add_order_note( $message );
						$this->do_capture_success( $order, $capture_response_array, $already_captured_amount, $capture_amount );
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
		}
		return $response_data;
	}

	/**
	 * Handles the order status if Capture Transaction is successful
	 *
	 * @param \WC_Order  $order order object.
	 * @param array      $capture_response_array array containing response data.
	 * @param float      $already_captured_amount amount previously captured via partial capture(s).
	 * @param float|null $capture_amount the amount requested for this specific capture call.
	 */
	public function do_capture_success( $order, $capture_response_array, $already_captured_amount = VISA_ACCEPTANCE_VAL_ZERO, $capture_amount = null ) {

		$captured_amount = null !== $capture_amount ? (float) $capture_amount : (float) $capture_response_array['amount'];
		$total_captured  = $already_captured_amount + $captured_amount;
		$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $total_captured );
		$this->update_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES );

		// add capture transaction ID.
		if ( $capture_response_array && $capture_response_array['transaction_id'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $capture_response_array['transaction_id'] );
		}

		// This "capture the remaining balance" action never appends to '_vas_partial_captures'
		// (only Visa_Acceptance_Partial_Capture does), so for an APM (e.g. PayPal) order that
		// already has a partial capture on record, a refund issued afterwards would have no
		// transaction ID to target for the amount captured here. Record it in that same history
		// so APM refund allocation can find it. Card behaviour is unaffected - card refunds don't
		// consult this history for transaction-id selection.
		$payment_type = $order->get_meta( '_vas_payment_type', true );
		if ( $capture_response_array && $capture_response_array['transaction_id']
			&& VISA_ACCEPTANCE_APM_STRING === strtolower( (string) $payment_type ) ) {
			$capture_history   = $order->get_meta( '_vas_partial_captures', true );
			$capture_history   = is_array( $capture_history ) ? $capture_history : array();
			$capture_history[] = array(
				'sequence'       => count( $capture_history ) + VISA_ACCEPTANCE_VAL_ONE,
				'transaction_id' => $capture_response_array['transaction_id'],
				'amount'         => $captured_amount,
				'currency'       => $order->get_currency(),
				'items'          => array(),
				'shipping'       => false,
				'status'         => isset( $capture_response_array['status'] ) ? $capture_response_array['status'] : VISA_ACCEPTANCE_STRING_EMPTY,
				'date'           => current_time( 'mysql' ),
			);
			$order->update_meta_data( '_vas_partial_captures', $capture_history );
			$order->save();
		}
	}

	/**
	 * Sums the amounts already captured via partial capture(s) for this order.
	 *
	 * @param \WC_Order $order order object.
	 *
	 * @return float
	 */
	public function get_previously_captured_amount( $order ) {
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = is_array( $capture_history ) ? $capture_history : array();
		$total_captured  = VISA_ACCEPTANCE_VAL_ZERO;
		foreach ( $capture_history as $capture ) {
			$total_captured += floatval( $capture['amount'] );
		}
		return $total_captured;
	}

	/**
	 * Gets the sequence number this capture would occupy in the partial capture history.
	 *
	 * @param \WC_Order $order order object.
	 *
	 * @return int
	 */
	public function get_next_capture_sequence_number( $order ) {
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = is_array( $capture_history ) ? $capture_history : array();
		return count( $capture_history ) + VISA_ACCEPTANCE_VAL_ONE;
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
	 * @param \WC_Order  $order order object.
	 * @param string     $transaction_id transaction id.
	 * @param float|null $capture_amount amount to capture; defaults to the full order total when null.
	 * @param bool       $has_prior_captures whether partial capture(s) already exist for this order.
	 * @param int        $capture_sequence_number sequence number of this capture within the partial capture history.
	 *
	 * @return array
	 */
	public function execute_capture( $order, $transaction_id, $capture_amount = null, $has_prior_captures = false, $capture_sequence_number = VISA_ACCEPTANCE_VAL_ONE ) {
		$request     = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client  = $request->get_api_client();
		$capture_api = new \Pymt_Vas\Dependencies\CyberSource\Api\CaptureApi( $api_client );
		
		$request_obj = new \Pymt_Vas\Dependencies\CyberSource\Model\CapturePaymentRequest();
		$request_obj->setClientReferenceInformation( $request->client_reference_information( $order ) );

		$payment_solution_data  = $request->get_payment_solution( $order );
		$capture_processing_array = array(
			'commerceIndicator' => $payment_solution_data['commerceIndicator'],
		);
		if ( ! empty( $payment_solution_data['paymentSolution'] ) ) {
			$capture_processing_array['paymentSolution'] = $payment_solution_data['paymentSolution'];
		}
		// PayPal (Alt Pay) requires captureOptions to close out a sequence started by a prior partial capture.
		if ( $has_prior_captures ) {
			$capture_processing_array['captureOptions'] = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesProcessingInformationCaptureOptions(
				array(
					'captureSequenceNumber' => $capture_sequence_number,
					'totalCaptureCount'     => $capture_sequence_number,
				)
			);
		}
		$processing_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesProcessingInformation( $capture_processing_array );
		$request_obj->setProcessingInformation( $processing_information );

		$amount_details = null === $capture_amount
			? $request->order_information_amount_details( $order )
			: new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
				array(
					'totalAmount' => number_format( $capture_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
					'currency'    => $order->get_currency(),
				)
			);

		$order_information_array = array( 'amountDetails' => $amount_details );
		// Omit line items when prior partial captures exist so their sum doesn't mismatch the remaining totalAmount (PayPal rejects this).
		if ( ! $has_prior_captures ) {
			$order_information_array['lineItems'] = $request->get_line_items_information( $order );
		}

		$order_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesOrderInformation( $order_information_array );
		$request_obj->setOrderInformation( $order_information );
		$log_request_data = json_decode( wp_json_encode( \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $request_obj ) ), true );
		$log_request_data = ( false !== $log_request_data && is_array( $log_request_data ) ) ? $log_request_data : array();
		$this->gateway->add_logs_data( $log_request_data, true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );
		try {
			$api_response = $capture_api->capturePayment( $request_obj, $transaction_id );
			// Log encrypted response FIRST (if it's encrypted).
			$response_body_raw = $api_response[VISA_ACCEPTANCE_VAL_ZERO];
			$this->gateway->add_logs_service_response( $response_body_raw, $api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );
			
			// Decrypt MLE response if needed.
			$payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );
			
			// Log decrypted response if it was encrypted.
			if ( $response_body !== $response_body_raw ) {
				$this->gateway->add_logs_service_response( 
					$response_body, 
					$api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], 
					true, 
					ucfirst( VISA_ACCEPTANCE_CAPTURE ) 
				);
			}

			return array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $response_body,
			);
		} catch ( \Throwable $e ) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, ucfirst( VISA_ACCEPTANCE_CAPTURE ) );
		}
	}
}
