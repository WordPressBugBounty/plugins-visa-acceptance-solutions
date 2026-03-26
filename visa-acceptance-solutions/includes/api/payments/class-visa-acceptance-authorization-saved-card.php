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
require_once __DIR__ . '/../payments/class-visa-acceptance-payment-methods.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/../../class-visa-acceptance-payment-gateway-subscriptions.php';

use CyberSource\Api\PaymentsApi;

/**
 * Visa Acceptance Authorization Saved Card Class
 * Handles Authorization requests using saved cards
 */
class Visa_Acceptance_Authorization_Saved_Card extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * AuthorizationSavedCard constructor.
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Initiates saved Credit-card transaction
	 *
	 * @param \WC_Order         $order order.
	 * @param \WC_Payment_Token $token token.
	 * @param string            $saved_card_cvv saved card cvv.
	 * @param boolean           $merchant_initiated merchant initiated transaction.
	 * @param string            $flex_cvv_token JWT token from Flex microform.
	 *
	 * @return array|null
	 */
	public function do_transaction( $order, $token, $saved_card_cvv, $merchant_initiated = false, $flex_cvv_token = null ) {
		try {
			if ( $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) === $this->gateway->get_environment() ) {
				return $this->do_saved_card_transaction( $order, $token, $saved_card_cvv, $merchant_initiated, $flex_cvv_token );
			} else {
				return; // phpcs:ignore WordPress.Security.NonceVerification
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to initiates Saved Credit-card transaction', true );
		}
	}

	/**
	 * Handles saved Credit-card transaction
	 *
	 * @param \WC_Order         $order order object.
	 * @param \WC_Payment_Token $token token object.
	 * @param string            $saved_card_cvv saved card cvv.
	 * @param boolean           $merchant_initiated merchant initiated transaction.
	 * @param string            $flex_cvv_token JWT token from Flex microform.
	 *
	 * @return array
	 */
	public function do_saved_card_transaction( $order, $token, $saved_card_cvv, $merchant_initiated, $flex_cvv_token = null ) {
		$settings                                        = $this->gateway->get_config_settings();
		$payment_method                                  = new Visa_Acceptance_Payment_Methods( $this->gateway );
		$auth_response          						 = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$request                						 = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions 									 = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$return_response[ VISA_ACCEPTANCE_SUCCESS ]      = null;
		$return_response[ VISA_ACCEPTANCE_ERROR ] = null;
		// Check if this is a CUP or JAYWAN stored card for free trial.
		$is_zero_amount_order = ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() );
		$unsupported_zero_amount_card = $is_zero_amount_order && $token && $payment_method->unsupported_zero_amount_saved_card( $token );
		$refund = new Visa_Acceptance_Refund( $this->gateway );

		try {
			if ( $token ) {
				$is_echeck             = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() );
				$is_zero_amount_echeck = $is_echeck && $is_zero_amount_order;
				$data                  = $payment_method->build_token_data( $token );
				if ( ! $data ) {
					$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_DATA;
				} else {
					$payment_response = $this->get_payment_response_saved_card( $order, $data, $saved_card_cvv, $merchant_initiated, $flex_cvv_token, $token );
					if ( empty( $payment_response ) || ! is_array( $payment_response ) ) {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_STRING_EMPTY );
					} else {
						$http_code    = $payment_response['http_code'];
						$payment_body = $payment_response['body'];

						// Ensure body is JSON string for get_payment_response_array.
						if ( VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code || is_array( $payment_body ) ) {
							$payment_body = wp_json_encode( $payment_body);
						}
						$payment_response_array = $this->get_payment_response_array(
							$http_code,
							$payment_body,
							$is_echeck ? VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS : VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED
						);
						$status          = $payment_response_array['status'];
						$is_dm_review    = ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $status || VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $status );
						$is_echeck_valid = $is_echeck && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED ), true );
						$is_card_valid   = ! $is_echeck && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status;

						$has_subscription = $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) );

						if ( $is_dm_review ) {
							if ( $has_subscription ) {
								$subscriptions->update_order_subscription_token( $order, $data['token'] );
							}
							$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
							$this->add_review_transaction_data( $order, $payment_response_array );
							$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING );

							$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;
						} elseif ( $auth_response->is_transaction_approved( $payment_response, $status ) ) {
							if ( $is_echeck_valid || $is_card_valid ) {
								$is_charge_transaction = $is_echeck || ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) ) );
								$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
								$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );
								if ( $has_subscription ) {
									$subscriptions->update_order_subscription_token( $order, $data['token'] );
								}

								if ( $is_charge_transaction ) {
									$this->add_capture_data( $order, $payment_response_array );
									$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
								} else {
									$this->add_transaction_data( $order, $payment_response_array );
									$this->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
								}

								// Execute automatic authorization reversal for CUP/JAYWAN stored cards on free trial orders.
								if ( ! $is_echeck && $unsupported_zero_amount_card && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
									$code = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getClientReferenceInformation' ) && $payment_response['body']->getClientReferenceInformation() )
										? $payment_response['body']->getClientReferenceInformation()->getCode()
										: ( $payment_response_array['client_reference_code'] ?? $order->get_id() );
									$payment_method->process_card_auth_reversal( $payment_response, $code, $order );
								}

								// Refund the $0.01 verification charge for zero-amount eCheck free trial orders.
								if ( $is_zero_amount_echeck && $is_echeck_valid ) {
									$txn_id = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getId' ) ) ? $payment_response['body']->getId() : null;
									if ( $txn_id ) {
										$ref_code = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getClientReferenceInformation' ) && $payment_response['body']->getClientReferenceInformation() )
											? $payment_response['body']->getClientReferenceInformation()->getCode()
											: null;
										$refund->get_refund_response( null, VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT, $txn_id, true, $ref_code );
									}
								}

								$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;
							} else {
								$this->add_transaction_data( $order, $payment_response_array );
								$this->update_order_notes( VISA_ACCEPTANCE_AUTH_REJECT, $order, $payment_response_array, null );
								$this->update_order_notes( VISA_ACCEPTANCE_REJECT_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED );
								if ( ! $request->auth_reversal_exists( $order, $payment_response_array ) ) {
									$request->do_auth_reversal( $order, $payment_response_array );
								}

								$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
							}
						} else {
							$return_response = $request->get_error_message( $payment_response_array, $order );
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles saved Credit-card transaction', true );
		}

		return $return_response;
	}

	/**
	 * Generate payment response payload for Credit-card transaction.
	 *
	 * @param \WC_Order $order order.
	 * @param array     $token_data saved card token data.
	 * @param string    $saved_card_cvv saved card cvv.
	 * @param boolean   $merchant_initiated merchant initiated transaction.
	 * @param string    $flex_cvv_token JWT token from Flex microform.
	 * @param \WC_Payment_Token $token payment token object.
	 */
	public function get_payment_response_saved_card( $order, $token_data, $saved_card_cvv, $merchant_initiated, $flex_cvv_token = null, $token = null ) {
		$settings     = $this->gateway->get_config_settings();
		$request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client   = $request->get_api_client();
		$payments_api = new PaymentsApi( $api_client );
		$is_echeck    = isset($token_data['is_echeck']) && $token_data['is_echeck'];

		// Determine log header — eCheck is always a charge; cards depend on transaction type setting.
		$log_header = $is_echeck || VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type']
			? ucfirst(VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE)
			: VISA_ACCEPTANCE_AUTHORIZATION;

		// Tag eCheck orders with payment type meta.
		if ( $is_echeck ) {
			$order->update_meta_data('_vas_payment_type', VISA_ACCEPTANCE_CHECK);
			$order->save();
		}

		// Determine whether a zero-amount override is needed.
		$is_zero_amount_order         = (VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total());
		$payment_method               = new Visa_Acceptance_Payment_Methods($this->gateway);
		$unsupported_zero_amount_card = ! $is_echeck && $is_zero_amount_order && $token && $payment_method->unsupported_zero_amount_saved_card($token);

		// Temporarily override order total: CUP/JAYWAN → $1.00, eCheck free-trial → $0.01.
		if ($is_zero_amount_order && ($is_echeck || $unsupported_zero_amount_card)) {
			$override_amount = $is_echeck ? VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT : VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT;
			add_filter(
				'woocommerce_order_get_total',
				function ($total, $filter_order) use ($order, $override_amount) {
					return $filter_order->get_id() === $order->get_id() ? $override_amount : $total;
				},
				VISA_ACCEPTANCE_VAL_TEN,
				VISA_ACCEPTANCE_VAL_TWO
			);
		}

		// Build processing information.
		$processing_information            = $request->get_processing_info($order, $settings, null, null, true, $merchant_initiated);
		$processing_information[VISA_ACCEPTANCE_CAPTURE] = $is_echeck || (! $unsupported_zero_amount_card && $processing_information[VISA_ACCEPTANCE_CAPTURE]);

		if ($is_echeck) {
			$processing_information += $this->get_echeck_bank_transfer_options();
		}

		$processing_information = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_information );
		$payload_data = array(
			'clientReferenceInformation' => $request->client_reference_information( $order ),
			'processingInformation'      => $processing_information,
			'paymentInformation'         => $request->get_cybersource_payment_information( $token_data, $saved_card_cvv, $flex_cvv_token ),
			'deviceInformation'          => $request->get_device_information(),
			'orderInformation'           => $request->get_payment_order_information( $order ),
			'buyerInformation'           => $request->get_payment_buyer_information( $order ),
		);

		// Remove the temporary total override filter now that the payload is built.
		if ($is_zero_amount_order && ($is_echeck || $unsupported_zero_amount_card)) {
			remove_all_filters('woocommerce_order_get_total');
		}

		// Add token information if JWT token is available - use the payment adapter method.
		if ( ! empty( $flex_cvv_token ) ) {
			$payload_data['tokenInformation'] = $request->get_cybersource_token_information( $flex_cvv_token );
			
		}
		$payload = new \CyberSource\Model\CreatePaymentRequest( $payload_data );
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( $payload, true, $log_header );
		try {
				$api_response = $payments_api->createPayment( $payload );
			$response_body = $api_response[VISA_ACCEPTANCE_VAL_ZERO];
				if ( is_string( $response_body ) && $this->is_jwe_response( $response_body ) ) {
					$merchant_config = $request->get_merchant_configuration( true );
					$response_body = $this->decrypt_response_if_encrypted( $response_body, $merchant_config[VISA_ACCEPTANCE_VAL_ONE] );
			}

				$this->gateway->add_logs_service_response( $response_body, $api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, $log_header );
				$return_array = array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $response_body,
			);
				return $return_array;
			} catch ( \Throwable $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}
		}
	}
}
