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
require_once __DIR__ . '/../request/payments/class-visa-acceptance-key-generation-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

use CyberSource\Api\UnifiedCheckoutCaptureContextApi;
use CyberSource\Model\GenerateUnifiedCheckoutCaptureContextRequest;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;
use CyberSource\Logging\LogConfiguration;
use CyberSource\ApiClient as CyberSourceClient;

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
	 * @return array
	 */
	public function get_unified_checkout_capture_context() {
		$response                         	= array();
		$log_header                       	= VISA_ACCEPTANCE_UC_CAPTURE_CONTEXT;
		$do_service_call                  	= true;
		$key_generation_request           	= new Visa_Acceptance_Key_Generation_Request( $this->gateway );
		$payment_gateway_unified_checkout 	= new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$merchant_configuration           	= new MerchantConfiguration();
		$configuration 						= new Configuration();
		$subscription_active              	= $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		$settings                         	= $this->gateway->get_config_settings();
		$merchant_configuration->setAuthenticationType( 'HTTP_SIGNATURE' );
		if ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === $settings['environment'] ) {
			$merchant_configuration->setRunEnvironment( VISA_ACCEPTANCE_REQUEST_HOST_APITEST );
			$configuration->setHost( VISA_ACCEPTANCE_REQUEST_HOST_APITEST );
			$merchant_configuration->setMerchantID( $settings['test_merchant_id'] );
			$merchant_configuration->setApiKeyID( $settings['test_api_key'] );
			$merchant_configuration->setSecretKey( $settings['test_api_shared_secret'] );
		} else {
			$merchant_configuration->setRunEnvironment( VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION );
			$configuration->setHost( VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION );
			$merchant_configuration->setMerchantID( $settings['merchant_id'] );
			$merchant_configuration->setApiKeyID( $settings['api_key'] );
			$merchant_configuration->setSecretKey( $settings['api_shared_secret'] );
		}

		if ( isset($settings['enable_mle']) && (VISA_ACCEPTANCE_YES === $settings['enable_mle']) || (true === $settings['enable_mle'] )) {
			$merchant_configuration->setUseMLEGlobally( true );
			$certificate_path = str_replace( '\\', '/', $settings['mle_certificate_path']);
			$merchant_configuration->setKeysDirectory( $certificate_path );
			$merchant_configuration->setKeyFileName( $settings['mle_filename'] );
			$merchant_configuration->setKeyPassword( $settings['mle_key_password'] );
		} else {
			$merchant_configuration->setUseMLEGlobally( false );
		}

		$merchant_configuration->setDefaultDeveloperId( VISA_ACCEPTANCE_DEVELOPER_ID );
		$merchant_configuration->setSolutionId( VISA_ACCEPTANCE_SOLUTION_ID );
		$merchant_configuration->setLogConfiguration( new LogConfiguration() );
		$api_client          = new CyberSourceClient( $configuration, $merchant_configuration );
		$capture_context_api = new UnifiedCheckoutCaptureContextApi( $api_client );

		if ( is_add_payment_method_page() || ( $subscription_active && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			$request = $key_generation_request->get_zero_uc_request();
		} else {
			$request = $key_generation_request->get_uc_request();
			if ( ( VISA_ACCEPTANCE_ZERO_AMOUNT === (string) $request['orderInformation']['amountDetails']['totalAmount'] ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
				$request = $key_generation_request->get_zero_uc_request();
			} elseif ( VISA_ACCEPTANCE_ZERO_AMOUNT >= (string) $request['orderInformation']['amountDetails']['totalAmount'] ) {
				$do_service_call = false;
			}
		}
		if ( $do_service_call ) {
		try {
			$capture_request = new GenerateUnifiedCheckoutCaptureContextRequest( $request );
			if ( ! empty( $capture_request ) ) {
				$this->gateway->add_logs_data( $capture_request, true, $log_header );
				$response = $capture_context_api->generateUnifiedCheckoutCaptureContext( $capture_request );
				$this->gateway->add_logs_service_response( $response[0],$response[2]['v-c-correlation-id'], true, $log_header );
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
}
