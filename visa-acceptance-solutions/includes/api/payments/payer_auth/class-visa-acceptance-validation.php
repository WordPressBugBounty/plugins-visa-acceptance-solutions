<?php
/**
 * WooCommerce Visa Acceptance Solutions
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@visa-acceptance-solutions.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Visa Acceptance Solutions newer
 * versions in the future. If you wish to customize WooCommerce Visa Acceptance Solutions for your
 * needs please refer to http://docs.woocommerce.com/document/visa-acceptance-solutions-payment-gateway/
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../class-visa-acceptance-request.php';
require_once __DIR__ . '/../../class-visa-acceptance-api-client.php';
require_once __DIR__ . '/../class-visa-acceptance-payment-methods.php';
require_once __DIR__ . '/../../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/../../../class-visa-acceptance-payment-gateway-subscriptions.php';

use CyberSource\Api\PaymentsApi;
use CyberSource\Model\CreatePaymentRequest;

/**
 * Visa Acceptance Validation Class
 * Provides functionality for Validation request for payer-auth
 */
class Visa_Acceptance_Validation extends Visa_Acceptance_Request {

	/**
	 * Validation constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Check order details for Payer-auth validation request
	 *
	 * @param \WC_Order         $order order object.
	 * @param string            $card_token card token.
	 * @param \WC_Payment_Token $saved_token saved token data if using saved card.
	 * @param string            $token_check_box indicates whether token check box is checked.
	 * @param string            $auth_id auth id.
	 * @param string            $pareq Payer Auth Request Signature.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	public function do_validation( $order, $card_token, $saved_token, $token_check_box, $auth_id, $pareq, $sca_case ) {
		$response = array();
		if ( $this->gateway->get_id() === $order->data['payment_method'] || 'admin' === $order->created_via ) {
			$response = $this->handleValidationResponse( $order, $card_token, $saved_token, $token_check_box, $auth_id, $pareq, $sca_case );
		}
		return $response;
	}

	/**
	 * Handles Validation Response, when Set-up request has successfully done.
	 *
	 * @param \WC_Order         $order order object.
	 * @param string            $jwt jwt token.
	 * @param \WC_Payment_Token $saved_token token.
	 * @param string            $token_check_box indicates whether token check box is checked.
	 * @param string            $validation_tid validation TID.
	 * @param string            $pareq Payer Auth Request Signature.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	private function handleValidationResponse( $order, $jwt, $saved_token, $token_check_box, $validation_tid, $pareq, $sca_case ) {
		$response_array = null;
		$settings       = $this->gateway->get_config_settings();
		// Getting the response from api call.
		$validation_response                        = $this->getPayerAuthValidationResponse( $order, $jwt, $saved_token, $token_check_box, $validation_tid, $pareq, $sca_case );
		$auth_response                              = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$subscriptions      						= new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$request                                    = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$http_code                                  = $validation_response['http_code'];
		$json                                       = json_decode( $validation_response['body'] );
		$status                                     = $json->status;
		$payment_response_array                     = $this->get_payment_response_array( $http_code, $validation_response['body'], $status );
		$return_response[ VISA_ACCEPTANCE_SUCCESS ] = null;
		$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = null;

		if ( VISA_ACCEPTANCE_YES === $settings['enable_saved_sca'] && VISA_ACCEPTANCE_YES === $token_check_box ) {
			if ( VISA_ACCEPTANCE_STRING_CUSTOMER_AUTHENTICATION_REQUIRED === $payment_response_array['reason'] ) {
				$this->mark_order_failed( $payment_response_array['reason'] );
				$this->update_failed_order( $order, $payment_response_array );
				$response_array[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_SERVER_ERROR;
				$checkout_url                                   = wc_get_checkout_url();
				$response_array['redirect']                     = $checkout_url;
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order->get_id() );
				return $response_array;
			}
		}

		// Handling the response.
		if ( ( $auth_response->is_transaction_approved( $validation_response, $payment_response_array['status'] ) ) ) {
			$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
			$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order->get_id() );
			if ( ( $auth_response->is_transaction_status_approved( $payment_response_array['status'] ) ) ) {
				if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $payment_response_array['status'] ) {
					if ( VISA_ACCEPTANCE_YES === $token_check_box ) {
						$response = $this->save_payment_method( $validation_response );
					}
					if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) && ( $response['status'] && isset( $response['token'] ) || ! empty( $saved_token ) ) ) {
						$subscription_token = ( $response['status'] && isset( $response['token'] ) ) ? $response['token'] : $saved_token->get_token();
						$subscriptions->update_order_subscription_token( $order, $subscription_token );
						$subscriptions->add_payment_data_to_subscription( $order );
					}
				}
				$is_charge_transaction = VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) );
				$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
				$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );
				if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
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

					/**  Here if response is success then update responsearray[redirect] with order completion page
					 *   and status and if not then update it with checkout page URL.
					*/
					$response_array['status'] = $json->status;
			if ( $return_response[ VISA_ACCEPTANCE_SUCCESS ] ) {
				WC()->cart->empty_cart();
				// Order completion URL.
				$redirect                   = $this->gateway->get_return_url( $order );
				$response_array['redirect'] = $redirect;
			} else {
				$message = $payment_response_array['reason'];
				if ( ! isset( $message ) || VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT === $payment_response_array['reason'] ) {
					$message = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
				}

				$this->mark_order_failed( $message );
				// Checkout Page URL.
				$checkout_url                                   = wc_get_checkout_url();
				$response_array['redirect']                     = $checkout_url;
				$response_array[ VISA_ACCEPTANCE_STRING_ERROR ] = $message;
			}

