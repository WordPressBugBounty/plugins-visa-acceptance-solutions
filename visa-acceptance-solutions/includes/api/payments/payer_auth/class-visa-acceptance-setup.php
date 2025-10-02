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
require_once __DIR__ . '/../../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../class-visa-acceptance-payment-methods.php';

use CyberSource\Api\PayerAuthenticationApi;
use CyberSource\Model\PayerAuthSetupRequest;

/**
 * Visa Acceptance Setup Class
 * Provides functionality for setup request for payer-auth
 */
class Visa_Acceptance_Setup extends Visa_Acceptance_Request {

	/**
	 * Setup constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Checks order details for Payer-auth set-up request
	 *
	 * @param string            $token token.
	 * @param \WC_Payment_Token $saved_token saved token.
	 * @param int               $order_id order id.
	 *
	 * @return array
	 */
	public function do_setup( $token, $saved_token, $order_id ) {
		$order          = wc_get_order( $order_id );
		$response_array = array();
		$settings       = $this->gateway->get_config_settings();
		try {
			if ( $this->gateway->get_id() === $order->get_payment_method() || 'admin' === $order->created_via ) {
				if ( isset( $settings['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $settings['enable_threed_secure'] ) {
					// Getting the response.
					$setup_response         = $this->getPayerAuthSetUpResponse( $token, $saved_token, $order_id );
					$json                   = json_decode( $setup_response['body'] );
					$http_code              = $setup_response['http_code'];
					$status                 = $json->status;
					$setup_body = $setup_response['body'];
					if(VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code) {
						$setup_body = wp_json_encode($setup_response['body']);
					}
					$payment_response_array = $this->get_payment_response_array( $http_code, $setup_body, $status );

					// Handling the response.
					if ( VISA_ACCEPTANCE_STRING_COMPLETED === $status ) {
						//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$response_array['accessToken'] = isset( $json->consumerAuthenticationInformation->accessToken ) ? $json->consumerAuthenticationInformation->accessToken : null;
						//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$response_array['referenceId'] = isset( $json->consumerAuthenticationInformation->referenceId ) ? $json->consumerAuthenticationInformation->referenceId : null;
						//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$response_array['dataCollectionUrl'] = isset( $json->consumerAuthenticationInformation->deviceDataCollectionUrl ) ? $json->consumerAuthenticationInformation->deviceDataCollectionUrl : null;
						//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$response_array['status'] = isset( $json->status ) ? $json->status : null;
					} else {
						$message = $payment_response_array['reason'];
						if ( ! isset( $message ) || VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT === $payment_response_array['reason'] ) {
							$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_STRING_EMPTY );
							$message = __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
						}
						$this->mark_order_failed( $message );
						$checkout_url                       = wc_get_checkout_url();
						$response_array['status']           = $json->status;
						$response_array['checkoutRedirect'] = $checkout_url;
						$response_array['error']            = $message;
					}
				}
			}
			return $response_array;
		} catch ( Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Order details for payer-auth', true );
		}
	}

	/**
	 * Gets Payer-Auth Setup Response
	 *
	 * @param string            $token token.
	 * @param \WC_Payment_Token $saved_token saved token.
	 * @param int               $order_id order id.
	 * @return array
	 */
	private function getPayerAuthSetUpResponse( $token, $saved_token, $order_id ) {
		$settings           = $this->gateway->get_config_settings();
		$request = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		// token used for payment, need to get via ajax call.
		$customer_token_id = null;
		$card_is_saved     = false;
		if ( ! empty( $saved_token ) ) {
			$payment_method    = new Visa_Acceptance_Payment_Methods( $this );
			$token_data        = $payment_method->build_token_data( $saved_token );
			$customer_token_id = $token_data['token_information']['id'];
			$user_id           = get_current_user_id();
			$savetokens        = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->gateway->get_id() );

			// check if token is from our table.
			foreach ( $savetokens as $savetoken ) {
				if ( $savetoken->get_id() === $saved_token->get_id() ) {
					$card_is_saved = true;
					break;
				}
			}
		}
		// creating the Payload.
		$order        = wc_get_order( $order_id );
		$api_client   = $request->get_api_client();
		$payments_api = new PayerAuthenticationApi( $api_client );

		$customer_payment_information = new \CyberSource\Model\Riskv1authenticationsetupsPaymentInformationCustomer(
			array(
				'customerId' => $customer_token_id,
			)
		);
		$payment_information = new \CyberSource\Model\Riskv1authenticationsetupsPaymentInformation(
			array(
				'customer' => $customer_payment_information,
			)
		);

		if ( $card_is_saved && empty( $token ) ) {

			$payload = new PayerAuthSetupRequest(
				array(
					'clientReferenceInformation' => $request->client_reference_information( $order ),
					'paymentInformation'         => $payment_information,
				)
			);
		} else {
			$payload = new PayerAuthSetupRequest(
				array(
					'clientReferenceInformation' => $request->client_reference_information( $order ),
					'tokenInformation'           => $request->get_cybersource_token_information( $token ),
				)
			);
		}
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( $payload, true, VISA_ACCEPTANCE_SETUP );
		
			try {
				$api_response = $payments_api->payerAuthSetup( $payload );
				$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, VISA_ACCEPTANCE_SETUP );
				$return_array = array(
					'http_code' => $api_response[1],
					'body'      => $api_response[0],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, VISA_ACCEPTANCE_SETUP );
			}
		}
	}
}
