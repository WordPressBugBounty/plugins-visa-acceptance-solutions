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
 * @subpackage Visa_Acceptance_Solutions/public
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../../payments/class-visa-acceptance-payment-methods.php';
require_once __DIR__ . '/../../../class-visa-acceptance-payment-gateway-subscriptions.php';

use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;
use CyberSource\Logging\LogConfiguration;
use CyberSource\Authentication\Util\MLEUtility;
use CyberSource\ApiClient as CyberSourceClient;

/**
 * Visa Acceptance Payment Adapter Class
 *
 * Handles creation of payment request values
 */
class Visa_Acceptance_Payment_Adapter extends Visa_Acceptance_Request {

	/**
	 *
	 * Fetches jti from transient token.
	 *
	 * @param string $transient_token transient token.
	 * @return any
	 */
	public function get_jti_from_transient_token( $transient_token ) {
		$transient_token_component    = explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token )['1'];
		$transient_token_json         = base64_decode( $transient_token_component ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$transient_token_json_decoded = json_decode( $transient_token_json );
		$jti                          = isset( $transient_token_json_decoded->jti ) ? $transient_token_json_decoded->jti : null;
		return $jti;
	}

	/**
	 *
	 * Configure Merchant Configuration for payment methods.
	 *
	 * @return string $merchant_configuration.
	 */
	public function get_merchant_configuration() {
		$unified_checkout		= new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$settings               = $unified_checkout->get_config_settings();
		$merchant_configuration = new MerchantConfiguration();
		$configuration 			= new Configuration();
		$merchant_configuration->setAuthenticationType( 'HTTP_SIGNATURE' );
		if ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === $settings['environment'] ) {
			$merchant_configuration->setRunEnvironment( VISA_ACCEPTANCE_REQUEST_HOST_APITEST );
			$configuration->setHost( VISA_ACCEPTANCE_REQUEST_HOST_APITEST );
			$merchant_configuration->setMerchantID( $settings['test_merchant_id'] );
			$merchant_configuration->setApiKeyID( $settings['test_api_key'] );
			$merchant_configuration->setSecretKey( $settings['test_api_shared_secret'] );
		} else {
			$merchant_configuration->setRunEnvironment( VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION );
			$configuration->setHost(VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION);
			$merchant_configuration->setMerchantID( $settings['merchant_id'] );
			$merchant_configuration->setApiKeyID( $settings['api_key'] );
			$merchant_configuration->setSecretKey( $settings['api_shared_secret'] );
		}
		if ( isset($settings['enable_mle']) && (VISA_ACCEPTANCE_YES === $settings['enable_mle']) || (true === $settings['enable_mle'] )) {
			$merchant_configuration->setUseMLEGlobally( true );
			$merchant_configuration->setAuthenticationType( 'JWT' );
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
		return [$configuration, $merchant_configuration];
	}

	/**
	 *
	 * Api Client function.
	 *
	 * @return string $api_client
	 */
	public function get_api_client() {
		$merchant_config = $this->get_merchant_configuration();
		$api_client      = new CyberSourceClient($merchant_config[0], $merchant_config[1]);
		return $api_client;
	}

	/**
	 * Mask value.
	 *
	 * @param string $value value.
	 *
	 * @return string $masked
	 */
	public function mask_value( $value ) {
		if ( ! empty( $value ) ) {
			// Mask all characters including special characters like '@', '.' and 'com'.
			$masked = preg_replace_callback(
				'/[A-Za-z0-9.@com]/',
				function ( $matches ) {
					return 'x';
				},
				$value
			);
			return $masked;
		}
	}