					return $response_array;
		} else {
			return $request->get_error_message( $payment_response_array, $order, $json, $sca_case );
		}
	}

	/**
	 * Gets Payer-Auth Validation Response
	 *
	 * @param \WC_Order         $order order object.
	 * @param string            $jwt jwt token.
	 * @param \WC_Payment_Token $saved_token saved_token.
	 * @param string            $token_check_box indicates whether the token check box is checked.
	 * @param string            $validation_tid validation TID.
	 * @param string            $pareq Payer Auth Request Signature.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	private function getPayerAuthValidationResponse( $order, $jwt, $saved_token, $token_check_box, $validation_tid, $pareq, $sca_case ) {
		$settings       = $this->gateway->get_config_settings();
		$log_header     = ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] ) ? VISA_ACCEPTANCE_VALIDATION_CHARGE : VISA_ACCEPTANCE_VALIDATION_AUTHORIZATION;
		$saved_card_cvv = $this->get_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
		$request      	= new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client   	= $request->get_api_client();
		$payments_api 	= new PaymentsApi( $api_client );
		if ( $saved_token ) {
			$is_stored_card = false;
			$payment_method = new Visa_Acceptance_Payment_Methods( $this );
			$token_data     = $payment_method->build_token_data( $saved_token );

			// Checking if token passed in args is a saved card token.
			$user_id    = get_current_user_id();
			$savetokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->gateway->get_id() );

			// check if token is from visa acceptance token table.
			foreach ( $savetokens as $savetoken ) {
				if ( $savetoken->get_id() === $saved_token->get_id() ) {
					$is_stored_card = true;
					break;
				}
			}
		}

		$processing_information_data = $request->get_processing_info( $order, $settings, $token_check_box, 'validate', $is_stored_card );
		$processing_information      = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_information_data );

		if ( ! empty( $pareq ) && VISA_ACCEPTANCE_YES === $sca_case ) {
				$consumer_authentication_information = new \CyberSource\Model\Ptsv2paymentsConsumerAuthenticationInformation(
					array(
						'authenticationTransactionId' => $validation_tid,
						'signedPares'                 => $pareq,
					)
				);
		} else {
			$consumer_authentication_information = new \CyberSource\Model\Ptsv2paymentsConsumerAuthenticationInformation(
				array(
					'authenticationTransactionId' => $validation_tid,
					'signedPares'                 => null,
				)
			);
		}

		$validation_request = array(
			'clientReferenceInformation'        => $request->client_reference_information( $order ),
			'processingInformation'             => $processing_information,
			'consumerAuthenticationInformation' => $consumer_authentication_information,
			'orderInformation'                  => $request->get_payment_order_information( $order ),
			'deviceInformation'                 => $request->get_device_information(),
			'buyerInformation'                  => $request->get_payment_buyer_information( $order ),
		);
		if ( $is_stored_card && empty( $jwt ) ) {
			$validation_request['paymentInformation'] = $request->get_cybersource_payment_information( $token_data, $saved_card_cvv );

			$payload = new CreatePaymentRequest( $validation_request );
		} else {
			$validation_request['tokenInformation'] = $request->get_cybersource_token_information( $jwt );
			$payload                                = new CreatePaymentRequest( $validation_request );
			if ( VISA_ACCEPTANCE_YES === $token_check_box ) {
				$payload = $request->get_action_token_type( $payload );
			}
		}

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
