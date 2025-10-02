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
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-zero-auth-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';

use CyberSource\Api\PaymentsApi;

/**
 * Visa Acceptance Payment Methods Class
 * Handles Tokenisation requests
 */
class Visa_Acceptance_Payment_Methods extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 *  @var object $gateway */
	public $gateway;

	/**
	 * PaymentMethods constructor.
	 *
	 * @param object $gateway gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Deletes payment token from a gateway
	 *
	 * @param string            $core_token_id token id.
	 * @param \WC_Payment_Token $core_token token object.
	 *
	 * @return array
	 */
	public function delete_token_from_gateway( $core_token_id, $core_token ) {
		try {
			$response = array();
			if ( $core_token instanceof \WC_Payment_Token && $this->gateway->get_id() === $core_token->get_gateway_id() ) {
					$token      = $this->build_token_data( $core_token );
					$token_data = $token['token_information'];
					$response   = $this->get_delete_response( $token_data['id'], $token_data );
			}
			return $response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'unable to deletes payment token from a gateway', true );
		}
	}

	/**
	 * Builds the token data
	 *
	 * @param \WC_Payment_Token $core_token token object.
	 *
	 * @return array
	 */
	public function build_token_data( $core_token ) {
		try {
			$props           = $this->get_props();
			$data            = array();
			$core_token_data = $core_token->get_data();
			$meta_data       = $core_token_data['meta_data'];
			foreach ( $meta_data as $meta_datum ) {
				$data[ $meta_datum->key ] = $meta_datum->value;
			}
			foreach ( $core_token_data as $core_key => $value ) {
				if ( array_key_exists( $core_key, $props ) ) {
					$framework_key          = $props[ $core_key ];
					$data[ $framework_key ] = $value;
				} elseif ( ! isset( $data[ $core_key ] ) ) {
					$data[ $core_key ] = $value;
				}
			}
			return $data;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to build token data.', true );
		}
	}

	/**
	 * Generate delete request payload for token delete
	 *
	 * @param \WC_Payment_Token $token token object.
	 * @param string            $token_data token data.
	 *
	 * @return array
	 */
	public function get_delete_response( $token, $token_data ) {
		// Initialize CyberSource API Client using visa acceptance adapter class.
		$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client             = $request->get_api_client();
		$payment_instrument_api = new \CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );

		try {
			// Call the SDK method to delete the payment instrument.
			$response     = $payment_instrument_api->deleteCustomerPaymentInstrument( $token, $token_data['payment_instrument_id'] );
			$return_array = array(
				'http_code' => $response[1], // CyberSource returns 204 No Content on successful delete.
				'body'      => wp_json_encode( array( 'message' => 'Payment instrument deleted successfully.' ) ),
			);
			return $return_array;
		} catch ( \CyberSource\ApiException $e ) {
			$return_array = array(
				'http_code' => $e->getCode(),
				'body'      => $e->getResponseBody(),
			);
			return $return_array;
		}
	}

	/**
	 * Create payment tokens through payment gateway API
	 *
	 * @param string $transient_token transient token value.
	 * @param object $order order details.
	 *
	 * @return array
	 */
	public function create_token( $transient_token, $order = null ) {
		try {
			$settings                 = $this->gateway->get_gateway_settings();
			$return_result['message'] = null;
			$return_result['status']  = null;
			$customer_data            = $this->get_order_for_add_payment_method();
			$customer                 = WC()->customer;
			if ( $customer->get_billing_first_name() && $customer->get_billing_last_name() ) {
				$response = $this->get_token_response( $transient_token, $customer, $order );
				if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] ) {
					$core_token = $this->check_token_exist( $response, $customer_data );
					if ( $core_token ) {
						$return_result = $this->update_token( $core_token, $response, $settings, $customer_data );
					} else {
						$return_result = $this->gettokenResponseArray( $response, $customer_data );
					}
				} else {
					$return_result['message'] = __( 'Unable to save card. Please try again later.', 'visa-acceptance-solutions' );
					$return_result['status']  = false;
				}
			} else {
				$return_result['message'] = __( 'Please add the address to proceed.', 'visa-acceptance-solutions' );
				$return_result['status']  = false;
			}
			return $return_result;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to create payment tokens through payment gateway API.', true );
		}
	}

	/**
	 * Updates the token if based on response
	 *
	 * @param \WC_Payment_Token $core_token token object.
	 * @param array             $response response.
	 * @param array             $settings settings array.
	 * @param array             $customer_data customer data.
	 *
	 * @return $result
	 */
	public function update_token( $core_token, $response, $settings, $customer_data ) {
		$old_token          = $this->build_token_data( $core_token );
		$json               = json_decode( $response['body'] );
		$tokens             = $this->get_token_information( $json );
		$card_data_response = $this->get_card_details( $tokens, $settings );
		$token_obj 			= new \WC_Payment_Token_CC();
		try {
			if ( isset( $card_data_response[1] ) && VISA_ACCEPTANCE_TWO_ZERO_ZERO === $card_data_response[1] ) {
				$new_token = $this->get_card_token_information( $card_data_response, $tokens );
				if ( $old_token['expiry_month'] !== $new_token['exp_month'] || $new_token['exp_year'] !== $old_token['expiry_year'] ) {
					$new_token = $this->get_card_data_number( $new_token );
					$token_obj->set_token( $new_token['instrument_identifier_id'] );
					$props         = $this->get_props();
					$data          = $this->get_data_to_save( $new_token, $customer_data, false );
					$return_result = $this->save_token_to_database( $core_token, $data, $props );
				} else {
					$return_result['message'] = __( 'Card is already saved. Please try with another card.', 'visa-acceptance-solutions' );
					$return_result['status']  = true;
					$return_result['token']   = $new_token['instrument_identifier_id'];
				}
			} else {
				$return_result['message'] = __( 'Unable to save card. Please try again later.', 'visa-acceptance-solutions' );
				$return_result['status']  = false;
			}
			return $return_result;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to updates token if based on response', true );
		}
	}

	/**
	 * Checks whether the token already exists
	 *
	 * @param array $response response array.
	 * @param array $customer_data customer data.
	 *
	 * @return \WC_Payment_Token|boolean
	 */
	public function check_token_exist( $response, $customer_data ) {
		$settings          = $this->gateway->get_config_settings();
		$environment_id    = $settings['environment'];
		$tokens            = array();
		$new_instrument_id = $this->get_instrument_identifier( $response );
		try {
			if ( $customer_data['customer_id'] ) {
				$core_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
				if ( is_array( $core_tokens ) && ! empty( $core_tokens ) ) {
					foreach ( $core_tokens as $core_token ) {
						if ( $environment_id === $core_token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) ) {
							$tokens         = $this->build_token_data( $core_token );
							$existing_token = $tokens['token'];
							if ( $existing_token === $new_instrument_id ) {
								return $core_token;
							}
						}
					}
				}
			}
			return false;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'unable to check token already exists or not.', true );
		}
	}

	/**
	 * Get instrument identifier ID from response body
	 *
	 * @param array $response response array.
	 *
	 * @return string
	 */
	public function get_instrument_identifier( $response ) {
		try {
			$tokens = $this->get_token_information( json_decode( $response['body'] ) );
			return $tokens['instrument_identifier_id'];
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get instrument id from body', true );
		}
	}

	/**
	 * Create response array based given response data
	 *
	 * @param array $response response array.
	 * @param array $customer_data customer data.
	 *
	 * @return array
	 */
	public function gettokenResponseArray( $response, $customer_data ) {
		$settings               = $this->gateway->get_config_settings();
		$json                   = json_decode( $response['body'] );
		$status                 = $json->status;
		$payment_response_array = $this->get_payment_response_array( $response['http_code'], $response['body'], $status );
		$token_obj 				= new \WC_Payment_Token_CC();
		$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		try {
			if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
				$tokens             = $this->get_token_information( $json );
				$card_data_response = $this->get_card_details( $tokens, $settings );
				if ( VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $card_data_response[1] ) {
					$tokens    = $this->get_card_token_information( $card_data_response, $tokens );
					$tokens    = $this->get_card_data_number( $tokens );
					$token_obj->set_token( $tokens['instrument_identifier_id'] );
					$data          = $this->get_data_to_save( $tokens, $customer_data, true );
					$props         = $this->get_props();
					$return_result = $this->save_token_to_database( $token_obj, $data, $props );
				} else {
					$return_result['message'] = __( 'Unable to fetch card information. Please try again later.', 'visa-acceptance-solutions' );
					$return_result['status']  = false;
				}
			} else {
				$return_response          = $request->get_error_message( $payment_response_array );
				$return_result['message'] = $return_response[ VISA_ACCEPTANCE_STRING_ERROR ];
				$return_result['status']  = false;
			}
			return $return_result;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to create array based on response data.', true );
		}
	}

	/**
	 * Saves token to database
	 *
	 * @param \WC_Payment_Token_CC $token_obj token object.
	 * @param array                $data data to be saved.
	 * @param array                $props properties.
	 *
	 * @return array
	 */
	public function save_token_to_database( $token_obj, $data, $props ) {
		foreach ( $data as $key => $value ) {
			// prefix the expiration year if needed (WC_Payment_Token requires it to be 4 digits long);.

			$core_key = array_search( $key, $props, true );

			/** \WC_Payment_Token does not define a set_is_default method */
			if ( VISA_ACCEPTANCE_IS_DEFAULT === $core_key ) {
				$token_obj->set_default( $value );
			} elseif ( $core_key ) {
				$token_obj->set_props( array( $core_key => $value ) );
			} else {
				$token_obj->update_meta_data( $key, $value, true );
			}
		}
		try {
			$saved_token = $token_obj->save();
			if ( $saved_token ) {
				$return_result['message'] = __( 'Card Saved Succesfully.', 'visa-acceptance-solutions' );
				$return_result['status']  = true;
				$return_result['token']   = $token_obj->get_token();
			}
		} catch ( \Exception $e ) {
			$return_result['message'] = __( 'Unable to store card information. Please try again later.', 'visa-acceptance-solutions' );
			$return_result['status']  = false;
		}
		return $return_result;
	}

	/**
	 * Get data of token from transaction details to save
	 *
	 * @param array   $tokens tokens array.
	 * @param array   $customer_data customer data.
	 * @param boolean $new_card new card.
	 *
	 * @return array
	 */
	public function get_data_to_save( $tokens, $customer_data, $new_card ) {
		try {
			$settings                                  = $this->gateway->get_config_settings();
			$data                                      = array();
			$token_identifier['id']                    = $tokens['id'];
			$token_identifier['payment_instrument_id'] = $tokens['payment_instrument_id'];
			$token_identifier['state']                 = VISA_ACCEPTANCE_ACTIVE;
			$token_identifier['new']                   = $new_card;
			$data['first_six']                         = $tokens['first_six'];
			$data['last_four']                         = $tokens['last_four'];
			$data['token_information']                 = $token_identifier;
			$data['card_type']                         = $this->get_card_type_name( $tokens['card_type'] );
			$data['exp_month']                         = $tokens['exp_month'];
			$data['exp_year']                          = $tokens['exp_year'];
			$data['gateway_id']                        = $this->gateway->get_id();
			$data['user_id']                           = $customer_data['customer_id'];
			$data['environment']                       = $settings['environment'];
			return $data;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get data of token from transaction details to save', true );
		}
	}

	/**
	 * Gets type of card
	 *
	 * @param string $card_type card type.
	 *
	 * @return string
	 */
	public function get_card_type_name( $card_type ) {
		try {
			$types = array(
				'001' => 'Visa',
				'002' => 'Mastercard',
				'003' => 'AMEX',
				'004' => 'Discover',
				'005' => 'DinersClub',
				'007' => 'JCB',
			);
			return ( ! empty( $types[ $card_type ] ) ) ? $types[ $card_type ] : 'NA';
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get type of card.', true );
		}
	}

	/**
	 * Gets Card information
	 *
	 * @param array $card_data_response response array for card data.
	 * @param array $tokens tokens array.
	 *
	 * @return array
	 */
	public function get_card_token_information( $card_data_response, $tokens ) {
		try {
			$card_data_response_json = json_decode( $card_data_response[0] );
			$tokens['exp_month']     = isset( $card_data_response_json->card->expirationMonth ) ? $card_data_response_json->card->expirationMonth : null;
			$tokens['exp_year']      = isset( $card_data_response_json->card->expirationYear ) ? $card_data_response_json->card->expirationYear : null;
			$tokens['card_number']   = isset( $card_data_response_json->_embedded->instrumentIdentifier->card->number ) ? $card_data_response_json->_embedded->instrumentIdentifier->card->number : null;
			$tokens['card_type']     = isset( $card_data_response_json->card->type ) ? $card_data_response_json->card->type : null;
			return $tokens;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get card information.', true );
		}
	}

	/**
	 * Gets token information
	 *
	 * @param object $json json data for token object.
	 *
	 * @return array
	 */
	public function get_token_information( $json ) {
		$request = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		try {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase.
			$tokens = array();

			// Check for customer ID in tokenInformation.
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( isset( $json->tokenInformation ) && isset( $json->tokenInformation->customer ) && isset( $json->tokenInformation->customer->id ) ) {
				$tokens['id'] = $json->tokenInformation->customer->id; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} 
			// Fallback: Check for customer ID in paymentInformation.
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			elseif ( isset( $json->paymentInformation ) && isset( $json->paymentInformation->customer ) && isset( $json->paymentInformation->customer->id ) ) {
				$tokens['id'] = $json->paymentInformation->customer->id; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}       // CustomerId - at checkout.
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( empty( $tokens['id'] ) ) {
				$tokens['id'] = $request->get_customer_id();
			}
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$tokens['instrument_identifier_id'] = isset( $json->tokenInformation->instrumentIdentifier->id ) ? $json->tokenInformation->instrumentIdentifier->id : null;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$tokens['payment_instrument_id'] = isset( $json->tokenInformation->paymentInstrument->id ) ? $json->tokenInformation->paymentInstrument->id : null;
			return $tokens;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get token information.', true );
		}
	}

	/**
	 * Sets card number details
	 *
	 * @param array $tokens tokens array.
	 *
	 * @return array
	 */
	public function get_card_data_number( $tokens ) {
		$card_num            = $tokens['card_number'];
		$tokens['first_six'] = substr( $card_num, 0, 6 );
		$tokens['last_four'] = substr( $card_num, -4 );
		return $tokens;
	}

	/**
	 * Gets properties of card
	 *
	 * @return array
	 */
	public function get_props() {
		$response = array(
			'gateway_id'   => 'gateway_id',
			'user_id'      => 'user_id',
			'is_default'   => 'default',
			'last4'        => 'last_four',
			'expiry_year'  => 'exp_year',
			'expiry_month' => 'exp_month',
			'card_type'    => 'card_type',
		);
		return $response;
	}

	/**
	 * Gets card details
	 *
	 * @param array $tokens tokens array.
	 * @param array $settings settings.
	 *
	 * @return array
	 */
	public function get_card_details( $tokens, $settings ) {
		try {
			$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$api_client             = $request->get_api_client();
			$payment_instrument_api = new \CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );
			$card_details_response  = $payment_instrument_api->getCustomerPaymentInstrument( $tokens['id'], $tokens['payment_instrument_id'] );
			return $card_details_response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get card details.', true );
		}
	}

	/**
	 * Gets token response payload
	 *
	 * @param string $transient_token transient token.
	 * @param array  $customer customer data.
	 * @param mixed  $order order details.
	 *
	 * @return array
	 */
	public function get_token_response( $transient_token, $customer, $order = null ) {
		$action_list = array( VISA_ACCEPTANCE_DECISION_SKIP, VISA_ACCEPTANCE_TOKEN_CREATE );

		// Initialize CyberSource API Client.
		$request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client   = $request->get_api_client();
		$payments_api = new \CyberSource\Api\PaymentsApi( $api_client );

		// Build the payload.
		$client_reference_information = new \CyberSource\Model\Ptsv2paymentsClientReferenceInformation(
			array(
				'code'               => strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) ),
				'partner'            => $request->client_reference_information_partner(),
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		$order_information = new \CyberSource\Model\Ptsv2paymentsOrderInformation(
			array(
				'billTo'        => new \CyberSource\Model\Ptsv2paymentsOrderInformationBillTo(
					array(
						'firstName'          => $customer->get_billing_first_name(),
						'lastName'           => $customer->get_billing_last_name(),
						'address1'           => $customer->get_billing_address_1(),
						'locality'           => $customer->get_billing_city(),
						'administrativeArea' => $customer->get_billing_state(),
						'postalCode'         => $customer->get_billing_postcode(),
						'country'            => $customer->get_billing_country(),
						'email'              => $customer->get_billing_email(),
						'phoneNumber'        => $customer->get_billing_phone(),
					)
				),
				'amountDetails' => new \CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
					array(
						'totalAmount' => VISA_ACCEPTANCE_ZERO_AMOUNT,
						'currency'    => get_woocommerce_currency(),
					)
				),
			)
		);

		$authorization_options = new \CyberSource\Model\Ptsv2paymentsProcessingInformationAuthorizationOptions(
			array(
				'credentialStoredOnFile' => true,
				'type'                   => 'customer',
			)
		);
		if ( ! empty( $order ) && $order instanceof \WC_Order ) {
			$payment_solution = $order->get_meta( VISA_ACCEPTANCE_WC_UC_ID . 'payment_solution', true, VISA_ACCEPTANCE_EDIT );
			if ( ! empty( $payment_solution ) ) {
				$processing_information['paymentSolution'] = $payment_solution;
			}
		}

		$processing_information = new \CyberSource\Model\Ptsv2paymentsProcessingInformation(
			array(
				'actionList'           => $action_list,
				'authorizationOptions' => $authorization_options,
			)
		);
		$buyer_information      = new \CyberSource\Model\Ptsv2paymentsBuyerInformation(
			array(
				'merchantCustomerId' => $order ? $order->get_user_id() : 'guest_' . uniqid(),
			)
		);
		$payload                = new \CyberSource\Model\CreatePaymentRequest(
			array(
				'clientReferenceInformation' => $client_reference_information,
				'orderInformation'           => $order_information,
				'tokenInformation'           => $request->get_cybersource_token_information( $transient_token ),
				'deviceInformation'          => $request->get_device_information(),
				'processingInformation'      => $processing_information,
				'buyerInformation'           => $buyer_information,
			)
		);
		$payload = $request->get_action_token_type( $payload );

		if ( ! empty( $payload ) ) {
			$this->gateway->add_logs_data( $payload, true, ucfirst( VISA_ACCEPTANCE_TOKENIZATION ) );
			try {
				// Make the API call.
				$api_response = $payments_api->createPayment( $payload );
				$this->gateway->add_logs_service_response( $api_response[0],$api_response[2]['v-c-correlation-id'], true, ucfirst( VISA_ACCEPTANCE_TOKENIZATION ) );
				$return_array = array(
					'http_code' => $api_response[1],
					'body'      => $api_response[0],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, ucfirst( VISA_ACCEPTANCE_TOKENIZATION ) );
			}
		}
		
	}
}
