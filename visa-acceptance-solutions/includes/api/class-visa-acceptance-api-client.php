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

require __DIR__ . '/../../vendor/autoload.php';
use CyberSource\Authentication\Util\MLEUtility;
use CyberSource\Authentication\Core\MerchantConfiguration;

/**
 *
 * Visa Acceptance Api Client request Class
 * Handles Api Client requests
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Api_Client {

	/**
	 * Gateway Object
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * ApiClient constructor.
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Helps trigger endpoints.
	 *
	 * @param array   $payload payload.
	 * @param string  $endpoint endpoint.
	 * @param boolean $service_header service header.
	 * @param string  $service service.
	 * @param array   $settings settings.
	 *
	 * @return array
	 */
	public function processor( $payload, $endpoint, $service_header, $service, $settings ) {
		$flex_url = VISA_ACCEPTANCE_FLEX_TEST_DOMAIN;
		if ( isset( $settings['environment'] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings['environment'] ) {
			$flex_url = VISA_ACCEPTANCE_FLEX_PROD_DOMAIN;
		}
		$request_host    = $this->remove_http( $flex_url );
		$method          = VISA_ACCEPTANCE_REQUEST_METHOD_GET;
		$url             = $flex_url . $endpoint;
		$resource_encode = mb_convert_encoding( $endpoint, VISA_ACCEPTANCE_UTF_8, VISA_ACCEPTANCE_ISO );
		$date            = $this->get_date();
		$auth_headers    = $this->get_http_signature_get( $resource_encode, strtolower( $method ), $date, $request_host, $settings );
		$response        = wp_safe_remote_request(
			$url,
			array(
				'method'      => ( $method ),
				'headers'     => $auth_headers,
				'body'        => ( $payload ),
				'redirection' => VISA_ACCEPTANCE_VAL_ZERO,
				'timeout'     => VISA_ACCEPTANCE_VAL_SIX_ZERO,
			)
		);
		$response_array  = $this->get_response_array( $response );
		return $response_array;
	}

	/**
	 * Marks order status as failed if any error occured.
	 *
	 * @param string $message error message.
	 *
	 * @return void
	 */
	public function mark_order_failed( $message ) {
		wc_add_notice( $message, VISA_ACCEPTANCE_STRING_ERROR );
	}

	/**
	 * Helps trigger service endpoint.
	 *
	 * @param array   $payload payload.
	 * @param string  $resource_data resource.
	 * @param boolean $service_header service header.
	 * @param string  $service service.
	 * @param array   $settings settings.
	 * @param string  $log_header log header.
	 *
	 * @return array
	 */
	public function service_processor( $payload, $resource_data, $service_header, $service, $settings, $log_header ) {
		$flex_url = VISA_ACCEPTANCE_FLEX_TEST_DOMAIN;
		$api_url  = VISA_ACCEPTANCE_API_TEST_DOMAIN;
		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( $payload, true, $log_header );
		}
		$date          = $this->get_date();
		$header_params = array();
		if ( isset( $settings['environment'] ) && VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION === $settings['environment'] ) {
			$flex_url = VISA_ACCEPTANCE_FLEX_PROD_DOMAIN;
			$api_url  = VISA_ACCEPTANCE_API_PROD_DOMAIN;
		}
		$host_url        = isset( $service ) && ( VISA_ACCEPTANCE_VAL_ZERO === strcmp( $service, VISA_ACCEPTANCE_FLEXFORM ) ) ? $flex_url : $api_url;
		$request_host    = $this->remove_http( $host_url );
		$url             = $host_url . $resource_data;
		$resource_encode = mb_convert_encoding( $resource_data, VISA_ACCEPTANCE_UTF_8, VISA_ACCEPTANCE_ISO );
		if ( VISA_ACCEPTANCE_UPDATECARD === $service ) {
			$method = VISA_ACCEPTANCE_REQUEST_METHOD_PATCH;
		} elseif ( VISA_ACCEPTANCE_REQUEST_METHOD_DELETE === $service ) {
			$method = VISA_ACCEPTANCE_REQUEST_METHOD_DELETE;
		} elseif ( VISA_ACCEPTANCE_GET_TRANSACTION === $service ) {
			$method = VISA_ACCEPTANCE_REQUEST_METHOD_GET;
		} else {
			$method = VISA_ACCEPTANCE_REQUEST_METHOD_POST;
		}
		if ( ! $service_header ) {
			$header_params['Content-Type'] = VISA_ACCEPTANCE_REQUEST_HEADER_PARAM_CONTENT_TYPE_UTF;
		} elseif ( VISA_ACCEPTANCE_FLEXFORM === $service ) {
				$header_params['Accept']       = VISA_ACCEPTANCE_REQUEST_HEADER_PARAM;
				$header_params['Content-Type'] = VISA_ACCEPTANCE_REQUEST_HEADER_PARAM;
		} else {
			$header_params['Accept']       = VISA_ACCEPTANCE_REQUEST_HEADER_PARAM_ACCEPT_UTF;
			$header_params['Content-Type'] = VISA_ACCEPTANCE_REQUEST_HEADER_PARAM_CONTENT_TYPE_UTF;
		}

		if ( VISA_ACCEPTANCE_REQUEST_METHOD_DELETE === $service || VISA_ACCEPTANCE_GET_TRANSACTION === $service ) {
			$auth_headers = $this->get_http_signature_get( $resource_encode, strtolower( $method ), $date, $request_host, $settings );
		} else {
			$auth_headers = $this->get_http_signature( $payload, $resource_encode, strtolower( $method ), $date, $request_host, $settings );
		}
		$header_params = array_merge( $header_params, $auth_headers );
		$response      = wp_safe_remote_request(
			$url,
			array(
				'method'      => strtoupper( $method ),
				'headers'     => $header_params,
				'body'        => ( $payload ),
				'redirection' => VISA_ACCEPTANCE_VAL_ZERO,
				'timeout'     => VISA_ACCEPTANCE_VAL_SIX_ZERO,
			)
		);
		if ( $log_header && ( is_wp_error( $response ) || ( ! is_wp_error( $response ) && ! empty( $response['body'] ) ) ) ) {
			$this->gateway->add_logs_data( $response, false, $log_header );
		}
		$response = $this->get_response_array( $response );
		return $response;
	}

	/**
	 * Converts service_processor reponse into array.
	 *
	 * @param string $response response.
	 *
	 * @return array
	 */
	public function get_response_array( $response ) {
		$response_array = array(
			'header'    => null,
			'body'      => null,
			'http_code' => null,
		);
		if ( ! is_wp_error( $response ) ) {
			$response_array['header']    = wp_remote_retrieve_headers( $response )->getAll();
			$response_array['body']      = wp_remote_retrieve_body( $response );
			$response_array['http_code'] = wp_remote_retrieve_response_code( $response );
		}
		return $response_array;
	}

	/**
	 * Removes protocol in the URL.
	 *
	 * @param string $url url.
	 *
	 * @return string
	 */
	public function remove_http( $url ) {
		$disallowed = array( 'http://', 'https://' );
		foreach ( $disallowed as $disallow ) {
			if ( 0 === strpos( $url, $disallow ) ) {
				$url = str_replace( $disallow, VISA_ACCEPTANCE_STRING_EMPTY, $url );
				break;
			}
		}
		return $url;
	}

	/**
	 * Returns the current date.
	 *
	 * @return string
	 */
	public function get_date() {
		return gmdate( VISA_ACCEPTANCE_DATE_TIME ) . VISA_ACCEPTANCE_GMT;
	}

	/**
	 * Encodes payload data.
	 *
	 * @param string $request_payload payload data.
	 *
	 * @return string
	 */
	public function generate_digest( $request_payload ) {
		$utf8_encoded_string = mb_convert_encoding( $request_payload, VISA_ACCEPTANCE_UTF_8, VISA_ACCEPTANCE_ISO );
		$digest_encode       = hash( VISA_ACCEPTANCE_ALGORITHM_SHA256, $utf8_encoded_string, true );
		return $this->get_base64_encode( $digest_encode );
	}

	/**
	 * Encodes data with MIME base64.
	 *
	 * @param string $input_string  string .
	 *
	 * @return string
	 */
	private function get_base64_encode( $input_string ) {
		return base64_encode( $input_string ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decodes data encoded with MIME base64.
	 *
	 * @param string $input_string  string .
	 *
	 * @return string
	 */
	private function get_base64_decode( $input_string ) {
		return base64_decode( $input_string ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Prepares request data header information.
	 *
	 * @param array  $payload payload.
	 * @param string $resource_data response.
	 * @param string $http_method http method.
	 * @param string $current_date current date.
	 * @param string $request_host request host.
	 * @param array  $settings settings.
	 *
	 * @return array
	 */
	public function get_http_signature( $payload, $resource_data, $http_method, $current_date, $request_host, $settings ) {
		$general_settings      = $this->get_environment_settings( $settings );
		$digest                = $this->generate_digest( $payload );
		$signature_string      = 'host: ' . $request_host . "\ndate: " .
		$current_date . "\nrequest-target: " .
		$http_method . VISA_ACCEPTANCE_SPACE . $resource_data . "\ndigest: SHA-256=" .
		$digest . "\nv-c-merchant-id: " . $general_settings['merchant_id'];
		$header_string         = 'host date request-target digest v-c-merchant-id';
		$signature_byte_string = mb_convert_encoding( $signature_string, VISA_ACCEPTANCE_UTF_8, VISA_ACCEPTANCE_ISO );
		$decode_key            = $this->get_base64_decode( $general_settings['merchant_secret_key'] );
		$signature             = $this->get_base64_encode(
			hash_hmac(
				VISA_ACCEPTANCE_ALGORITHM_SHA256,
				$signature_byte_string,
				$decode_key,
				true
			)
		);
		$signature_header      = array(
			'keyid="' . $general_settings['merchant_key_id'] .
							'"',
			'algorithm="HmacSHA256"',
			'headers="' .
							$header_string . '"',
			'signature="' . $signature . '"',
		);
		$signature_token       = array( 'Signature' => implode( ', ', $signature_header ) );
		$host                  = array( 'Host' => $request_host );
		$vc_merchant_id        = array( 'v-c-merchant-id' => $general_settings['merchant_id'] );
		$digest_array          = array( 'Digest' => 'SHA-256=' . $digest );
		$headers               = array_merge( $vc_merchant_id, $signature_token, $host, array( 'Date' => $current_date ), $digest_array );
		return $headers;
	}

	/**
	 * Prepares request data header information for get signature.
	 *
	 * @param string $resource_data response.
	 * @param string $http_method http method.
	 * @param date   $current_date current date.
	 * @param string $request_host request host.
	 * @param array  $settings settings.
	 *
	 * @return array
	 */
	public function get_http_signature_get( $resource_data, $http_method, $current_date, $request_host, $settings ) {
		$general_settings      = $this->get_environment_settings( $settings );
		$signature_string      = 'host: ' . $request_host . "\ndate: " .
		$current_date . "\nrequest-target: " . $http_method . VISA_ACCEPTANCE_SPACE .
		$resource_data . "\nv-c-merchant-id: " . $general_settings['merchant_id'];
		$header_string         = 'host date request-target v-c-merchant-id';
		$signature_byte_string = mb_convert_encoding( $signature_string, VISA_ACCEPTANCE_UTF_8, VISA_ACCEPTANCE_ISO );
		$decode_key            = $this->get_base64_decode( $general_settings['merchant_secret_key'] );
		$signature             = $this->get_base64_encode(
			hash_hmac(
				VISA_ACCEPTANCE_ALGORITHM_SHA256,
				$signature_byte_string,
				$decode_key,
				true
			)
		);
		$signature_header      = array(
			'keyid="' . $general_settings['merchant_key_id'] . '"',
			'algorithm="HmacSHA256"',
			'headers="' . $header_string . '"',
			'signature="' . $signature . '"',
		);
		$signature_token       = array( 'Signature' => implode( ', ', $signature_header ) );
		$host                  = array( 'Host' => $request_host );
		$vc_merchant_id        = array( 'v-c-merchant-id' => $general_settings['merchant_id'] );
		$headers               = array_merge(
			$vc_merchant_id,
			$signature_token,
			$host,
			array( 'Date' => $current_date ),
		);
		return $headers;
	}

	/**
	 * Gets the merchant details based on environment.
	 *
	 * @param array $settings settings.
	 *
	 * @return array
	 */
	private function get_environment_settings( $settings ) {
		$general_settings = array();
		if ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === ( ! empty( $settings['environment'] ) ? $settings['environment'] : VISA_ACCEPTANCE_STRING_EMPTY ) ) {
			$general_settings['merchant_id']         = ( ! empty( $settings['test_merchant_id'] ) ? $settings['test_merchant_id'] : VISA_ACCEPTANCE_STRING_EMPTY );
			$general_settings['merchant_key_id']     = ( ! empty( $settings['test_api_key'] ) ? $settings['test_api_key'] : VISA_ACCEPTANCE_STRING_EMPTY );
			$general_settings['merchant_secret_key'] = ( ! empty( $settings['test_api_shared_secret'] ) ? $settings['test_api_shared_secret'] : VISA_ACCEPTANCE_STRING_EMPTY );
		} else {
			$general_settings['merchant_id']         = ( ! empty( $settings['merchant_id'] ) ? $settings['merchant_id'] : VISA_ACCEPTANCE_STRING_EMPTY );
			$general_settings['merchant_key_id']     = ( ! empty( $settings['api_key'] ) ? $settings['api_key'] : VISA_ACCEPTANCE_STRING_EMPTY );
			$general_settings['merchant_secret_key'] = ( ! empty( $settings['api_shared_secret'] ) ? $settings['api_shared_secret'] : VISA_ACCEPTANCE_STRING_EMPTY );
		}
		return $general_settings;
	}
}