	/**
	 * Decrypt the cvv.
	 *
	 * @param string $encrypted_data Encrypted Data.
	 * @param string $ext_id IV.
	 * @param string $val_id Key.
	 * @param string $ref_id Tag.
	 *
	 * @return string
	 */
	public function decrypt_cvv( $encrypted_data, $ext_id, $val_id, $ref_id ) {
		$encrypted_data = base64_decode( $encrypted_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ext_id         = base64_decode( $ext_id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ref_id         = base64_decode( $ref_id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$hashed_key     = substr( hash( 'sha256', $val_id, true ), 0, 32 ); // phpcs:ignore WordPress.Security.NonceVerification
		return openssl_decrypt( $encrypted_data, 'aes-256-gcm', $hashed_key, OPENSSL_RAW_DATA, $ext_id, $ref_id ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Gets billing information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_billing_information( $order ) {
		$order_billing           = $order->get_data();
			$bill_to_information = array(
				'firstName'          => $order_billing['billing']['first_name'],
				'lastName'           => $order_billing['billing']['last_name'],
				'address1'           => $order_billing['billing']['address_1'],
				'address2'           => $order_billing['billing']['address_2'],
				'postalCode'         => $order_billing['billing']['postcode'],
				'locality'           => $order_billing['billing']['city'],
				'administrativeArea' => $order_billing['billing']['state'],
				'country'            => $order_billing['billing']['country'],
				'phoneNumber'        => $order_billing['billing']['phone'],
				'email'              => $order_billing['billing']['email'],
			);
			return $bill_to_information;
	}

	/**
	 * Gets shipping information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_shipping_information( $order ) {
		$order_shipping      = $order->get_data();
		$ship_to_information = array(
			'firstName'          => $order_shipping['shipping']['first_name'],
			'lastName'           => $order_shipping['shipping']['last_name'],
			'address1'           => $order_shipping['shipping']['address_1'],
			'address2'           => $order_shipping['shipping']['address_2'],
			'postalCode'         => $order_shipping['shipping']['postcode'],
			'locality'           => $order_shipping['shipping']['city'],
			'administrativeArea' => $order_shipping['shipping']['state'],
			'country'            => $order_shipping['shipping']['country'],
			'phoneNumber'        => $order_shipping['shipping']['phone'],
			'email'              => $order_shipping['billing']['email'],
		);
		return $ship_to_information;
	}

	/**
	 * Get cybersource payment information
	 *
	 * @param array  $token_data saved card token data.
	 * @param string $saved_card_cvv saved card cvv.
	 * @return array
	 */
	public function get_cybersource_payment_information( $token_data, $saved_card_cvv ) {
		$payment_information = new \CyberSource\Model\Ptsv2paymentsPaymentInformation(
			array(
				'paymentInstrument' => array(
					'id' => $token_data['token_information']['payment_instrument_id'],
				),
				'customer'          => array(
					'id' => $token_data['token_information']['id'],
				),
				'card'              => array(
					'securityCode'           => $saved_card_cvv,
					'typeSelectionIndicator' => VISA_ACCEPTANCE_VAL_ONE,
				),
			)
		);
		return $payment_information;
	}

	/**
	 * Get cybersource token information
	 *
	 * @param string $trans_token transient token JWT used to retrieve token information.
	 * @return array
	 */
	public function get_cybersource_token_information( $trans_token ) {
		$token_information = new \CyberSource\Model\Ptsv2paymentsTokenInformation(
			array(
				'transientTokenJwt' => $trans_token,
			)
		);
		return $token_information;
	}

	/**
	 * Client Reference Information Partner
	 *
	 * @return array
	 */
	public function client_reference_information_partner() {
		$client_reference_information_partner = new \CyberSource\Model\Ptsv2paymentsClientReferenceInformationPartner(
			array(
				'developerId' => VISA_ACCEPTANCE_DEVELOPER_ID,
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			)
		);
		return $client_reference_information_partner;
	}

	/**
	 * Client Reference Information
	 *
	 * @param object $order order.
	 * @return array
	 */
	public function client_reference_information( $order ) {
		$client_reference_information = new \CyberSource\Model\Ptsv2paymentsClientReferenceInformation(
			array(
				'code'               => $order->get_id(),
				'partner'            => $this->client_reference_information_partner(),
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		return $client_reference_information;
	}

	/**
	 * Client Reference Information
	 *
	 * @param object $order order.
	 * @return array
	 */
	public function get_payment_buyer_information( $order ) {
		$buyer_information = new \CyberSource\Model\Ptsv2paymentsBuyerInformation(
			array(
				'merchantCustomerId' => $order->get_user_id() ? strval( $order->get_user_id() ) : 'wc-vas-guest-' . $order->get_id(),
			)
		);
		return $buyer_information;
	}

	/**
	 * Order Information
	 *
	 * @param object $order order.
	 * @return array
	 */
	public function get_payment_order_information( $order ) {
		$order_information = new \CyberSource\Model\Ptsv2paymentsOrderInformation(
			array(
				'amountDetails' => $this->order_information_amount_details( $order ),
				'billTo'        => $this->get_cybersource_billing_information( $order ),
				'shipTo'        => $this->get_cybersource_shipping_information( $order ),
				'lineItems'     => $this->get_line_items_information( $order ),
			)
		);
		return $order_information;
	}

	/**
	 * Order Information Amount Details.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function order_information_amount_details( $order ) {
		$order_information_amount_details = new \CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
			array(
				'totalAmount' => (string) $order->get_total(),
				'currency'    => $order->get_currency(),
			)
		);
		return $order_information_amount_details;
	}

	/**
	 * Gets payment solution.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_payment_solution( $order ) {
		$payment_solution                            = $this->get_order_meta( $order, 'payment_solution' );
		if ( $this->gateway->is_subscriptions_activated && wcs_order_contains_renewal( $order ) ) {
            $processing_information['commerceIndicator'] = VISA_ACCEPTANCE_RECURRING;
        }
        else {
            $processing_information['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
        }
		if ( ! empty( $payment_solution ) ) {
			$processing_information['paymentSolution'] = $payment_solution;
		}
		return $processing_information;
	}

	/**
	 * Gets processing information.
	 *
	 * @param mixed $order              Order.
	 * @param mixed $gateway_settings   Gateway settings.
	 * @param mixed $is_save_card       Is saved card.
	 * @param mixed $service            Service.
	 * @param mixed $is_stored_card     is stored card.
	 * @param mixed $merchant_initiated merchant initiated transaction.
	 * @return array
	 */
	public function get_processing_info( $order, $gateway_settings, $is_save_card, $service = null, $is_stored_card = false, $merchant_initiated = false ) {
		$subscriptions = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$processing_information = array(
			'commerceIndicator' => VISA_ACCEPTANCE_INTERNET,
			'capture'           => $this->get_capture( $gateway_settings, $order ),
			'actionList'        => $this->get_action_list( $gateway_settings, $is_save_card, $service ),
		);
		if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
			$processing_information['authorizationOptions'] = array(
				'initiator' => array(
					'credentialStoredOnFile' => true,
					'type'                   => 'customer',
				),
			);
		}
		if ( $is_stored_card ) {
			$processing_information['authorizationOptions'] = array(
				'initiator' => array(
					'storedCredentialUsed' => true,
					'type'                 => 'customer',
				),
			);
		}
		if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) ) ) {
			if ( $is_stored_card ) {
				$processing_information = $subscriptions->saved_token_subscriptions_payload( $order, $processing_information, $merchant_initiated );
			} elseif ( VISA_ACCEPTANCE_YES === $is_save_card ) {
				$processing_information = $subscriptions->customer_subscription_payload( $order, $processing_information );
			}
		}
		return $processing_information;
	}

	/**
	 * Gets customer ID.
	 *
	 * @return mixed
	 */
	public function get_customer_id() {
		$customer_data = $this->get_order_for_add_payment_method();
		$core_tokens   = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
		$customer_id   = null;
		if ( is_array( $core_tokens ) && ! empty( $core_tokens ) ) {
			$payment_method = new Visa_Acceptance_Payment_Methods( $this );
			$token_data     = $payment_method->build_token_data( $core_tokens[ array_key_first( $core_tokens ) ] );
			if ( ! empty( $token_data['token_information']['id'] ) ) {
				$customer_id = $token_data['token_information']['id'];
			}
		}
		return $customer_id;
	}

	/**
	 * Get action token type
	 *
	 * @param mixed $action_token_type_payload  Action token type.
	 * @return mixed
	 */
	public function get_action_token_type( $action_token_type_payload ) {
		$customer_id = $this->get_customer_id();
		if ( ! empty( $customer_id ) ) {
			$action_token_type_payload['paymentInformation']['customer']['id']      = $customer_id;
			$action_token_type_payload['processingInformation']['actionTokenTypes'] = array( 'paymentInstrument', 'instrumentIdentifier' );
			$action_token_type_payload['processingInformation']['actionTokenTypes'] = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
		} else {
			$action_token_type_payload['processingInformation']['actionTokenTypes'] = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
		}
		return $action_token_type_payload;
	}

	/**
	 * Gets action list.
	 *
	 * @param mixed $gateway_settings   Gateway setting.
	 * @param mixed $is_save_card       Is saved card.
	 * @param mixed $service            Service.
	 * @return array
	 */
	public function get_action_list( $gateway_settings, $is_save_card, $service ) {
		$action_list_dm    = array();
		$action_list_token = array();
		$action_list_3ds   = array();
		if ( isset( $gateway_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] ) && VISA_ACCEPTANCE_NO === $gateway_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] ) {
			$action_list_dm = array( VISA_ACCEPTANCE_DECISION_SKIP );
		}

		if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
			$action_list_token = array( VISA_ACCEPTANCE_TOKEN_CREATE );
		}
		if ( ! empty( $service ) ) {
			if ( 'enroll' === $service ) {
				$action_list_3ds = array( VISA_ACCEPTANCE_CONSUMER_AUTHENTICATION );
			} else {
				$action_list_3ds = array( VISA_ACCEPTANCE_VALIDATE_CONSUMER_AUTHENTICATION );
			}
		}
		$action_list = array_merge( $action_list_dm, $action_list_token, $action_list_3ds );

		return $action_list;
	}

