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
		$payment_type = $order->get_meta('_vas_payment_type', true);
		if ( empty( $payment_type ) ) {
			$payment_type = VISA_ACCEPTANCE_PAYMENT_TYPE_CARD;
		}
		$is_echeck = ( strtoupper( $payment_type ) === VISA_ACCEPTANCE_CHECK );
		$is_apm    = ( VISA_ACCEPTANCE_APM_STRING === strtolower( (string) $payment_type ) );

		if ( $is_apm ) {
			return $this->do_apm_refund( $order, $amount, $reason );
		}

        $capture_transaction_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID );
        if ( $capture_transaction_id ) {
            // Once an order has been captured (fully or partially), refunds must reference
            // the capture transaction rather than the original authorization transaction.
            $transaction_id = $capture_transaction_id;
        } else {
            $transaction_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) ? $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID ) : $order->get_transaction_id();
        } 
		$order->get_transaction_id();
		$response = false;
		$refund_response_obj = new Visa_Acceptance_Refund_Response( $this->gateway );
		try {
			if ( VISA_ACCEPTANCE_VAL_ZERO !== $order->get_total() && $order->get_total() >= $amount ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( $transaction_id ) {
					$refund_response       = $this->get_refund_response( $order, $amount, $transaction_id, $is_echeck );
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
					$status = $refund_response_array['status'];
					
					// If amount is not in response, use the requested refund amount.
					if ( empty( $refund_response_array['amount'] ) ) {
						$refund_response_array['amount'] = $amount;
					}
					// If currency is not in response, use order currency.
					if ( empty( $refund_response_array['currency'] ) ) {
						$refund_response_array['currency'] = $order->get_currency();
					}
					
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
		} catch ( \Throwable $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get refund transaction', true );
			return new \WP_Error( 'vas_refund_failed', $this->gateway->get_title() . ' ' . __( 'Refund Failed:', 'visa-acceptance-solutions' ) );
		}
	}

	/**
	 * Handles refund transaction for APM (e.g. PayPal) orders, splitting the request
	 * across multiple capture transactions - oldest first - when a single capture's
	 * remaining balance can't cover the requested amount on its own.
	 *
	 * @param \WC_Order $order  order object.
	 * @param string    $amount refund amount.
	 * @param string    $reason reason for refund.
	 *
	 * @return boolean|\WP_Error
	 */
	protected function do_apm_refund( $order, $amount, $reason ) {
		$refund_response_obj = new Visa_Acceptance_Refund_Response( $this->gateway );
		try {
			if ( VISA_ACCEPTANCE_VAL_ZERO === $order->get_total() || $order->get_total() < $amount ) { // phpcs:ignore WordPress.Security.NonceVerification
				$response = $this->get_refund_failed_wp_error( VISA_ACCEPTANCE_INVALID_AMOUNT, VISA_ACCEPTANCE_INVALID_AMOUNT_ERROR );
				$order->add_order_note( $response->get_error_message() );
				return $response;
			}

			$allocations = $this->get_apm_refund_allocations( $order, $amount );
			if ( empty( $allocations ) ) {
				$response = $this->get_refund_failed_wp_error(
					VISA_ACCEPTANCE_INVALID_AMOUNT,
					__( 'Requested amount exceeds the total balance remaining across all captured transactions.', 'visa-acceptance-solutions' )
				);
				$order->add_order_note( $response->get_error_message() );
				return $response;
			}

			$refunded_so_far = VISA_ACCEPTANCE_VAL_ZERO;
			foreach ( $allocations as $allocation ) {
				$leg_amount     = $allocation['amount'];
				$transaction_id = $allocation['transaction_id'];

				$refund_response = $this->get_refund_response( $order, $leg_amount, $transaction_id, false );
				$http_code       = $refund_response['http_code'];
				$refund_body     = $refund_response['body'];
				if ( VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code ) {
					$refund_body = wp_json_encode( $refund_response['body'] );
				}
				$refund_response_array = $this->get_payment_response_array( $http_code, $refund_body, VISA_ACCEPTANCE_REFUND );
				$status = $refund_response_array['status'];

				if ( empty( $refund_response_array['amount'] ) ) {
					$refund_response_array['amount'] = $leg_amount;
				}
				if ( empty( $refund_response_array['currency'] ) ) {
					$refund_response_array['currency'] = $order->get_currency();
				}

				if ( ! $refund_response_obj->is_transaction_approved( $refund_response, $status ) ) {
					if ( $refunded_so_far > VISA_ACCEPTANCE_VAL_ZERO ) {
						// Earlier legs already moved money at the processor before this one failed;
						// surface exactly what succeeded so the merchant can reconcile manually.
						$message = sprintf(
							/* translators: 1: amount already refunded, 2: amount that failed, 3: failure reason */
							esc_html__( 'Refund partially completed: %1$s was refunded before a %2$s portion failed - %3$s. Please verify the processor account and reconcile manually if needed.', 'visa-acceptance-solutions' ),
							wc_price( $refunded_so_far, array( 'currency' => $order->get_currency() ) ),
							wc_price( $leg_amount, array( 'currency' => $order->get_currency() ) ),
							$refund_response_array['reason']
						);
						$order->add_order_note( $message );
						return new \WP_Error( 'vas_refund_failed', $message );
					}
					$response = $this->get_refund_failed_wp_error( $http_code, $refund_response_array['reason'] );
					$order->add_order_note( $response->get_error_message() );
					return $response;
				}

				$this->add_refund_data( $order, $refund_response_array );
				$this->add_refund_order_note( $order, $reason, $refund_response_array );
				$this->record_apm_capture_refund( $order, $transaction_id, $leg_amount );
				$refunded_so_far = round( $refunded_so_far + $leg_amount, VISA_ACCEPTANCE_VAL_TWO );
			}

			$this->mark_order_as_refunded( $order );
			return true;
		} catch ( \Throwable $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get refund transaction', true );
			return new \WP_Error( 'vas_refund_failed', $this->gateway->get_title() . ' ' . __( 'Refund Failed:', 'visa-acceptance-solutions' ) );
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
                if ( ! empty( $refund_response_array['credit_auth_code'] ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_CODE, $refund_response_array['credit_auth_code'] );
                }
                if ( isset( $refund_response_array['credit_auth_response'] ) && VISA_ACCEPTANCE_STRING_EMPTY !== $refund_response_array['credit_auth_response'] ) {
                    $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_RESPONSE, $refund_response_array['credit_auth_response'] );
                }
                if ( ! empty( $refund_response_array['credit_auth_network_transaction_id'] ) ) {
                    $this->add_order_meta( $order, VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID, $refund_response_array['credit_auth_network_transaction_id'] );
                }
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
        $message = $this->gateway->get_title() . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . sprintf(
        /* translators: %1$s - a monetary amount */
            esc_html__( 'Refund of %1$s Approved:', 'visa-acceptance-solutions' ),
            wc_price(
                $refund_response_array['amount'],
                array(
                    'currency' => $order->get_currency(),
                )
            )
        );
        // adds the transaction id (if any) to the order note.
        if ( $refund_response_array['transaction_id'] ) {
            $message .= VISA_ACCEPTANCE_SPACE . sprintf(
                /* translators: %s - transaction id */
                esc_html__( '(Transaction ID %s)', 'visa-acceptance-solutions' ),
                $refund_response_array['transaction_id']
            );
        }
        $credit_fields = $this->build_response_credit_fields( $refund_response_array );
        if ( ! empty( $refund_response_array['credit_auth_network_transaction_id'] ) ) {
            $credit_fields[] = 'Network Transaction ID: ' . $refund_response_array['credit_auth_network_transaction_id'];
        }
        if ( ! empty( $credit_fields ) ) {
            // Remove "(Transaction ID ...)" suffix since Transaction ID is included in credit_fields.
            $transaction_id_pos = strpos( $message, '(Transaction ID' );
            if ( false !== $transaction_id_pos ) {
                $message = rtrim( substr( $message, 0, $transaction_id_pos ) );
            }
            $message .= "\n" . implode( "\n", $credit_fields );
        }
        if ( ! empty( $reason ) ) {
            /* translators: %1$s - reason tag */
            $reason_message = sprintf(
                VISA_ACCEPTANCE_REFUND_MESSAGE,
                $reason,
            );
            $message .= "\n" . $reason_message;
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
			$message = sprintf(
				/* translators: %1$s - payment gateway title , %2$s - error message*/
				esc_html__( '%1$s Refund Failed. %2$s', 'visa-acceptance-solutions' ),
				$this->gateway->get_title(),
				$error_message
			);
			return new \WP_Error( VISA_ACCEPTANCE_WC_UNDERSCORE . $this->gateway->get_id() . '_refund_failed', $message );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, ' Unable to get updates order notes if refund fails', true );
		}
	}

	/**
	 * Generate refund request payload for a payment gateway
	 *
	 * @param object|null $order order object, or null for tokenization verification refunds.
	 * @param string      $amount refund amount.
	 * @param string      $transaction_id transaction id.
	 * @param boolean     $is_echeck whether this is an eCheck refund.
	 * @param string|null $code client reference code, used when $order is null.
	 * @throws \Throwable If the API call fails.
	 * 
	 * @return array
	 */
	public function get_refund_response( $order, $amount, $transaction_id, $is_echeck = false, $code = null ) {
		$request     = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client  = $request->get_api_client();
		$refund_api  = new \Pymt_Vas\Dependencies\CyberSource\Api\RefundApi( $api_client );
		$request_obj = new \Pymt_Vas\Dependencies\CyberSource\Model\RefundPaymentRequest();
		$api_response = array();

		$client_reference_information_partner = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsClientReferenceInformationPartner(
			array(
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			)
		);

		if ( null === $code ) {
			$stored_ref_code = $order ? $this->get_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE ) : null;
			$code            = ! empty( $stored_ref_code ) ? strval( $stored_ref_code ) : strval( $order->get_id() );
		} else {
			$code = strval( $code );
		}
		$client_reference_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidrefundsClientReferenceInformation(
			array(
				'code'               => $code,
				'partner'            => $client_reference_information_partner,
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		$request_obj->setClientReferenceInformation( $client_reference_information );
        $order_payment_type = $order ? $order->get_meta( '_vas_payment_type', true ) : VISA_ACCEPTANCE_PAYMENT_TYPE_CARD;
        $is_order_apm       = ( VISA_ACCEPTANCE_APM_STRING === strtolower( (string) $order_payment_type ) );
        if ( ! $is_order_apm ) {
            $payment_type_model = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidrefundsPaymentInformationPaymentType(
                array( 'name' => $is_echeck ? VISA_ACCEPTANCE_CHECK : VISA_ACCEPTANCE_PAYMENT_TYPE_CARD )
            );
            $request_obj->setPaymentInformation(
                new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidrefundsPaymentInformation(
                    array( 'paymentType' => $payment_type_model )
                )
            );
        }
		$request_obj->setProcessingInformation(
			new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidrefundsProcessingInformation(
			$is_echeck
				? $this->get_echeck_bank_transfer_options()
				: ( function() use ( $request, $order ) {
					$payment_solution_data = $request->get_payment_solution( $order );
					$processing_array = array( 'commerceIndicator' => $payment_solution_data['commerceIndicator'] );
					if ( ! empty( $payment_solution_data['paymentSolution'] ) ) {
						$processing_array['paymentSolution'] = $payment_solution_data['paymentSolution'];
					}
					return $processing_array;
				} )()
			)
		);
	
		$order_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsidrefundsOrderInformation(
			array(
				'amountDetails' => array(
					'totalAmount' => $amount,
					'currency'    => $is_echeck ? get_woocommerce_currency() : $order->get_currency(),
				),
			)
		);
		$request_obj->setOrderInformation( $order_information );
		$this->gateway->add_logs_data( (string) $request_obj, true, VISA_ACCEPTANCE_REFUND );
		try {
			if ( ! $order || VISA_ACCEPTANCE_UC_ID === $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) ) {
				$api_response = $refund_api->refundPayment( $request_obj, $transaction_id );
			}
			// Log encrypted response FIRST (if it's encrypted).
			$response_body_raw = $api_response[VISA_ACCEPTANCE_VAL_ZERO];
			$this->gateway->add_logs_service_response( $response_body_raw, $api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, VISA_ACCEPTANCE_REFUND );
			
			// Decrypt MLE response if needed.
			$payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, VISA_ACCEPTANCE_REFUND );
			
			// Log decrypted response if it was encrypted.
			if ( $response_body !== $response_body_raw ) {
				$this->gateway->add_logs_service_response( 
					$response_body, 
					$api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], 
					true, 
					VISA_ACCEPTANCE_REFUND 
				);
			}
			
			$return_array = array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $response_body,
			);
			return $return_array;
		} catch ( \Throwable $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'REFUND_ERROR', true );
			throw $e;
		}
	}
}
