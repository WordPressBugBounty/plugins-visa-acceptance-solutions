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
 * Do not edit or add to this file if you wish to upgrade WooCommerce Visa Acceptance Solutions to newer
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
require_once __DIR__ . '/../class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/../../request/payments/class-visa-acceptance-payer-auth-request.php';
require_once __DIR__ . '/../../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../../../class-visa-acceptance-payment-gateway-subscriptions.php';

use CyberSource\Api\PaymentsApi;
use CyberSource\Model\CreatePaymentRequest;

/**
 * Visa Acceptance Enrollment Class
 * Provides functionality for enrollment request for payer-auth
 */
class Visa_Acceptance_Enrollment extends Visa_Acceptance_Request {

	/**
	 * Enrollment constructor.
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}


	/**
	 * Checks order details for Payer-auth enrollment request.
	 *
	 * @param \WC_Order         $order order details.
	 * @param string            $token token.
	 * @param \WC_Payment_Token $saved_token saved token.
	 * @param string            $token_checkbox indicates whether the token checkbox is checked.
	 * @param string            $reference_id refernce id.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	public function do_enrollment( $order, $token, $saved_token, $token_checkbox, $reference_id, $sca_case ) {
		$response = array();
		if ( $this->gateway->get_id() === $order->data['payment_method'] || 'admin' === $order->created_via ) {
			$response = $this->handleEnrollmentResponse( $order, $token, $saved_token, $token_checkbox, $reference_id, $sca_case );
		}
		return $response;
	}

	/**
	 * Handles Enrollment Response, when order get successfully placed
	 *
	 * @param \WC_Order         $order order details.
	 * @param string            $token token.
	 * @param \WC_Payment_Token $saved_token saved token.
	 * @param string            $token_checkbox indicates whether the token checkbox is checked.
	 * @param string            $reference_id refernce id.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	private function handleEnrollmentResponse( $order, $token, $saved_token, $token_checkbox, $reference_id, $sca_case ) {
		$settings                                        = $this->gateway->get_config_settings();
		$response_array                                  = array();
		$return_response[ VISA_ACCEPTANCE_SUCCESS ]      = null;
		$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = null;
		// Getting the response from api call.
		$enrollment_response = $this->getPayerAuthEnrollmentResponse( $order, $token, $saved_token, $token_checkbox, $reference_id, $sca_case );
		$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions      	= new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$auth_response          = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$json                   = json_decode( $enrollment_response['body'] );
		$status                 = $json->status;
		$http_code              = $enrollment_response['http_code'];
		$payment_response_array = $this->get_payment_response_array( $http_code, $enrollment_response['body'], $status );
		if ( VISA_ACCEPTANCE_YES === $settings['enable_saved_sca'] && VISA_ACCEPTANCE_YES === $token_checkbox ) {
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
		if ( VISA_ACCEPTANCE_PENDING_AUTHENTICATION === $status ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['accessToken'] = isset( $json->consumerAuthenticationInformation->accessToken ) ? $json->consumerAuthenticationInformation->accessToken : null;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['stepUpUrl'] = isset( $json->consumerAuthenticationInformation->stepUpUrl ) ? $json->consumerAuthenticationInformation->stepUpUrl : null;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['status'] = isset( $json->status ) ? $json->status : null;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['pareq'] = isset( $json->consumerAuthenticationInformation->pareq ) ? $json->consumerAuthenticationInformation->pareq : null;

			return $response_array;
		} elseif ( ( $auth_response->is_transaction_approved( $enrollment_response, $payment_response_array['status'] ) ) ) {
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order->get_id() );
			if ( ( $auth_response->is_transaction_status_approved( $payment_response_array['status'] ) ) ) {
				if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $payment_response_array['status'] ) {
					if ( VISA_ACCEPTANCE_YES === $token_checkbox ) {
						$response = $this->save_payment_method( $enrollment_response );
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
				$checkout_url               = wc_get_checkout_url();
				$response_array['redirect'] = $checkout_url;
				$response_array['error']    = $message;
			}
			return $response_array;
		} else {
			return $request->get_error_message( $payment_response_array, $order, $json, $sca_case );
		}
	}

	/**
	 * Gets Payer-auth Enrollment response
	 *
	 * @param object            $order order object.
	 * @param string            $trans_token token.
	 * @param \WC_Payment_Token $saved_token saved token.
	 * @param string            $token_check indicates whether the token check box is checked.
	 * @param string            $reference_id reference.
	 * @param string            $sca_case Flag for verifying SCA case.
	 *
	 * @return array
	 */
	private function getPayerAuthEnrollmentResponse( $order, $trans_token, $saved_token, $token_check, $reference_id, $sca_case ) {
		$settings           = $this->gateway->get_config_settings();
		$saved_card_cvv     = $this->get_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
		$log_header         = VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] ? VISA_ACCEPTANCE_ENROLLMENT_CHARGE : VISA_ACCEPTANCE_ENROLLMENT_AUTHORIZATION;
		$request            = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$payer_auth_request = new Visa_Acceptance_Payer_Auth_Request( $this->gateway );
		// Creating Return URL.
		$plugin_file    = plugin_basename( __FILE__ );
		$parts          = explode( VISA_ACCEPTANCE_SLASH, $plugin_file );
		$plugin_slug    = $parts[0];
		$rest_base_url  = get_rest_url();
		$return_url     = $rest_base_url . $plugin_slug . '/v1/' . VISA_ACCEPTANCE_PAYER_AUTH_RESPONSE_SLUG;
		$token_checkbox = $token_check;
		$api_client 	= $request->get_api_client();
		$payments_api 	= new PaymentsApi( $api_client );

		// Exploding Transient token.
		if ( ! empty( $trans_token ) ) {
			$jti = $request->get_jti_from_transient_token( $trans_token );
		}
		if ( ! empty( $saved_token ) ) {
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

		// Prepare the request payload using CyberSource SDK models.
		$processing_information_data = $request->get_processing_info( $order, $settings, $token_checkbox, 'enroll', $is_stored_card );

		$processing_information = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_information_data );

		$consumer_authentication_information_data = $payer_auth_request->get_enroll_consumer_authentication_info( $reference_id, $return_url, $sca_case, $token_checkbox, $settings );
		$consumer_authentication_information = new \CyberSource\Model\Ptsv2paymentsConsumerAuthenticationInformation( $consumer_authentication_information_data );

		$enrollment_request = array(
			'clientReferenceInformation'        => $request->client_reference_information( $order ),
			'processingInformation'             => $processing_information,
			'consumerAuthenticationInformation' => $consumer_authentication_information,
			'orderInformation'                  => $request->get_payment_order_information( $order ),
			'deviceInformation'                 => $request->get_device_information(),
			'buyerInformation'                  => $request->get_payment_buyer_information( $order ),
		);
		if ( $is_stored_card && empty( $jti ) ) {
			$enrollment_request['paymentInformation'] = $request->get_cybersource_payment_information( $token_data, $saved_card_cvv );
			$payload = new CreatePaymentRequest( $enrollment_request );
		} else {
			// Use transient token.
			$enrollment_request['tokenInformation'] = $request->get_cybersource_token_information( $trans_token );
			$payload = new CreatePaymentRequest( $enrollment_request );
			if ( VISA_ACCEPTANCE_YES === $token_checkbox ) {
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
