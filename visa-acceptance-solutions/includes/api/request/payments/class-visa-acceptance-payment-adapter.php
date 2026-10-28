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

use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\MerchantConfiguration;
use Pymt_Vas\Dependencies\CyberSource\Configuration;
use Pymt_Vas\Dependencies\CyberSource\Logging\LogConfiguration;
use Pymt_Vas\Dependencies\CyberSource\ApiClient as CyberSourceClient;
use Pymt_Vas\Dependencies\CyberSource\Api\TransactionDetailsApi;
use Pymt_Vas\Dependencies\CyberSource\Api\PaymentsApi;

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
     * @param bool      $authentication_type Authentication type flag.
     * @param bool|null $use_mle Optional. Override MLE usage. If null, uses plugin settings.
     * @return array Array of [$configuration, $merchant_configuration].
     */
    public function get_merchant_configuration($authentication_type, $use_mle = null) {
		
        $unified_checkout       = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
        $settings               = $unified_checkout->get_config_settings();
        
        $enable_response_mle = isset( $settings['enable_response_mle'] ) && ( VISA_ACCEPTANCE_YES === $settings['enable_response_mle'] );
        $merchant_configuration = new MerchantConfiguration();
        $configuration          = new Configuration();
        $merchant_configuration->setAuthenticationType( 'JWT' );
        $merchant_configuration->setJwtKeyType( 'SHARED_SECRET' );
        
        if ( VISA_ACCEPTANCE_ENVIRONMENT_TEST === $settings[VISA_ACCEPTANCE_ENVIRONMENT] ) {
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
		$merchant_configuration->setEnableRequestMLEForOptionalApisGlobally( true );
		$mle_request_cert_path = $this->getRequestMleCertPath($settings);
		if (!empty($mle_request_cert_path)) {
			$merchant_configuration->setMleForRequestPublicCertPath($mle_request_cert_path);
		}
		$merchant_configuration->setRequestMleKeyAlias('CyberSource_SJC_US');

		// Configure Response MLE if enabled.
        if ( $enable_response_mle ) {
            $merchant_configuration->setEnableResponseMleGlobally( true );
			$merchant_configuration->setResponseMlePrivateKeyFilePath( $settings['mle_certificate_path'] );
			$merchant_configuration->setResponseMlePrivateKeyFilePassword( $settings['mle_key_password'] );
        } else {
            $merchant_configuration->setEnableResponseMleGlobally( false );
        }
        $merchant_configuration->setSolutionId( VISA_ACCEPTANCE_SOLUTION_ID );
        $merchant_configuration->setLogConfiguration( new LogConfiguration() );
        return [$configuration, $merchant_configuration];
    }

	/**
	 * Get the path to the extracted PEM certificate from the .p12 file.
	 *
	 * @param array $settings Gateway settings containing MLE certificate path and password.
	 * @return string|null Path to the extracted PEM certificate, or null if no path configured.
	 * @throws \Exception When the configured key file path is invalid or the password is incorrect.
	 */
	private function getRequestMleCertPath($settings) {
		$p12_path = $settings['mle_certificate_path'] ?? '';
		$p12_password = $settings['mle_key_password'] ?? '';

		if (empty($p12_path)) {
		return null;
		}

		if (!file_exists($p12_path) || !is_readable($p12_path)) {
		throw new \Exception( 'Request MLE: Key file not found or not readable: ' . esc_html( $p12_path ) );
		}

		// Cache the extracted PEM cert alongside the .p12 to avoid repeated extraction.
		$pem_cache_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mle_request_cert_' . md5($p12_path . $p12_password) . '.pem';
		// Re-extract if cache doesn't exist or .p12 is newer.
		if (!file_exists($pem_cache_path) || filemtime($p12_path) > filemtime($pem_cache_path)) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local certificate file for OpenSSL processing.
		$p12_content = file_get_contents($p12_path);
		$certs = array();
		if (!openssl_pkcs12_read($p12_content, $certs, $p12_password)) {
			throw new \Exception( 'Request MLE: Failed to read key file. The key file path or password may be incorrect.' );
		}
		
		// Collect all certificates from the .p12 (main cert + extra certs).
		$all_pem_certs = '';
		if (!empty($certs['cert'])) {
			$all_pem_certs .= $certs['cert'];
		}
		if (!empty($certs['extracerts']) && is_array($certs['extracerts'])) {
			foreach ($certs['extracerts'] as $extra_cert) {
			$all_pem_certs .= $extra_cert;
			}
		}
		
		if (empty($all_pem_certs)) {
			throw new \Exception( 'Request MLE: No certificate found in key file.' );
		}
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing local certificate cache file.
		file_put_contents($pem_cache_path, $all_pem_certs);
		}
		return $pem_cache_path;
	}
	
	/**
     * Api Client function.
     *
     * @param bool $authentication_type Authentication type.
     * @param bool $use_mle Whether to use MLE encryption (default: null = use settings).
     * @return CyberSourceClient API client.
     */
    public function get_api_client($authentication_type = false, $use_mle = null) {
        $merchant_config = $this->get_merchant_configuration($authentication_type, $use_mle);
        $api_client      = new CyberSourceClient($merchant_config[VISA_ACCEPTANCE_VAL_ZERO], $merchant_config[VISA_ACCEPTANCE_VAL_ONE]);
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
		$hashed_key     = substr( hash( VISA_ACCEPTANCE_ALGORITHM_SHA256, $val_id, true ), VISA_ACCEPTANCE_VAL_ZERO, 32 ); // phpcs:ignore WordPress.Security.NonceVerification
		return openssl_decrypt( $encrypted_data, 'aes-256-gcm', $hashed_key, OPENSSL_RAW_DATA, $ext_id, $ref_id ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Gets billing information.
	 *
	 * @param mixed $order  Order.
	
	 * @return array
	 */
	public function get_billing_information( $order ) {
  $b = $order->get_data()['billing'];

  // For BO orders the customer profile may be incomplete; fall back to WC_Customer account data.
  $customer_id = $order->get_customer_id();
  if ( $customer_id ) {
   $wc_customer = new \WC_Customer( $customer_id );
   if ( empty( $b['first_name'] ) ) { $b['first_name'] = $wc_customer->get_billing_first_name(); }
   if ( empty( $b['last_name'] ) )  { $b['last_name']  = $wc_customer->get_billing_last_name(); }
   if ( empty( $b['address_1'] ) )  { $b['address_1']  = $wc_customer->get_billing_address_1(); }
   if ( empty( $b['city'] ) )       { $b['city']       = $wc_customer->get_billing_city(); }
   if ( empty( $b['phone'] ) )      { $b['phone']      = $wc_customer->get_billing_phone(); }
   if ( empty( $b['postcode'] ) )   { $b['postcode']   = $wc_customer->get_billing_postcode(); }
   if ( empty( $b['state'] ) )      { $b['state']      = $wc_customer->get_billing_state(); }
   if ( empty( $b['country'] ) )    { $b['country']    = $wc_customer->get_billing_country(); }
   if ( empty( $b['email'] ) )      { $b['email']      = $wc_customer->get_billing_email(); }
  }

  $bill_to_information = array(
   'firstName'          => $b['first_name'],
   'lastName'           => $b['last_name'],
   'address1'           => $b['address_1'],
   'postalCode'         => $b['postcode'],
   'locality'           => $b['city'],
   'administrativeArea' => $b['state'],
   'country'            => $b['country'],
   'email'              => $b['email'],
  );
  if ( ! empty( $b['address_2'] ) ) {
   $bill_to_information['address2'] = $b['address_2'];
  }
  if ( ! empty( $b['phone'] ) ) {
   $bill_to_information['phoneNumber'] = $b['phone'];
  }
  // Strip any remaining empty strings to prevent CyberSource MISSING_FIELD errors.
  return array_filter( $bill_to_information, function( $v ) { return '' !== (string) $v; } );
 }

	/**
	 * Gets shipping information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_shipping_information( $order ) {
  $order_data = $order->get_data();
  $s          = $order_data['shipping'];

  // Fall back to WC_Customer account data for any empty shipping fields (common in BO orders).
  $customer_id = $order->get_customer_id();
  if ( $customer_id ) {
   $wc_customer = new \WC_Customer( $customer_id );
   if ( empty( $s['first_name'] ) ) { $s['first_name'] = $wc_customer->get_shipping_first_name(); }
   if ( empty( $s['last_name'] ) )  { $s['last_name']  = $wc_customer->get_shipping_last_name(); }
   if ( empty( $s['address_1'] ) )  { $s['address_1']  = $wc_customer->get_shipping_address_1(); }
   if ( empty( $s['city'] ) )       { $s['city']       = $wc_customer->get_shipping_city(); }
   if ( empty( $s['postcode'] ) )   { $s['postcode']   = $wc_customer->get_shipping_postcode(); }
   if ( empty( $s['state'] ) )      { $s['state']      = $wc_customer->get_shipping_state(); }
   if ( empty( $s['country'] ) )    { $s['country']    = $wc_customer->get_shipping_country(); }
  }

  // If no shipping address is set (e.g. virtual products), return null so shipTo is omitted entirely.
  if ( empty( $s['address_1'] ) && empty( $s['first_name'] ) ) {
   return null;
  }

  $ship_to_information = array(
   'firstName'          => $s['first_name'],
   'lastName'           => $s['last_name'],
   'address1'           => $s['address_1'],
   'postalCode'         => $s['postcode'],
   'locality'           => $s['city'],
   'administrativeArea' => $s['state'],
   'country'            => $s['country'],
  );
  if ( ! empty( $s['address_2'] ) ) {
   $ship_to_information['address2'] = $s['address_2'];
  }
  if ( ! empty( $s['phone'] ) ) {
   $ship_to_information['phoneNumber'] = $s['phone'];
  }
  if ( ! empty( $order_data['billing']['email'] ) ) {
   $ship_to_information['email'] = $order_data['billing']['email'];
  }
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
		// Check if this is an eCheck token.
		$is_echeck = isset( $token_data['is_echeck'] ) && $token_data['is_echeck'];
		
		if ( $is_echeck ) {
			// eCheck: identify via paymentInstrument + customer + paymentType.
			$payment_info_array = array(
				'paymentInstrument' => array(
					'id' => $token_data['token_information']['payment_instrument_id'],
				),
				'customer'  => array(
					'id' => $token_data['token_information']['id'],
				),
				'paymentType' => array( 'name' => VISA_ACCEPTANCE_CHECK ),
			);
		} else {
			// CC: identify via paymentInstrument + customer.
			$card_array         = array( 'typeSelectionIndicator' => '1' );
			if ( null !== $saved_card_cvv && VISA_ACCEPTANCE_STRING_EMPTY !== $saved_card_cvv ) {
				$card_array['securityCode'] = $saved_card_cvv;
			}
			$payment_info_array = array(
				'paymentInstrument' => array(
					'id' => $token_data['token_information']['payment_instrument_id'],
				),
				'customer'          => array(
					'id' => $token_data['token_information']['id'],
				),
				VISA_ACCEPTANCE_CARD => $card_array,
			);
		}
		
		$payment_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformation( $payment_info_array );
		
		return $payment_information;
	}

	/**
	 * Get cybersource token information
	 *
	 * @param string $trans_token transient token JWT used to retrieve token information.
	 * @return array
	 */
	public function get_cybersource_token_information( $trans_token ) {
		$token_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsTokenInformation(
			array(
				'transientTokenJwt' => $trans_token,
			)
		);
		return $token_information;
	}

	/**
	 * Get eCheck payment information
	 *
	 * @param object $order Order object.
	 * @return object Payment information object.
	 */
	public function get_echeck_payment_information( $order ) {
		$payment_type = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformationPaymentType();
		$payment_type->setName( VISA_ACCEPTANCE_CHECK );

		$payment_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformation();
		$payment_information->setPaymentType( $payment_type );
		
		return $payment_information;
	}

	/**
	 * Gets CyberSource billing information with eCheck support.
	 *
	 * @param mixed $order Order.
	 * @return object
	 */
	public function get_cybersource_billing_information_v2( $order ) {
		$bill_to_information = $this->get_billing_information( $order );
		$bill_to = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationBillTo( $bill_to_information );
		return $bill_to;
	}

	/**
	 * Gets CyberSource shipping information with eCheck support.
	 *
	 * @param mixed $order Order.
	 * @return object
	 */
	public function get_cybersource_shipping_information_v2( $order ) {
		$ship_to_information = $this->get_shipping_information( $order );
		if ( null === $ship_to_information ) {
			return null;
		}
		$ship_to = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationShipTo( $ship_to_information );
		return $ship_to;
	}

	/**
	 * Client Reference Information Partner
	 *
	 * @return array
	 */
	public function client_reference_information_partner() {
		$client_reference_information_partner = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsClientReferenceInformationPartner(
			array(
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
		$stored_ref_code = $this->get_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE );
		// No stored code yet (e.g. first charge for a subscription renewal order) - generate one instead of using the order ID.
		$code = ! empty( $stored_ref_code ) ? $stored_ref_code : strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) );
		$client_reference_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsClientReferenceInformation(
			array(
				'code'               => $code,
				'partner'            => $this->client_reference_information_partner(),
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		return $client_reference_information;
	}

	/**
	 * Order Information
	 *
	 * @param object $order Order object.
	 * @return array
	 */
	public function get_payment_order_information( $order ) {
		$order_info_array = array(
			'amountDetails' => $this->order_information_amount_details( $order ),
			'billTo'        => $this->get_cybersource_billing_information_v2( $order ),
			'lineItems'     => $this->get_line_items_information( $order ),
		);
		// Only include shipTo when a shipping address is actually present.
		$ship_to = $this->get_cybersource_shipping_information_v2( $order );
		if ( ! empty( $ship_to ) ) {
			$order_info_array['shipTo'] = $ship_to;
		}
		$order_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformation( $order_info_array );
		return $order_information;
	}

	/**
	 * Order Information Amount Details.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function order_information_amount_details( $order ) {
		$order_information_amount_details = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
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
		$payment_solution                            = $this->get_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_SOLUTION );
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
			VISA_ACCEPTANCE_CAPTURE           => $this->get_capture( $gateway_settings, $order ),
			'actionList'        => $this->get_action_list( $gateway_settings, $is_save_card, $service ),
		);
		// Set default commerceIndicator to internet for all transactions.
		$processing_information['commerceIndicator'] = VISA_ACCEPTANCE_INTERNET;
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
		if ( $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) ) ) {
			if ( $is_stored_card ) {
				$processing_information = $subscriptions->saved_token_subscriptions_payload( $order, $processing_information, $merchant_initiated );
			} elseif ( VISA_ACCEPTANCE_YES === $is_save_card ) {
				$processing_information = $subscriptions->customer_subscription_payload( $order, $processing_information );
			}
		}
		return $processing_information;
	}

	/**
	 * Client Reference Information
	 *
	 * @param object $order order.
	 * @return array
	 */
	public function get_payment_buyer_information( $order ) {
		$buyer_information = null;
		if($order->get_user_id()) {
			$buyer_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsBuyerInformation(
				array(
					'merchantCustomerId' => strval( $order->get_user_id() ),
				)
			);
		}
		return $buyer_information;
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
            $action_token_type_payload['paymentInformation']['customer']['id'] = $customer_id;
        }
        $action_token_type_payload['processingInformation']['actionTokenTypes'] = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
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
		if ( isset( $gateway_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ] ) && VISA_ACCEPTANCE_NO === $gateway_settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ] ) {
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
		// Plugin-managed behavior: only force capture for virtual-only orders when enabled.
		// All other orders remain authorization-first and can be captured later (manual/auto-capture flow).
		$capture = $this->check_virtual_order_enabled( $gateway_settings, $order );
		if ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			$capture = false;
		}
		return $capture;
	}

	/**
	 * Generates the device information for the request.
	 *
	 * @param bool $merchant_initiated Whether the transaction is merchant-initiated (default: false).
	 * @return object Device information object.
	 */
	public function get_device_information( $merchant_initiated = false ) {
		$settings = $this->gateway->get_config_settings();
		$session_id = VISA_ACCEPTANCE_STRING_EMPTY;
		
		if ( isset( $settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ] ) && VISA_ACCEPTANCE_YES === $settings[ VISA_ACCEPTANCE_SETTING_ENABLE_FRAUD_WEBHOOK ] && ! $merchant_initiated ) {
			$gateway_id = $this->gateway->get_id();
			$session_id = isset( WC()->session ) ? WC()->session->get( "wc_{$gateway_id}_device_data_session_id", VISA_ACCEPTANCE_STRING_EMPTY ) : VISA_ACCEPTANCE_STRING_EMPTY;
		}
		
		// Get client IP address.
		$ip_address = $this->get_client_ip_address();
		
		// Build device information array — omit fingerprintSessionId when empty.
		$device_info_array = array();
		if ( ! empty( $session_id ) ) {
			$device_info_array['fingerprintSessionId'] = $session_id;
		}
		$is_valid_ip = ! empty( $ip_address )
			&& filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false;
		if ( $is_valid_ip ) {
			$device_info_array['ipAddress'] = $ip_address;
		}
		
		$device_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsDeviceInformation( $device_info_array );
		return ! empty( $device_info_array ) ? $device_information : null;
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
        $transaction_id      = $payment_response_array['transaction_id'];
        $payment_response      = $this->get_transaction_details_sdk( $transaction_id );
        // The body is already a stdClass object from SDK, not a JSON string.
        $decoded_test_response = is_string( $payment_response['body'] ) ? json_decode( $payment_response['body'], true ) : json_decode( wp_json_encode( $payment_response['body'] ), true );
 
        if ( ! empty( $decoded_test_response['_links']['relatedTransactions'] ) ) {
            $related_transactions = $decoded_test_response['_links']['relatedTransactions'];
            foreach ( $related_transactions as $related_transaction ) {
                $href_url       = $related_transaction['href'];
                $href_url_split = explode( VISA_ACCEPTANCE_SLASH, $href_url );
                $related_txn_id = end( $href_url_split );
                $res     = $this->get_transaction_details_sdk( $related_txn_id );
                // The body is already a stdClass object from SDK, not a JSON string.
                $res_dec = is_string( $res['body'] ) ? json_decode( $res['body'] ) : $res['body'];
               
                //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                $applications_array = isset( $res_dec->applicationInformation->applications ) ? $res_dec->applicationInformation->applications : null;
                foreach ( $applications_array as $application ) {
                    //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    $application_name = isset( $application->name ) ? $application->name : null;
                    //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    $application_code = isset( $application->rCode ) ? $application->rCode : null;
                    //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    $application_flag = isset( $application->rFlag ) ? $application->rFlag : null;
                    if ( VISA_ACCEPTANCE_ISC_AUTH_REVERSAL === $application_name && VISA_ACCEPTANCE_VAL_ONE === (int) $application_code && 'SOK' === $application_flag ) {
                        $auth_reversal_exist = true;
                    }
                }
            }
        }
        return $auth_reversal_exist;
    }
    
    /**
     * Get transaction details using CyberSource SDK.
     *
     * @param string $transaction_id Transaction ID to retrieve.
     * @return array Response array with http_code and body.
     */
    public function get_transaction_details_sdk( $transaction_id ) {
        try {
            $api_client      = $this->get_api_client(true);
            $transaction_api = new TransactionDetailsApi( $api_client );
           
            $api_response = $transaction_api->getTransaction( $transaction_id );
           
            return array(
                'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
                'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
            );
        } catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
            $error_message = $e->getMessage();
            $error_body    = $e->getResponseBody();
            return array(
                'http_code' => $e->getCode(),
                'body'      => $error_body ? $error_body : wp_json_encode( array( VISA_ACCEPTANCE_ERROR => $error_message ) ),
            );
        }
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
		if ( isset( $gateway_settings['charge_virtual_orders'] ) && VISA_ACCEPTANCE_YES === $gateway_settings['charge_virtual_orders'] ) {
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
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_MID_CREDENTIAL;
		} elseif ( VISA_ACCEPTANCE_FIVE_ZERO_TWO === (int) $http_code || in_array( $error_msg, $this->get_error_messages(), true ) ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_SERVER_ERROR;
		} elseif ( VISA_ACCEPTANCE_TWO_ZERO_THREE === (int) $http_code || VISA_ACCEPTANCE_FOUR_ZERO_ZERO === (int) $http_code ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_PROCESS_REQUEST_ERROR;
		} elseif ( 'default' === $error_msg || VISA_ACCEPTANCE_AUTHENTICATION_FAILED === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_PAYMENT_LOAD_ERROR;
		} elseif ( VISA_ACCEPTANCE_EXPIRED_CARD === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
		} elseif ( VISA_ACCEPTANCE_UNEXPECTED_ERROR === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_UNEXPECTED_OCCURED_ERROR;
		} elseif ( VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_MERCHANT_CONFIGURATION_ERROR;
		} elseif ( VISA_ACCEPTANCE_PROCESSOR_TIMEOUT === $error_msg || VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT === $error_msg || VISA_ACCEPTANCE_API_RESPONSE_STATUS_DECISION_REJECT === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_TIMEOUT_ERROR;
		} elseif ( VISA_ACCEPTANCE_CSRF_EXPIRED === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_SESSION_EXPIRED_ERROR;
		} elseif ( VISA_ACCEPTANCE_CSRF_INVALID === $error_msg || VISA_ACCEPTANCE_CSRF_VALIDATION_ERROR === $error_msg || VISA_ACCEPTANCE_INVALID_DATA_ERROR === $error_msg ) {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
		} elseif ( VISA_ACCEPTANCE_NETWORK_ERROR === $error_msg || VISA_ACCEPTANCE_VAL_ZERO === (int) $http_code ) {
            $return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_PAYMENT_LOAD_ERROR;
		} else {
			$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_MID_CREDENTIAL;
		}
		$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
		if ( null !== $order ) {
			$this->update_failed_order( $order, $payment_response_array );
			if ( isset( $return_response[ VISA_ACCEPTANCE_ERROR ] ) ) {
				// Clean up temporarily stored card data so it is not left in order meta after a failure.
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_NORMAL . $order->get_id() );
				$this->delete_order_meta( $order, VISA_ACCEPTANCE_SAVED_CARD_BLOCKS . $order->get_id() );
			}
		}
		// Condition.
		if ( null !== $json ) {
			$checkout_url                = wc_get_checkout_url();
			$return_response['status']   = $json->status;
			$return_response['redirect'] = $checkout_url;
			if ( isset( $return_response[ VISA_ACCEPTANCE_ERROR ] ) ) {
				if ( ! empty( $payment_response_array['cardholderMessage'] ) ) {
					$this->mark_order_failed( $payment_response_array['cardholderMessage'] );
				}
				$this->mark_order_failed( $return_response[VISA_ACCEPTANCE_ERROR] );
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
	/**
	 * Refresh payment status using CyberSource SDK.
	 *
	 * @param string $transaction_id CyberSource transaction ID.
	 * @param string $order_id       WooCommerce order ID.
	 * @return array Response with status, http_code, body, and data.
	 */
	public function get_refresh_payment_status_sdk( $transaction_id, $order_id ) {
		try {
			// Build request payload.
			$refresh_request = new \Pymt_Vas\Dependencies\CyberSource\Model\RefreshPaymentStatusRequest(
				array(
					'clientReferenceInformation' => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsClientReferenceInformation(
						array( 'code' => $order_id )
					),
					'processingInformation' => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsProcessingInformation(
						array( 'actionList' => array( 'AP_STATUS' ) )
					),
				)
			);

			// Use SDK PaymentsApi - disable MLE for this call (not required).
			$api_client = $this->get_api_client( false, false );
			$payments_api = new PaymentsApi( $api_client );
			
			// Call refresh payment status.
			list( $response, $http_code, $headers ) = $payments_api->refreshPaymentStatus( $transaction_id, $refresh_request );
			
			// Convert response object to array.
			$response_array = json_decode( wp_json_encode( $response ), true );
			
			$this->gateway->add_logs_data(
				array(
					'http_code' => $http_code,
					'status' => $response_array['status'] ?? 'UNKNOWN',
				),
				true,
				'Refresh Payment Status'
			);
			
			return array(
				'status' => $response_array['status'] ?? 'UNKNOWN',
				'http_code' => $http_code,
				'body' => wp_json_encode( $response_array ),
				'data' => $response_array,
			);
			
		} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
			$this->gateway->add_logs_data(
				array(
					'ApiException',
					'code' => $e->getCode(),
					'message' => $e->getMessage(),
					'response' => $e->getResponseBody(),
				),
				false,
				'Refresh Payment Status',
				true
			);
			
			return array(
				'status' => 'error',
				'http_code' => $e->getCode(),
				'body' => $e->getResponseBody() ?? $e->getMessage(),
			);
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data(
				array( 'Exception: ' . $e->getMessage() ),
				false,
				'Refresh Payment Status',
				true
			);
			
			return array(
				'status' => 'error',
				'http_code' => VISA_ACCEPTANCE_FIVE_ZERO_ZERO,
				'body' => $e->getMessage(),
			);
		}
	}

	/**
     * Refresh payment status.
     *
     * @param string $transaction_id Transaction ID.
     * @param int    $order_id Order ID.
     * @return array Response array with http_code and body.
     */
    public function refresh_payment_status( $transaction_id, $order_id ) {
        try {
            $merchant_config = $this->get_merchant_configuration( false );
            $configuration   = $merchant_config[ VISA_ACCEPTANCE_VAL_ZERO ];
            $merchant_configuration = $merchant_config[ VISA_ACCEPTANCE_VAL_ONE ];
            $api_client = new CyberSourceClient( $configuration, $merchant_configuration );
 
            // Use Payments API to refresh payment status.
            $payments_api = new PaymentsApi( $api_client );
 
            // Create the refresh payment status request.
            $client_reference_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2refreshpaymentstatusidClientReferenceInformation();
            $client_reference_information->setCode( (string) $order_id );
            
            // Create processing information with required action list.
            $processing_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsProcessingInformation();
            $processing_information->setActionList( array( 'AP_STATUS' ) );
           
            $refresh_request = new \Pymt_Vas\Dependencies\CyberSource\Model\RefreshPaymentStatusRequest();
            $refresh_request->setClientReferenceInformation( $client_reference_information );
            $refresh_request->setProcessingInformation( $processing_information );
 
            // Call the API to refresh payment status.
            list( $response, $status_code, $headers ) = $payments_api->refreshPaymentStatus( $transaction_id, $refresh_request );
 
            $this->gateway->add_logs_data(
                array(
                    'transaction_id' => $transaction_id,
                    'order_id' => $order_id,
                ),
                false,
                'Refresh Payment Status Request'
            );
 
            $this->gateway->add_logs_data(
                array(
                    'http_code' => $status_code,
                    'response' => $response ? wp_json_encode( $response ) : VISA_ACCEPTANCE_STRING_EMPTY,
                ),
                false,
                'Refresh Payment Status Response'
            );
            
            // Convert response to array for consistent handling.
            $response_array = is_object( $response ) ? json_decode( wp_json_encode( $response ), true ) : $response;
            
            return array(
                'http_code' => $status_code,
                'body' => is_array( $response_array ) ? wp_json_encode( $response_array ) : ( $response ? wp_json_encode( $response ) : VISA_ACCEPTANCE_STRING_EMPTY ),
                'data' => $response_array,
            );
        } catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
            $this->gateway->add_logs_data(
                array(
                    'http_code' => $e->getCode(),
                    'error' => $e->getMessage(),
                    'response_body' => $e->getResponseBody(),
                ),
                false,
                'Refresh Payment Status Error',
                true
            );
 
            return array(
                'http_code' => $e->getCode(),
                'body' => $e->getResponseBody() ? $e->getResponseBody() : $e->getMessage(),
            );
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data(
                array(
                    'error' => $e->getMessage(),
                ),
                false,
                'Refresh Payment Status Exception',
                true
            );
            return array(
                'http_code' => VISA_ACCEPTANCE_FIVE_ZERO_ZERO,
                'body' => $e->getMessage(),
            );
        }
    }
}
