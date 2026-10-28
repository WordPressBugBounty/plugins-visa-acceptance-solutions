<?php
/**
 * Partial Capture Handler
 * Handles partial capture functionality using WooCommerce order meta
 * (The WordPress way - no custom tables!)
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes/api/payments
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
 * Visa Acceptance Partial Capture Class
 * Uses WooCommerce order meta instead of custom tables
 */
class Visa_Acceptance_Partial_Capture extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 * @var object $gateway
	 */
	public $gateway;

	/**
	 * Constructor
	 *
	 * @param object $gateway Gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Performs partial capture based on selected quantities
	 * @param \WC_Order $order Order object.
	 * @param array     $quantities Quantities per line item.
	 * @param bool      $include_shipping Include shipping in capture.
	 * @param array     $response_data Response data array.
	 *
	 * @return array
	 */
	public function perform_partial_capture( $order, $quantities, $include_shipping, $response_data ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		$authorization_amount = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'authorization_amount' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT );
		$transaction_id       = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? $this->get_order_meta( $order, 'trans_id' ) : $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );

		// Get capture history from order meta.
		$capture_history = $this->get_capture_history( $order );
		$total_captured_amount = VISA_ACCEPTANCE_VAL_ZERO;
		$captured_items = array();

		// Calculate what's already been captured.
		foreach ( $capture_history as $capture ) {
			$total_captured_amount += floatval( $capture['amount'] );
			foreach ( $capture['items'] as $item_id => $qty ) {
				$captured_items[ $item_id ] = ( $captured_items[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO ) + $qty;
			}
		}

		// Calculate capture amount and build line items.
		$capture_amount = VISA_ACCEPTANCE_VAL_ZERO;
		$line_items     = array();
		$order_items    = $order->get_items();
		$total_capture_quantity = VISA_ACCEPTANCE_VAL_ZERO;
		$total_order_quantity = VISA_ACCEPTANCE_VAL_ZERO;

		foreach ( $order_items as $item_id => $item ) {
			$total_order_quantity += $item->get_quantity();
			$quantity = isset( $quantities[ $item_id ] ) ? intval( $quantities[ $item_id ] ) : VISA_ACCEPTANCE_VAL_ZERO;
			
			if ( $quantity > VISA_ACCEPTANCE_VAL_ZERO ) {
				$item_total = $item->get_total() + $item->get_total_tax();
				$item_price = $item_total / $item->get_quantity();
				
				$capture_amount += $item_price * $quantity;
				$total_capture_quantity += $quantity;
				
				$line_items[] = array(
					'item_id'   => $item_id,
					'product_id' => $item->get_product_id(),
					'name'      => $item->get_name(),
					'quantity'  => $quantity,
					'price'     => number_format( $item->get_total() / $item->get_quantity(), VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
					'tax'       => number_format( $item->get_total_tax() / $item->get_quantity(), VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				);
			}
		}

		// Handle shipping.
		$shipping_amount = VISA_ACCEPTANCE_VAL_ZERO;
		$shipping_captured = $this->is_shipping_captured( $order );
		
		if ( $include_shipping && ! $shipping_captured ) {
			$shipping_amount = $order->get_shipping_total() + $order->get_shipping_tax();
			$capture_amount += $shipping_amount;
		}

		// Determine if this is the final capture.
		$total_items_captured = array_sum( $captured_items ) + $total_capture_quantity;
		$is_final_capture = ( $total_items_captured >= $total_order_quantity )
			|| ( ( $total_captured_amount + $capture_amount ) >= $authorization_amount );

		// Get next sequence number.
		$sequence_number     = count( $capture_history ) + VISA_ACCEPTANCE_VAL_ONE;
		$total_capture_count = $is_final_capture ? $sequence_number : 99;

		// Execute the capture.
		$capture_response = $this->execute_partial_capture(
			$order,
			$transaction_id,
			$line_items,
			$capture_amount,
			$include_shipping,
			$shipping_amount,
			$sequence_number,
			$total_capture_count,
			$is_final_capture
		);

		$http_code    = $capture_response['http_code'];
		$capture_body = $capture_response['body'];

		if ( VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code ) {
			$capture_body = wp_json_encode( $capture_body['body'] );
		}

		$capture_response_array = $this->get_payment_response_array(
			$http_code,
			$capture_body,
			VISA_ACCEPTANCE_PARTIAL_CAPTURE
		);

		$status = $capture_response_array['status'];
		$capture_response_obj = new Visa_Acceptance_Capture_Response( $this->gateway );

		if ( $capture_response_obj->is_transaction_approved( $capture_response, $status ) ) {
			// Save capture to order meta.
			$capture_record = array(
				'sequence'       => $sequence_number,
				'transaction_id' => $capture_response_array['transaction_id'],
				'amount'         => $capture_amount,
				'currency'       => $order->get_currency(),
				'items'          => array(),
				'shipping'       => $include_shipping && ! $shipping_captured,
				'status'         => $status,
				'date'           => current_time( 'mysql' ),
			);

			// Add line items to capture record.
			foreach ( $line_items as $item ) {
				$capture_record['items'][ $item['item_id'] ] = $item['quantity'];
			}

			// Add to capture history using order meta.
			$capture_history[] = $capture_record;
			$order->update_meta_data( '_vas_partial_captures', $capture_history );

			// Update capture transaction ID.
			$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $capture_response_array['transaction_id'] );

			// Keep a running total independent of the '_vas_partial_captures' array so refund
			// routing has a fallback if that history can't be read back for any reason.
			$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $total_captured_amount + $capture_amount );

			// Check if fully captured.
			if ( $is_final_capture ) {
				$this->update_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES );
				$message = sprintf(
					/* translators: %1$s - payment gateway title */
					__( '%1$s - Full Capture Complete', 'visa-acceptance-solutions' ),
					$this->gateway->get_title()
				);
				$credit_fields = $this->build_response_credit_fields( $capture_response_array );
				if ( ! empty( $credit_fields ) ) {
					$message .= "\n" . implode( "\n", $credit_fields );
				}

				$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, $message );
			} else {
				// Partial capture - keep on hold.
				$this->update_order_meta( $order, '_vas_partial_capture', VISA_ACCEPTANCE_YES );
				$message = sprintf(
					/* translators: %1$s - payment gateway title, %2$s - captured amount */
					__( '%1$s - Partial Capture of %2$s Approved:', 'visa-acceptance-solutions' ),
					$this->gateway->get_title(),
					wc_price( $capture_amount, array( 'currency' => $order->get_currency() ) )
				);
				$credit_fields = $this->build_response_credit_fields( $capture_response_array );
				if ( ! empty( $credit_fields ) ) {
					$message .= "\n" . implode( "\n", $credit_fields );
				}

				$order->add_order_note( $message );
			}

			$order->save();

			$response_data[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_YES;
			// Mirror the order-note wording so the admin alert matches what's logged on the order.
			$response_data['alert_message'] = sprintf(
				/* translators: %1$s - captured amount, %2$s - transaction ID */
				__( 'Partial Capture of %1$s Approved (Transaction ID %2$s)', 'visa-acceptance-solutions' ),
				html_entity_decode( wp_strip_all_tags( wc_price( $capture_amount, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES ),
				$capture_response_array['transaction_id']
			);
		} else {
			$response_data[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_NO;
			$response_data['error_message'] = __( 'Partial capture failed.', 'visa-acceptance-solutions' );
		}

		return $response_data;
	}

	/**
	 * Execute the partial capture API call
	 * @param \WC_Order $order Order object.
	 * @param string    $transaction_id Auth transaction ID.
	 * @param array     $line_items Line items to capture.
	 * @param float     $capture_amount Total capture amount.
	 * @param bool      $include_shipping Include shipping flag.
	 * @param float     $shipping_amount Shipping amount.
	 * @param int       $sequence_number Sequence number.
	 * @param int       $total_capture_count Total capture count.
	 * @param bool      $is_final Whether to release any remaining authorized-but-uncaptured balance.
	 *
	 * @return array
	 */
	public function execute_partial_capture( $order, $transaction_id, $line_items, $capture_amount, $include_shipping, $shipping_amount, $sequence_number, $total_capture_count, $is_final = false ) {
		$request     = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client  = $request->get_api_client();
		$capture_api = new \Pymt_Vas\Dependencies\CyberSource\Api\CaptureApi( $api_client );
		
		$request_obj = new \Pymt_Vas\Dependencies\CyberSource\Model\CapturePaymentRequest();
		$request_obj->setClientReferenceInformation( $request->client_reference_information( $order ) );

		$payment_solution_data = $request->get_payment_solution( $order );
		
		// Add capture options for partial capture.
		$capture_options = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesProcessingInformationCaptureOptions(
			array(
				'captureSequenceNumber' => $sequence_number,
				'totalCaptureCount'     => $total_capture_count,
				'isFinal'               => $is_final ? 'true' : 'false',
			)
		);
		
		$capture_processing_array = array(
			'commerceIndicator' => $payment_solution_data['commerceIndicator'],
			'captureOptions'    => $capture_options,
		);
		
		if ( ! empty( $payment_solution_data['paymentSolution'] ) ) {
			$capture_processing_array['paymentSolution'] = $payment_solution_data['paymentSolution'];
		}
		
		$processing_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesProcessingInformation( $capture_processing_array );
		$request_obj->setProcessingInformation( $processing_information );

		// Build line items for API.
		$api_line_items = array();
		foreach ( $line_items as $item ) {
			$api_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationLineItems(
				array(
					'productCode' => 'default',
					'productName' => $item['name'],
					'productSku'  => (string) $item['product_id'],
					'quantity'    => $item['quantity'],
					'unitPrice'   => $item['price'],
					'taxAmount'   => $item['tax'],
				)
			);
		}

		// Add shipping line item.
		if ( $include_shipping && $shipping_amount > VISA_ACCEPTANCE_VAL_ZERO ) {
			$shipping_total = $order->get_shipping_total();
			$shipping_tax   = $order->get_shipping_tax();
			
			$api_line_items[] = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationLineItems(
				array(
					'productCode' => 'shipping_and_handling',
					'productName' => 'Shipping and Handling',
					'productSku'  => 'shipping_and_handling',
					'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
					'unitPrice'   => number_format( $shipping_total, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
					'taxAmount'   => number_format( $shipping_tax, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
				)
			);
		}

		$order_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesOrderInformation(
			array(
				'amountDetails' => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidcapturesOrderInformationAmountDetails(
					array(
						'totalAmount' => number_format( $capture_amount, VISA_ACCEPTANCE_VAL_TWO, VISA_ACCEPTANCE_FULL_STOP, VISA_ACCEPTANCE_STRING_EMPTY ),
						'currency'    => $order->get_currency(),
					)
				),
				'lineItems'     => $api_line_items,
			)
		);
		
		$request_obj->setOrderInformation( $order_information );
		$log_request_data = json_decode( wp_json_encode( \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $request_obj ) ), true );
		$log_request_data = ( false !== $log_request_data && is_array( $log_request_data ) ) ? $log_request_data : array();
		$this->gateway->add_logs_data( $log_request_data, true, 'Partial Capture' );

		try {
			$api_response = $capture_api->capturePayment( $request_obj, $transaction_id );
			$response_body_raw = $api_response[VISA_ACCEPTANCE_VAL_ZERO];
			$this->gateway->add_logs_service_response( $response_body_raw, $api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, ucfirst( VISA_ACCEPTANCE_PARTIAL_CAPTURE ) );
			
			// Decrypt MLE response if needed.
			$payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, ucfirst( VISA_ACCEPTANCE_PARTIAL_CAPTURE ) );
			
			// Log decrypted response if it was encrypted.
			if ( $response_body !== $response_body_raw ) {
				$this->gateway->add_logs_service_response( 
					$response_body, 
					$api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], 
					true, 
					ucfirst( VISA_ACCEPTANCE_PARTIAL_CAPTURE ));
			}

			return array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $response_body,
			);
		} catch ( \Throwable $e ) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, 'Partial Capture' );
			return array(
				'http_code' => VISA_ACCEPTANCE_FIVE_ZERO_ZERO,
				'body'      => $e->getMessage(),
			);
		}
	}

	/**
	 * Get capture history from order meta
	 * @param \WC_Order $order Order object.
	 *
	 * @return array
	 */
	private function get_capture_history( $order ) {
		$history = $order->get_meta( '_vas_partial_captures', true );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get remaining quantities per line item
	 * @param \WC_Order $order Order object.
	 *
	 * @return array
	 */
	public function get_remaining_quantities( $order ) {
		$capture_history = $this->get_capture_history( $order );
		$captured_items = array();

		// Sum up captured quantities from history.
		foreach ( $capture_history as $capture ) {
			foreach ( $capture['items'] as $item_id => $qty ) {
				$captured_items[ $item_id ] = ( $captured_items[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO ) + $qty;
			}
		}

		$remaining = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$captured = $captured_items[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO;
			$remaining[ $item_id ] = $item->get_quantity() - $captured;
		}

		return $remaining;
	}

	/**
	 * Check if shipping has been captured
	 * @param \WC_Order $order Order object.
	 *
	 * @return bool
	 */
	public function is_shipping_captured( $order ) {
		$capture_history = $this->get_capture_history( $order );
		
		foreach ( $capture_history as $capture ) {
			if ( ! empty( $capture['shipping'] ) ) {
				return true;
			}
		}
		
		return false;
	}
}
