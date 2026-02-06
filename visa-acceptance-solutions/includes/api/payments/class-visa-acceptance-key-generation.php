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

use CyberSource\Api\UnifiedCheckoutCaptureContextApi;
use CyberSource\Api\MicroformIntegrationApi;
use CyberSource\Model\GenerateUnifiedCheckoutCaptureContextRequest;
use CyberSource\Model\GenerateCaptureContextRequest;

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
	 * @param array $grouped_items Array of grouped product items with product IDs and quantities (optional).
	 * @return array
	 */
	public function get_unified_checkout_capture_context($is_ep = false, $product_id = null, $quantity = null, $grouped_items = array() ) {
		$response                         	= array();
		$log_header							= 'Credit Card'. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
		$do_service_call                  	= true;
		$key_generation_request           	= new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$payment_gateway_unified_checkout 	= new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscription_active              	= $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		$payment_adapter 					= new Visa_Acceptance_Payment_Adapter( $this->gateway );
        $api_client 						= $payment_adapter->get_api_client(true);
		$capture_context_api 				= new UnifiedCheckoutCaptureContextApi( $api_client );

		if ( is_add_payment_method_page() || ( $subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			  if ( $is_ep && ($subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment )) {
                $request = $key_generation_request->get_digital_zero_uc_request();
				$log_header = 'Express Pay'. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
			} else {
				$request = $key_generation_request->get_zero_uc_request();
			}
		} else {
			if ( ! $is_ep ) {
				$request 	= $key_generation_request->get_uc_request();
			}
			else {
				$request = $key_generation_request->get_digital_uc_request($product_id, $quantity, $grouped_items );
				$log_header = 'Express Pay'. VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT ;
			}
			if ( ( VISA_ACCEPTANCE_ZERO_AMOUNT === (string) $request['orderInformation']['amountDetails']['totalAmount'] ) && WC_Subscriptions_Cart::cart_contains_subscription() && ! is_product() ) {
				$request = $is_ep ? $key_generation_request->get_digital_zero_uc_request() : $key_generation_request->get_zero_uc_request();
			} elseif ( VISA_ACCEPTANCE_ZERO_AMOUNT >= (string) $request['orderInformation']['amountDetails']['totalAmount'] ) {
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
		try {
			$capture_request = new GenerateUnifiedCheckoutCaptureContextRequest( $request );
			if ( ! empty( $capture_request ) ) {
				$this->gateway->add_logs_data( $capture_request, true, $log_header );
				$response = $capture_context_api->generateUnifiedCheckoutCaptureContext( $capture_request );
				$this->gateway->add_logs_service_response( $response[0],$response[2][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, $log_header );
				$return_array = array(
					'http_code' => $response[1],
					'body'      => $response[0],
				);
				return $return_array;
			}
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}	
		}
	}

	/**
	 * Generate Flex microform capture context using CyberSource SDK
	 * @return array
	 */
	public function get_flex_microform_capture_context() {
		$response = array();
		$log_header = 'Flex Microform Capture Context';
		$settings = $this->gateway->get_config_settings();
		
		// Configure merchant settings.
		$payment_adapter 	= new Visa_Acceptance_Payment_Adapter( $this->gateway );
        $api_client 		= $payment_adapter->get_api_client(true);
		$microform_api = new MicroformIntegrationApi($api_client);

		try {
			// Get website URL for target origins.
			$site_url = get_site_url();
			$parsed_url = wp_parse_url($site_url);
			$target_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
			if (isset($parsed_url['port'])) {
				$target_origin .= ':' . $parsed_url['port'];
			}
			
			// Get allowed card networks from settings.
			$allowed_card_networks = array('VISA', 'MASTERCARD', 'AMEX'); // Default.
			if (!empty($settings['card_types']) && is_array($settings['card_types'])) {
				$allowed_card_networks = array_map('strtoupper', $settings['card_types']);
			}
			
			// Create request with Flex parameters.
			$request_data = array(
				'clientVersion' => 'v2',
				'targetOrigins' => array($target_origin),
				'allowedCardNetworks' => $allowed_card_networks,
				'allowedPaymentTypes' => array('CARD')
			);
			
			$capture_request = new GenerateCaptureContextRequest($request_data);
			
			if (!empty($capture_request)) {
				$this->gateway->add_logs_data($capture_request, true, $log_header);
				
				// Make the API call.
				$response = $microform_api->generateCaptureContext($capture_request);
				
				// Extract correlation ID safely from headers.
				$correlation_id = 'N/A';
				if (isset($response[2]) && is_array($response[2]) && isset($response[2][VISA_ACCEPTANCE_V_C_CORRELATION_ID])) {
					$correlation_id = $response[2][VISA_ACCEPTANCE_V_C_CORRELATION_ID];
					if (is_array($correlation_id)) {
						$correlation_id = $correlation_id[0] ?? 'N/A';
					}
				}
				
				$this->gateway->add_logs_service_response($response[0], $correlation_id, true, $log_header
				);
				
				$return_array = array(
					'http_code' => $response[1],
					'body' => $response[0]
				);
				
				return $return_array;
			}
		} catch (\CyberSource\ApiException $e) {
			$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
		}
	}
}
