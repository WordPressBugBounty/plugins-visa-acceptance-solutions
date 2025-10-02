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
	 *
	 * @return array|null
	 */
	public function do_transaction( $order, $token, $saved_card_cvv, $merchant_initiated = false ) {
		try {
			if ( $token->get_meta( 'environment' ) === $this->gateway->get_environment() ) {
				return $this->do_saved_card_transaction( $order, $token, $saved_card_cvv, $merchant_initiated );
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
	 *
	 * @return array
	 */
	public function do_saved_card_transaction( $order, $token, $saved_card_cvv, $merchant_initiated ) {
		$settings                                        = $this->gateway->get_config_settings();
		$payment_method                                  = new Visa_Acceptance_Payment_Methods( $this );
		$auth_response          						 = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$request                						 = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions 									 = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$return_response[ VISA_ACCEPTANCE_SUCCESS ]      = null;
		$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = null;
		try {
			if ( $token ) {
				$data = $payment_method->build_token_data( $token );
				if ( $data ) {
					$payment_response       = $this->get_payment_response_saved_card( $order, $data, $saved_card_cvv, $merchant_initiated );
					if ( ! empty( $payment_response ) && is_array( $payment_response ) ) {
						$http_code              = $payment_response['http_code'];
						$payment_body 			= $payment_response['body'];
						if(VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code) {
							$payment_body = wp_json_encode($payment_response['body']);
						}
						$payment_response_array = $this->get_payment_response_array( $http_code, $payment_body, VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED );
						$status                 = $payment_response_array['status'];
						if ( $auth_response->is_transaction_approved( $payment_response, $payment_response_array['status'] ) ) {
							if ( $auth_response->is_transaction_status_approved( $payment_response_array['status'] ) ) {
								$is_charge_transaction = VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) );
								$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
								$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );
								if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
									if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) ) {
										$subscriptions->update_order_subscription_token( $order, $data['token'] );
									}
									if ( $is_charge_transaction ) {
										$this->add_capture_data( $order, $payment_response_array );
										$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );

									} else {
										$this->add_transaction_data( $order, $payment_response_array );
										$this->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
									}
								} else {
									$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
									$this->add_review_transaction_data( $order, $payment_response_array );
									$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, null );

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
					else {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_STRING_EMPTY );
					}
				} else {
					$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_DATA;
				}
			}
			return $return_response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles saved Credit-card transaction', true );
		}
	}

	/**
	 * Generate payment response payload for Credit-card transaction.
	 *
	 * @param \WC_Order $order order.
	 * @param array     $token_data saved card token data.
	 * @param string    $saved_card_cvv saved card cvv.
	 * @param boolean   $merchant_initiated merchant initiated transaction.
	 */
	public function get_payment_response_saved_card( $order, $token_data, $saved_card_cvv, $merchant_initiated ) {
		$settings     = $this->gateway->get_config_settings();
		$log_header   = ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] ) ? ucfirst( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE ) : VISA_ACCEPTANCE_AUTHORIZATION;
		$request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client   = $request->get_api_client();
		$payments_api = new PaymentsApi( $api_client );

		// Build the payload using CyberSource SDK models.
		$processing_information = $request->get_processing_info( $order, $settings, null, null, true, $merchant_initiated );

		$processing_information = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_information );

		$payload = new \CyberSource\Model\CreatePaymentRequest(
			array(
				'clientReferenceInformation' => $request->client_reference_information( $order ),
				'processingInformation'      => $processing_information,
				'paymentInformation'         => $request->get_cybersource_payment_information( $token_data, $saved_card_cvv ),
				'orderInformation'           => $request->get_payment_order_information( $order ),
				'deviceInformation'          => $request->get_device_information(),
				'buyerInformation'           => $request->get_payment_buyer_information( $order ),
			)
		);
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( $payload, true, $log_header );
			try {
				$api_response = $payments_api->createPayment( $payload );
				$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, $log_header );
				$return_array = array(
				'http_code' => $api_response[1],
				'body'      => $api_response[0],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}
  		}
	}
}