	/**
	 * Gets capture value to be passed in request.
	 *
	 * @param mixed $gateway_settings   Gateway settings.
	 * @param mixed $order Order.
	 *
	 * @return boolean $capture
	 */
	public function get_capture( $gateway_settings, $order ) {
		$capture = false;
		if ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $gateway_settings['transaction_type'] || $this->check_virtual_order_enabled( $gateway_settings, $order ) ) {
			if ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() && wcs_order_contains_subscription( $order ) ) {
				$capture = false;
			} else {
				$capture = true;
			}
		}
		return $capture;
	}

	/**
	 * Generates the device information for the request.
	 *
	 * @param mixed  $merchant_initiated merchant initiated transaction.
	 */
	public function get_device_information( $merchant_initiated = false ) {
		$settings = $this->gateway->get_config_settings();
		if ( isset( $settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] ) && VISA_ACCEPTANCE_YES === $settings[ VISA_ACCEPTANCE_SETTING_ENABLE_DECISION_MANAGER ] && ! $merchant_initiated ) {
			$gateway_id = $this->gateway->get_id();
			$session_id = isset( WC()->session ) ? WC()->session->get( "wc_{$gateway_id}_device_data_session_id", VISA_ACCEPTANCE_STRING_EMPTY ) : null;
		}
		$session_id         = ! empty( $session_id ) ? $session_id : VISA_ACCEPTANCE_STRING_EMPTY;
		$device_information = new \CyberSource\Model\Ptsv2paymentsDeviceInformation(
			array(
				'fingerprintSessionId' => $session_id,
			)
		);
		return $device_information;
	}

	/**
	 * Checks whether Auth Reversal Exists or not.
	 *
	 * @param \WC_Order $order order data.
	 * @param array     $payment_response_array Payment Response array.
	 *
	 * @return boolean
	 */
	public function auth_reversal_exists( $order, $payment_response_array ) {
		$auth_reversal_exist = false;
		$endpoint            = VISA_ACCEPTANCE_TRANSACTION_DETAILS_RESOURCE . $payment_response_array['transaction_id'];

		$settings              = $this->gateway->get_config_settings();
		$log_header            = ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] ) ? ucfirst( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE ) : VISA_ACCEPTANCE_AUTHORIZATION;
		$api                   = new Visa_Acceptance_Api_Client( $this->gateway );
		$payment_response      = $api->service_processor( VISA_ACCEPTANCE_STRING_EMPTY, $endpoint, true, VISA_ACCEPTANCE_GET_TRANSACTION, $settings, $log_header );
		$decoded_test_response = json_decode( $payment_response['body'], true );

		if ( ! empty( $decoded_test_response['_links']['relatedTransactions'] ) ) {
			$related_transactions = $decoded_test_response['_links']['relatedTransactions'];
			foreach ( $related_transactions as $related_transaction ) {
				$href_url       = $related_transaction['href'];
				$href_url_split = explode( VISA_ACCEPTANCE_SLASH, $href_url );
				$transaction_id = end( $href_url_split );
				$resources      = VISA_ACCEPTANCE_TRANSACTION_DETAILS_RESOURCE . $transaction_id;
				$res            = $api->service_processor( VISA_ACCEPTANCE_STRING_EMPTY, $resources, true, VISA_ACCEPTANCE_GET_TRANSACTION, $settings, $log_header );
				$res_dec        = json_decode( $res['body'] );
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$applications_array = isset( $res_dec->applicationInformation->applications ) ? $res_dec->applicationInformation->applications : null;
				foreach ( $applications_array as $application ) {
					//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$application_name = isset( $application->name ) ? $application->name : null;
					//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$application_code = isset( $application->rCode ) ? $application->rCode : null;
					//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$application_flag = isset( $application->rFlag ) ? $application->rFlag : null;
					if ( VISA_ACCEPTANCE_ISC_AUTH_REVERSAL === $application_name && 1 === (int) $application_code && 'SOK' === $application_flag ) {
						$auth_reversal_exist = true;
					}
				}
			}
		}
		return $auth_reversal_exist;
	}

	/**
	 * Performs AuthReversal.
	 *
	 * @param \WC_Order $order order data.
	 * @param array     $payment_response_array Payment Response array.
	 */
	public function do_auth_reversal( $order, $payment_response_array ) {
		$reason                 = VISA_ACCEPTANCE_AUTO_AUTH_REVERSAL;
		$transaction_id         = $payment_response_array['transaction_id'];
		$auth_reversal          = new Visa_Acceptance_Auth_Reversal( $this->gateway );
		$auth_reversal_response = $auth_reversal->get_reversal_response( $order, $order->get_total(), $reason, $transaction_id );
		$decoded                = json_decode( $auth_reversal_response['body'] );
		$message                = sprintf(
			$this->gateway->get_title() . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_VOID_APPROVED,
			$decoded->id
		);
		$order->add_order_note( $message );
	}

	/**
	 * Checks whether Virtual order is enabled or not.
	 *
	 * @param array     $gateway_settings Gateway Settings.
	 * @param \WC_Order $order order data.
	 *
	 * @return boolean
	 */
	public function check_virtual_order_enabled( $gateway_settings, $order ) {
		$is_virtual = false;
		if ( VISA_ACCEPTANCE_YES === $gateway_settings['charge_virtual_orders'] ) {
			$is_virtual = true;
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				// once one non-virtual product found, break out of the loop.
				if ( $product && ! $product->is_virtual() ) {
					$is_virtual = false;
					break;
				}
			}
		}
		return $is_virtual;
	}

	/**
	 * Gets errormessage.
	 *
	 * @param array $payment_response_array Payment response array.
	 * @param mixed $order                  Order.
	 * @param mixed $json                   Json.
	 * @param mixed $sca_case               SCA case.
	 * @return array
	 */
	public function get_error_message( $payment_response_array, $order = null, $json = null, $sca_case = null ) {
		$error_msg = $payment_response_array['reason'];
		$http_code = $payment_response_array['httpcode'];
		if ( VISA_ACCEPTANCE_NO === $sca_case && VISA_ACCEPTANCE_STRING_CUSTOMER_AUTHENTICATION_REQUIRED === $payment_response_array['reason'] ) {
			$return_response['sca'] = VISA_ACCEPTANCE_YES;
		} elseif ( VISA_ACCEPTANCE_FOUR_ZERO_ONE === (int) $http_code ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_MID_CREDENTIAL;
		} elseif ( VISA_ACCEPTANCE_FIVE_ZERO_TWO === (int) $http_code || in_array( $error_msg, $this->get_error_messages(), true ) ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_SERVER_ERROR;
		} elseif ( VISA_ACCEPTANCE_TWO_ZERO_THREE === (int) $http_code || VISA_ACCEPTANCE_FOUR_ZERO_ZERO === (int) $http_code ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_PROCESS_REQUEST_ERROR;
		} elseif ( 'default' === $error_msg || VISA_ACCEPTANCE_AUTHENTICATION_FAILED === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_PAYMENT_LOAD_ERROR;
		} elseif ( VISA_ACCEPTANCE_EXPIRED_CARD === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
		} elseif ( VISA_ACCEPTANCE_UNEXPECTED_ERROR === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_UNEXPECTED_OCCURED_ERROR;
		} elseif ( VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION_ERROR;
		} elseif ( VISA_ACCEPTANCE_PROCESSOR_TIMEOUT === $error_msg || VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT === $error_msg || VISA_ACCEPTANCE_API_RESPONSE_STATUS_DECISION_REJECT === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_TIMEOUT_ERROR;
		} elseif ( VISA_ACCEPTANCE_CSRF_EXPIRED === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_SESSION_EXPIRED_ERROR;
		} elseif ( VISA_ACCEPTANCE_CSRF_INVALID === $error_msg || VISA_ACCEPTANCE_CSRF_VALIDATION_ERROR === $error_msg || VISA_ACCEPTANCE_INVALID_DATA_ERROR === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
		} else {
			$return_response[ VISA_ACCEPTANCE_STRING_ERROR ] = VISA_ACCEPTANCE_INVALID_MID_CREDENTIAL;
		}
		$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
		if ( null !== $order ) {
			$this->update_failed_order( $order, $payment_response_array );
		}
		if ( isset( $return_response[ VISA_ACCEPTANCE_STRING_ERROR ] ) ) {
			$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
			$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order->get_id() );
		}
		// Condition.
		if ( null !== $json ) {
			$checkout_url                = wc_get_checkout_url();
			$return_response['status']   = $json->status;
			$return_response['redirect'] = $checkout_url;
			if ( isset( $return_response[ VISA_ACCEPTANCE_STRING_ERROR ] ) ) {
				if ( ! empty( $payment_response_array['cardholderMessage'] ) ) {
					$this->mark_order_failed( $payment_response_array['cardholderMessage'] );
				}
				$this->mark_order_failed( $return_response['error'] );
				return $return_response;
			}
		}
		$return_response['reason'] = $error_msg;
		return $return_response;
	}
		/**
		 * Returns the list of error messages.
		 *
		 * @return array
		 */
	public function get_error_messages() {
		return array(
			'SERVER_ERROR',
			'GENERAL_DECLINE',
			'digital_payment',
			'CUSTOMER_AUTHENTICATION_REQUIRED',
			'token_error',
			'INVALID_ACCOUNT',
			'PROCESSOR_DECLINED',
			'INSUFFICIENT_FUND',
			'STOLEN_LOST_CARD',
			'ISSUER_UNAVAILABLE',
			'UNAUTHORIZED_CARD',
			'CVN_NOT_MATCH',
			'EXCEEDS_CREDIT_LIMIT',
			'INVALID_CVN',
			'DECLINED_CHECK',
			'BOLETO_DECLINED',
			'DEBIT_CARD_USAGE_LIMIT_EXCEEDED',
			'CONSUMER_AUTHENTICATION_FAILED',
			'FAILED',
			'GPAY_ERROR',
			'VISASRC_ERROR',
			'PAYMENT_REFUSED',
			'BLACKLISTED_CUSTOMER',
			'SUSPENDED_ACCOUNT',
		);
	}
}
