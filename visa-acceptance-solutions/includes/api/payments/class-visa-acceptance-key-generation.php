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
require_once __DIR__ . '/../request/payments/class-visa-acceptance-key-generation-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

use Pymt_Vas\Dependencies\CyberSource\Api\UnifiedCheckoutV1CaptureContextApi;
use Pymt_Vas\Dependencies\CyberSource\Model\GenerateUnifiedCheckoutV1CaptureContextRequest;

/**
 * Visa Acceptance Key Generation Request Class.
 *
 * Handles key generation requests.
 */
class Visa_Acceptance_Key_Generation extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * Key_Generation constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Fetches the Capture Context for Unified Checkout.
	 *
	 * @param bool  $is_ep Whether it's Express Pay.
	 * @param int   $product_id The product ID (optional).
	 * @param int   $quantity The product quantity (optional).
	 * @param array $grouped_items The grouped items (optional).
	 * @param float $switch_amount The switch amount (optional).
	 * @return array
	 */
	public function get_unified_checkout_capture_context($is_ep = false, $product_id = null, $quantity = null, $grouped_items = array(), $switch_amount = null ) {
		$response                         	= array();
		$log_header							= VISA_ACCEPTANCE_CREDIT_CARD. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
		$do_service_call                  	= true;
		$key_generation_request           	= new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$payment_gateway_unified_checkout 	= new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscription_active              	= $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		$payment_adapter 					= new Visa_Acceptance_Payment_Adapter( $this->gateway );
        
        // Try JWT first (default), fallback to HTTP Signature if sessions endpoint doesn't support JWT.
        try {
            $api_client = $payment_adapter->get_api_client();
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( 'Request MLE Configuration Error' => $e->getMessage() ), false, $log_header, true );
            return $response;
        }
		$capture_context_api 				= new UnifiedCheckoutV1CaptureContextApi( $api_client );

		if ( is_add_payment_method_page() || ( $subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			  if ( $is_ep && ($subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment )) {
                $request = $key_generation_request->get_digital_zero_uc_request();
				$log_header = VISA_ACCEPTANCE_EXPRESS_PAY. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
			} else {
				$request = $key_generation_request->get_zero_uc_request();
			}
		} else {
			if ( ! $is_ep ) {
				$request 	= $key_generation_request->get_uc_request();
			}
			else {
				$request = $key_generation_request->get_digital_uc_request($product_id, $quantity, $grouped_items, $switch_amount );
				$log_header = VISA_ACCEPTANCE_EXPRESS_PAY. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
			}
			$is_cart_switch = false;
			if ( isset( WC()->cart ) ) {
				foreach ( WC()->cart->cart_contents as $cart_item ) {
					if ( ! empty( $cart_item['subscription_switch'] ) ) {
						$is_cart_switch = true;
						break;
					}
				}
			}
			$total_amount = isset( $request['orderInformation']['amountDetails']['totalAmount'] ) ? (string) $request['orderInformation']['amountDetails']['totalAmount'] : null;
			if ( null !== $total_amount && ( VISA_ACCEPTANCE_ZERO_AMOUNT === $total_amount ) && ($subscription_active && WC_Subscriptions_Cart::cart_contains_subscription()) && ! is_product() && ! $is_cart_switch ) {
				$request = $is_ep ? $key_generation_request->get_digital_zero_uc_request() : $key_generation_request->get_zero_uc_request();
			} elseif ( null !== $total_amount && VISA_ACCEPTANCE_ZERO_AMOUNT >= $total_amount ) {
				// For express pay on product page with zero amount, use digital zero request (placeholder).
				if ( $is_ep && is_product() ) {
					$request = $key_generation_request->get_digital_zero_uc_request();
				} else {
					// Skip service call for zero amount in other scenarios.
					$do_service_call = false;
				}
			}
		}
		if ( $do_service_call ) {
		$capture_request = null;
		try {
				$capture_request = new GenerateUnifiedCheckoutV1CaptureContextRequest( $request );
				if ( ! empty( $capture_request ) ) {
					$log_data = \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $capture_request );
					$this->gateway->add_logs_data( $log_data, true, $log_header );
					$response = $capture_context_api->generateUnifiedCheckoutV1CaptureContext( $capture_request );
					$response_body_raw = $response[VISA_ACCEPTANCE_VAL_ZERO];
					$settings = $this->gateway->get_config_settings();
					$enable_response_mle = isset( $settings['enable_response_mle'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_response_mle'] );
					if ( ! $enable_response_mle ) {
						$this->gateway->add_logs_service_response(
							$response_body_raw,
							$response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID],
							true,
							$log_header
						);
					}
					
					// Decrypt MLE response if needed.
					$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, $log_header );
					
					// Log decrypted response if it was encrypted.
					if ( $response_body !== $response_body_raw ) {
						$this->gateway->add_logs_service_response( 
							$response_body, 
							$response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], 
							true, 
							$log_header 
						);
					}
					$return_array = array(
					'http_code' => $response[VISA_ACCEPTANCE_VAL_ONE],
					'body'      => $response_body,
					);
				return $return_array;
				} 
			} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			} catch ( \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\MLEException $e ) {
				// Catch MLE-related errors (invalid credentials, certificate issues).
				$this->gateway->add_logs_data( 
					array( 'MLE Exception' => $e->getMessage() ), 
					false, 
					$log_header, 
					true 
				);
			} catch ( \Exception $e ) {
				$this->gateway->add_logs_data( 
					array( 'Exception' => $e->getMessage() ), 
					false, 
					$log_header, 
					true 
				);
			}	
		}
	}

	/**
	 * Fetches a TMS-token-scoped Capture Context for a single saved payment instrument.
	 *
	 * @param string $payment_instrument_id The CyberSource payment instrument ID.
	 * @param bool  $is_echeck Whether the payment instrument is an eCheck instrument.
	 * @return array { http_code: int, body: string|array }
	 */
	public function get_tms_token_capture_context( $payment_instrument_id, $is_echeck = false ) {
		$log_header              = 'TMS Token Capture Context';
		$key_generation_request  = new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$payment_adapter         = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		
		// Try JWT first, fallback to HTTP Signature if sessions endpoint doesn't support JWT.
		try {
            $api_client = $payment_adapter->get_api_client();
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( 'Request MLE Configuration Error' => $e->getMessage() ), false, $log_header, true );
            return $response;
        }
		$capture_context_api     = new UnifiedCheckoutV1CaptureContextApi( $api_client );
		$capture_request         = null;
		try {
			$request         = $key_generation_request->get_tms_token_uc_request( $payment_instrument_id, $is_echeck );
			$capture_request = new GenerateUnifiedCheckoutV1CaptureContextRequest( $request );
			$log_data        = \Pymt_Vas\Dependencies\CyberSource\ObjectSerializer::sanitizeForSerialization( $capture_request );
			$this->gateway->add_logs_data( $log_data, true, $log_header );
			$response = $capture_context_api->generateUnifiedCheckoutV1CaptureContext( $capture_request );
			$response_body_raw = $response[ VISA_ACCEPTANCE_VAL_ZERO ];
			// Only log raw response if response MLE is disabled.
			$settings = $this->gateway->get_config_settings();
			$enable_response_mle = isset( $settings['enable_response_mle'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_response_mle'] );
			if ( ! $enable_response_mle ) {
				$this->gateway->add_logs_service_response(
					$response_body_raw,
					$response[ VISA_ACCEPTANCE_VAL_TWO ][ VISA_ACCEPTANCE_V_C_CORRELATION_ID ] ?? VISA_ACCEPTANCE_STRING_EMPTY,
					true,
					$log_header
				);
			}
			
			// Decrypt MLE response if needed.
			$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, $log_header );
			
			// Log decrypted response if it was encrypted.
			if ( $response_body !== $response_body_raw ) {
				$this->gateway->add_logs_service_response( 
					$response_body, 
					$response[ VISA_ACCEPTANCE_VAL_TWO ][ VISA_ACCEPTANCE_V_C_CORRELATION_ID ] ?? VISA_ACCEPTANCE_STRING_EMPTY, 
					true, 
					$log_header 
				);
			}
			
			return array(
				'http_code' => $response[ VISA_ACCEPTANCE_VAL_ONE ],
				'body'      => $response_body,
			);
		} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
		} catch ( \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\MLEException $e ) {
			// Catch MLE-related errors (invalid credentials, certificate issues).
			$this->gateway->add_logs_data( 
				array( 'MLE Exception' => $e->getMessage() ), 
				false, 
				$log_header, 
				true 
			);
		} catch ( \Exception $e ) {
			// Catch any other exceptions.
			$this->gateway->add_logs_data( 
				array( 'Exception' => $e->getMessage() ), 
				false, 
				$log_header, 
				true 
			);
		}
	}
}
