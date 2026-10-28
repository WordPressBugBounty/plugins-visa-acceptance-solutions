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
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-zero-auth-request.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/class-visa-acceptance-refund.php';
require_once __DIR__ . '/class-visa-acceptance-payment-token-echeck.php';

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
		$data = array();
		try {
			$is_echeck = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $core_token->get_type() );
			if ( $is_echeck ) {
				$token_info = $core_token->get_meta( 'token_information' );
				if ( is_string( $token_info ) ) {
					$token_info = maybe_unserialize( $token_info );
				}
				$token_information = array();
				if ( is_array( $token_info ) ) {
					if ( isset( $token_info['id'] ) ) {
						$token_information['id'] = $token_info['id'];
					}
					if ( isset( $token_info['payment_instrument_id'] ) ) {
						$token_information['payment_instrument_id'] = $token_info['payment_instrument_id'];
					}
					if ( isset( $token_info['instrument_identifier_id'] ) ) {
						$token_information['instrument_identifier_id'] = $token_info['instrument_identifier_id'];
					}
				}
				$data = array(
					'token'             => $core_token->get_token(),
					'token_information' => $token_information,
					'last4'             => $core_token->get_last4(),
					'is_echeck'         => true,
				);
			} else {

				$props           = $this->get_props();
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
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to build token data.', true );
			$data = array(
				'token'             => VISA_ACCEPTANCE_STRING_EMPTY,
				'token_information' => array(),
				'last4'             => VISA_ACCEPTANCE_STRING_EMPTY,
			);
		}
		
		return $data;
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
		$api_client             = $request->get_api_client(); // Use JWT authentication.
		$payment_instrument_api = new \Pymt_Vas\Dependencies\CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );

		try {
			// Call the SDK method to delete the payment instrument.
			$response     = $payment_instrument_api->deleteCustomerPaymentInstrument( $token, $token_data['payment_instrument_id'] );
			$return_array = array(
				'http_code' => $response[VISA_ACCEPTANCE_VAL_ONE], // CyberSource returns 204 No Content on successful delete.
				'body'      => wp_json_encode( array( 'message' => 'Payment instrument deleted successfully.' ) ),
			);
			return $return_array;
		} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
			$return_array = array(
				'http_code' => $e->getCode(),
				'body'      => $e->getResponseBody(),
			);
			return $return_array;
		}
	}
 
	/**
	 * Checks if the transient token represents a CUP or JAYWAN card (combined check)
	 *
	 * @param string $transient_token transient token.
	 *
	 * @return boolean
	 */
	public function unsupported_zero_amount_card( $transient_token ) {
		$result = false;
        try {
            if ( ! empty( $transient_token ) ) {
                $card_type = $this->get_card_type_from_token( $transient_token );
                $result = ( VISA_ACCEPTANCE_CUP_CARD_TYPE === $card_type || VISA_ACCEPTANCE_JAYWAN_CARD_TYPE === $card_type );
            }
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Error checking card type for CUP/JAYWAN validation.', true );
            $result = false;
        }
        return $result;
	}

	/**
     * Gets the card type from transient token
     *
     * @param string $transient_token transient token.
     * @return string|null card type value or null if not found
	 * 
     */
	public function get_card_type_from_token( $transient_token ) {
        $card_type = null;
        try {
            if ( ! empty( $transient_token ) ) {
                $decoded_transient_token = json_decode( base64_decode( explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token )[VISA_ACCEPTANCE_VAL_ONE] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
               
                if ( isset( $decoded_transient_token['content']['paymentInformation'][VISA_ACCEPTANCE_CARD]['type']['value'] ) ) {
                    $card_type = $decoded_transient_token['content']['paymentInformation'][VISA_ACCEPTANCE_CARD]['type']['value'];
                }
            }
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to extract card type from token.', true );
            $card_type = null;
        }
        return $card_type;
    }

	/**
	 * Checks if a saved payment token is a CUP or JAYWAN card
	 *
	 * @param \WC_Payment_Token $token token object.
	 *
	 * @return boolean
	 */
	public function unsupported_zero_amount_saved_card( $token ) {
		$result = false;
        try {
             if ( $token && $token instanceof \WC_Payment_Token_CC ) {
                $card_type = $token->get_card_type();
               
                // Check both the numeric code and the card network name.
                if ( VISA_ACCEPTANCE_CUP_CARD_TYPE === $card_type ||
                     VISA_ACCEPTANCE_JAYWAN_CARD_TYPE === $card_type ||
                     VISA_ACCEPTANCE_CHINA_UNION_PAY === $card_type || VISA_ACCEPTANCE_JAYWAN === $card_type ||
                     stripos( $card_type, 'union' ) !== false ||
                     stripos( $card_type, 'jaywan' ) !== false ) {
                    $result = true;
                }
            }
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check if saved card is CUP or JAYWAN.', true );
            $result = false;
        }
        return $result;
	}

	/**
     * Process automatic authorization reversal for CUP cards.
     *
     * @param array  $tokenization_response tokenization response from create_token.
     * @param string $code client reference code.
     * @param mixed  $order order details.
     *
     * @return array
     */
    public function process_card_auth_reversal( $tokenization_response, $code, $order) {
    try {
        $auth_reversal = new Visa_Acceptance_Auth_Reversal( $this->gateway );
        // Extract transaction ID from tokenization response.
        $json = json_decode( $tokenization_response['body'] );
        $transaction_id = isset( $json->id ) ? $json->id : null;
        if ( empty( $transaction_id ) ) {
            return array( 'status' => false, 'message' => 'No transaction ID found for reversal' );
        }
		$reversal_result = $auth_reversal->get_reversal_response($order,
			VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT,
			'tokenization verification amount - automatic authorization reversal',
			$transaction_id, $code
		);
			// Process authorization reversal API response.
			if ( $reversal_result && isset( $reversal_result['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $reversal_result['http_code'] ) {
				// SUCCESS: Authorization reversal completed.
				$reversal_json = json_decode( $reversal_result['body'] );
				$reversal_transaction_id = isset( $reversal_json->id ) ? $reversal_json->id : VISA_ACCEPTANCE_NOT_APPLICABLE;
				
				// Determine card type for specific success message.
				$card_type_message = 'CUP/JAYWAN';
				if ( isset( $json->paymentInformation ) && isset( $json->paymentInformation->card ) && isset( $json->paymentInformation->card->type ) ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$card_type = $json->paymentInformation->card->type; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( VISA_ACCEPTANCE_CUP_CARD_TYPE === $card_type ) {
							$card_type_message = VISA_ACCEPTANCE_CHINA_UNION_PAY . ' (CUP)';
						} elseif ( VISA_ACCEPTANCE_JAYWAN_CARD_TYPE === $card_type ) {
							$card_type_message = VISA_ACCEPTANCE_JAYWAN;
						}
				}
				
				return array(
					'status' => true,
					'message' => $card_type_message . ' verification amount reversed successfully',
					'reversal_id' => $reversal_transaction_id,
					'original_id' => $transaction_id,
					'card_type' => $card_type_message
				);
			} else {
				// FAILED: Authorization reversal failed.
				$error_details = VISA_ACCEPTANCE_STRING_EMPTY;
				if ( $reversal_result && isset( $reversal_result['body'] ) ) {
					if ( is_array( $reversal_result['body'] ) ) {
						$error_details = implode( ', ', $reversal_result['body'] );
					} else {
						$error_details = (string) $reversal_result['body'];
					}
				}
				
				return array(
					'status' => false,
					'message' => 'Authorization reversal failed - ' . ( $error_details ? $error_details : 'please contact support if verification amount not reversed within 24 hours' ),
					'original_id' => $transaction_id
				);
			}
			
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Authorization reversal processing error - please contact support', true );
			return array( 
				'status' => false, 
				'message' => 'Authorization reversal exception: ' . $e->getMessage() 
			);
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
		$old_token = $this->build_token_data( $core_token );
        $json      = json_decode( $response['body'] );
        $tokens    = $this->get_token_information( $json );
        if ( empty( $tokens['payment_instrument_id'] )
            && ! empty( $tokens['instrument_identifier_id'] ) ) {
            $return_result['message'] = __(
                'Card is already saved. Please try with another card.',
                'visa-acceptance-solutions'
            );
            $return_result['status'] = true;
            $return_result['token']  = $tokens['instrument_identifier_id'];
            return $return_result;
        }
        $card_data_response = $this->get_card_details($tokens,$settings);
        $token_obj = new \WC_Payment_Token_CC();
		try {
			if ( isset( $card_data_response[VISA_ACCEPTANCE_VAL_ONE] ) && VISA_ACCEPTANCE_TWO_ZERO_ZERO === $card_data_response[VISA_ACCEPTANCE_VAL_ONE] ) {
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
	 * @param array   $response response array.
	 * @param array   $customer_data customer data.
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return \WC_Payment_Token|boolean
	 */
	public function check_token_exist( $response, $customer_data, $is_echeck = false ) {
        $settings          = $this->gateway->get_config_settings();
        $environment_id    = $settings['environment'];
        $tokens            = array();
        $new_instrument_id = $this->get_instrument_identifier( $response );
        $result            = false;
       
        try {
            if ( $customer_data['customer_id'] ) {
                $core_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
                if ( is_array( $core_tokens ) && ! empty( $core_tokens ) ) {
                    foreach ( $core_tokens as $core_token ) {
						if ( ! $core_token instanceof \WC_Payment_Token ) {
                            continue;
                        }
                        if ( $is_echeck && VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK !== $core_token->get_type() ) {
                            continue;
                        }
                        if ( $environment_id === $core_token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) ) {
                            $tokens = $this->build_token_data( $core_token );
                            $existing_instrument_id = isset( $tokens['token_information']['instrument_identifier_id'] )
                                ? $tokens['token_information']['instrument_identifier_id']
                                : $tokens['token'];
                           
                            if ( ! empty( $new_instrument_id ) && $existing_instrument_id === $new_instrument_id ) {
                                $result = $core_token;
                                break;
                            }
                        }
                    }
                }
            }
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'unable to check token already exists or not.', true );
        }
       
        return $result;
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
	 * @param array   $response response array.
	 * @param array   $customer_data customer data.
	 * @param boolean $is_echeck whether this is an eCheck token.
	 * @param string  $transient_token transient token JWT.
	 *
	 * @return array
	 */
	public function get_token_Response_Array( $response, $customer_data, $is_echeck = false, $transient_token = null ) {
		$settings               = $this->gateway->get_config_settings();
		$json                   = json_decode( $response['body'] );
		$status                 = $json->status;
		$payment_response_array = $this->get_payment_response_array( $response['http_code'], $response['body'], $status );
		$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$return_result          = array();
		
		try {
			$valid_statuses = array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED );
			if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] && in_array( $status, $valid_statuses, true ) ) {
				$tokens = $this->get_token_information( $json );
				// For express pay or when transient token is provided, skip card details fetch.
                $skip_card_details = ! empty( $transient_token ) || empty( $tokens['payment_instrument_id'] );
                if ( $skip_card_details ) {
                    // Use token information directly without fetching card details.
                    $card_data_response = array( null, VISA_ACCEPTANCE_TWO_ZERO_ZERO, array() );
                } else {
                    $card_data_response = $this->get_card_details( $tokens, $settings );
                }
				$token_obj          = $is_echeck ? new \Visa_Acceptance_Payment_Token_ECheck() : new \WC_Payment_Token_CC();
				$card_details_fetched = VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $card_data_response[VISA_ACCEPTANCE_VAL_ONE];
                // eCheck: don't hard-fail if the live TMS retrieve errors out — save with placeholder last4 rather than losing the token entirely.
                if ( $card_details_fetched || $is_echeck ) {
 
                    if ( $skip_card_details && ! $is_echeck ) {
                        // Google Pay may return only instrumentIdentifier and no paymentInstrument.
                        if ( ! empty( $tokens['instrument_identifier_id'] ) ) {
                            $return_result['status'] = true;
                            $return_result['token']  = $tokens['instrument_identifier_id'];
                            return $return_result;
                        }
                        $return_result['status']  = false;
                        $return_result['message'] = __( 'Unable to retrieve payment information. Please try again.', 'visa-acceptance-solutions' );
                        return $return_result;
                    }
                   
                    // Only call get_card_token_information if we actually fetched card details.
                    if ( ! $skip_card_details && $card_details_fetched ) {
                        $tokens = $this->get_card_token_information( $card_data_response, $tokens, $is_echeck );
                    } elseif ( $is_echeck && empty( $tokens['account_number_last_four'] ) ) {
                        $tokens['account_number_last_four'] = 'XXXX';
                        $tokens['account_type']              = 'Checking';
                    }
				if ( $is_echeck ) {
						$data  = $this->get_data_to_save( $tokens, $customer_data, true, $is_echeck );
						$props = $this->get_props( true );
					} else {
						$cards_information = $this->get_card_data_number( $tokens );
						$token_obj->set_token( $cards_information['instrument_identifier_id'] );
						$data  = $this->get_data_to_save( $cards_information, $customer_data, true, $is_echeck );
						$props = $this->get_props();
					}
						$return_result = $this->save_token_to_database( $token_obj, $data, $props, $is_echeck );
					} else {
					$payment_type              = $is_echeck ? VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK : VISA_ACCEPTANCE_PAYMENT_TYPE_CARD;
					/* translators: %s: Payment type (either 'eCheck' or 'card') */					
					$return_result['message'] = sprintf( __( 'Unable to fetch %s information. Please try again later.', 'visa-acceptance-solutions' ), $payment_type );
					$return_result['status']  = false;
				}
			} else {
				$return_response          = $request->get_error_message( $payment_response_array );
				$return_result['message'] = $return_response[ VISA_ACCEPTANCE_ERROR ];
				$return_result['status']  = false;
			}
			return $return_result;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to create array based on response data.', true );
			$this->gateway->add_logs_data( array( 'exception' => get_class( $e ) . ': ' . $e->getMessage(), 'trace' => $e->getTraceAsString() ), false, 'eCheck Debug - get_token_Response_Array Exception' );
		}
		
		
	}

	/**
	 * Saves token to database
	 *
	 * @param \WC_Payment_Token_CC $token_obj token object.
	 * @param array                $data data to be saved.
	 * @param array                $props properties.
	 * @param bool                 $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function save_token_to_database( $token_obj, $data, $props , $is_echeck = false ) {
        $return_result = array(
            'message' => VISA_ACCEPTANCE_STRING_EMPTY,
            'status'  => false,
        );
        foreach ( $data as $key => $value ) {
            $core_key = array_search( $key, $props, true );
 
            /** \WC_Payment_Token does not define a set_is_default method */
            if ( VISA_ACCEPTANCE_IS_DEFAULT === $core_key ) {
                $token_obj->set_default( $value );
            } elseif ( $core_key ) {
                $token_obj->set_props( array( $core_key => $value ) );
            } else {
				// Skip null values to prevent WordPress deprecation warnings.
                if ( null === $value ) {
                    continue;
                }
                if ( (is_array( $value ) || is_object( $value )) && $is_echeck ) {
                    $value = maybe_serialize( $value );
                }
                $token_obj->update_meta_data( $key, $value, true );
            }
        }
        try {
            $token_obj->save();
            $return_result['message'] = $is_echeck
                ? __( 'Account Saved Successfully.', 'visa-acceptance-solutions' )
                : __( 'Card Saved Succesfully.', 'visa-acceptance-solutions' );
            $return_result['status']  = true;
            $return_result['token']   = $token_obj->get_token();
			$return_result['token_id'] = $token_obj->get_id();
 
        } catch ( \Throwable $e ) {
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
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function get_data_to_save($tokens, $customer_data, $new_card, $is_echeck = false)
	{
		$settings                                  = $this->gateway->get_config_settings();
		try {
			$data                                      = array();
			$token_identifier['id']                    = $tokens['id'];
			$token_identifier['payment_instrument_id'] = $tokens['payment_instrument_id'];
			$token_identifier['state']                 = VISA_ACCEPTANCE_ACTIVE;
			$token_identifier['new']                   = $new_card;
			if ( ! empty( $tokens['instrument_identifier_id'] ) ) {
				$token_identifier['instrument_identifier_id'] = $tokens['instrument_identifier_id'];
			}
			if ($is_echeck) {
				$data['token']                        			= $tokens['payment_instrument_id'];
				$data['last4']                        			= $tokens['account_number_last_four'];
				$data['account_type']                 			= $tokens['account_type'];
			} else {
			$data['first_six']                         = $tokens['first_six'];
			$data['last_four']                         = $tokens['last_four'];
			$data['card_type']                         = $this->get_card_type_name($tokens['card_type']);
			$data['exp_month']                         = $tokens['exp_month'];
			$data['exp_year']                          = $tokens['exp_year'];
			}
			$data['token_information']                 = $token_identifier;
			$data['gateway_id']                        = $this->gateway->get_id();
			$data['user_id']                           = $customer_data['customer_id'];
			$data['environment']                       = $settings['environment'];
			return $data;
		} catch (\Exception $e) {
			$this->gateway->add_logs_data(array($e->getMessage()), false, 'Unable to get data of token from transaction details to save', true);
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
				'062' => VISA_ACCEPTANCE_CHINA_UNION_PAY,
				'024' => 'Maestro',
				'042' => 'Maestro',
				'081' => VISA_ACCEPTANCE_JAYWAN,
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
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function get_card_token_information( $card_data_response, $tokens, $is_echeck = false ) {
		try {
			$card_data_response_json = json_decode( $card_data_response[VISA_ACCEPTANCE_VAL_ZERO] );
			
			if ( $is_echeck ) {
				// Prefer the top-level bankAccount number; fall back to the embedded instrumentIdentifier.
				$masked_account = $card_data_response_json->bankAccount->number //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					?? $card_data_response_json->_embedded->instrumentIdentifier->bankAccount->number
					?? null;

				if ( null !== $masked_account ) {
					$cleaned = trim( preg_replace( '/[X\*\-]/', VISA_ACCEPTANCE_STRING_EMPTY, $masked_account ) );
					$tokens['account_number_last_four'] = strlen( $cleaned ) < VISA_ACCEPTANCE_VAL_FOUR
						? str_pad( $cleaned, VISA_ACCEPTANCE_VAL_FOUR, 'X', STR_PAD_LEFT )
						: substr( $cleaned, -4 );
				} else {
					$tokens['account_number_last_four'] = 'XXXX';
				}
				$tokens['account_type'] = isset( $card_data_response_json->bankAccount->type ) ? ucfirst( strtolower( $card_data_response_json->bankAccount->type ) ) : 'Checking'; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} else {
				$tokens['exp_month']     = isset( $card_data_response_json->card->expirationMonth ) ? $card_data_response_json->card->expirationMonth : null;
				$tokens['exp_year']      = isset( $card_data_response_json->card->expirationYear ) ? $card_data_response_json->card->expirationYear : null;
				$tokens['card_number']   = isset( $card_data_response_json->_embedded->instrumentIdentifier->card->number ) ? $card_data_response_json->_embedded->instrumentIdentifier->card->number : null;
				$tokens['card_type']     = isset( $card_data_response_json->card->type ) ? $card_data_response_json->card->type : null;
			}
			
			return $tokens;
		} catch ( \Exception $e ) {
			$log_type = $is_echeck ? VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK : VISA_ACCEPTANCE_PAYMENT_TYPE_CARD;
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, "Unable to get {$log_type} information.", true );
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
			} elseif ( isset( $json->paymentInformation ) && isset( $json->paymentInformation->customer ) && isset( $json->paymentInformation->customer->id ) ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$tokens['id'] = $json->paymentInformation->customer->id; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			// CustomerId - at checkout.
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
	 * Sets card number details.
	 * @param array $tokens tokens array.
	 *
	 * @return array
	 */
	public function get_card_data_number( $tokens ) {
		$card_num            = $tokens['card_number'];
		$tokens['first_six'] = substr( $card_num, VISA_ACCEPTANCE_VAL_ONE, 6 );
		$tokens['last_four'] = substr( $card_num, -VISA_ACCEPTANCE_VAL_FOUR );
		return $tokens;
	}

	/**
	 * Gets properties of card or eCheck.
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function get_props($is_echeck = false) {
		$response = array(
			'gateway_id' => 'gateway_id',
			'user_id'    => 'user_id',
		);

		if ( $is_echeck ) {
			$response['token']        = 'token';
			$response['last4']        = 'last4';
			// account_type is saved as metadata, not a core property.
			$response['account_type'] = 'account_type';
		} else {
			$response['is_default']   = 'default';
			$response['last4']        = 'last_four';
			$response['expiry_year']  = 'exp_year';
			$response['expiry_month'] = 'exp_month';
			$response['card_type']    = 'card_type';
		}
		return $response;
	}

	/**
     * Fetches a payment instrument from TMS API and returns the data as an array.
     *
     * @param string           $payment_instrument_id The payment instrument ID.
     * @param \WC_Payment_Token $token Optional token object for deletion on 404/410.
     * @return array|null Instrument data array, null on 404/410, or false on other errors.
     */
    public function fetch_tms_payment_instrument( $payment_instrument_id, $token = null ) {
        if ( empty( $payment_instrument_id ) ) {
            return false;
        }
 
        try {
            // Initialize CyberSource API Client using visa acceptance adapter class.
            $request    = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $api_client = $request->get_api_client(); 
 
            // Initialize TMS Payment Instrument API (v1).
            $payment_instrument_api = new \Pymt_Vas\Dependencies\CyberSource\Api\PaymentInstrumentApi( $api_client );
 
            // Call TMS API via SDK method - returns [data, status_code, headers].
            list( $response_data, $status_code, $http_headers ) = $payment_instrument_api->getPaymentInstrumentWithHttpInfo(
                $payment_instrument_id,
                VISA_ACCEPTANCE_STRING_EMPTY 
            );
 
            // Extract data from CyberSource object using getter methods.
            $data = array();
            if ( $response_data && is_object( $response_data ) ) {
                try {
                    // Get embedded data.
                    $embedded = $response_data->getEmbedded();
                    if ( $embedded && method_exists( $embedded, 'getInstrumentIdentifier' ) ) {
                        $instrument_identifier = $embedded->getInstrumentIdentifier();
                        
                        if ( $instrument_identifier && method_exists( $instrument_identifier, 'getTokenizedCard' ) ) {
                            $tokenized_card = $instrument_identifier->getTokenizedCard();
                            
                            if ( $tokenized_card && method_exists( $tokenized_card, 'getCard' ) ) {
                                $card = $tokenized_card->getCard();
                                
                                // Build array structure expected by update_token_from_tms_data.
                                $data = array(
                                    '_embedded' => array(
                                        'instrumentIdentifier' => array(
                                            'tokenizedCard' => array(
                                                'state' => method_exists( $tokenized_card, 'getState' ) ? $tokenized_card->getState() : null,
                                                'card' => array(
                                                    'suffix' => method_exists( $card, 'getSuffix' ) ? $card->getSuffix() : null,
                                                    'expirationMonth' => method_exists( $card, 'getExpirationMonth' ) ? $card->getExpirationMonth() : null,
                                                    'expirationYear' => method_exists( $card, 'getExpirationYear' ) ? $card->getExpirationYear() : null,
                                                ),
                                            ),
                                        ),
                                    ),
                                );
                            }
                        }
                    }
                } catch ( \Exception $e ) {
                    $this->gateway->add_logs_data(
                        array( 'Error extracting TMS data: ' . $e->getMessage() ),
                        false,
                        'TMS Token Refresh',
                        true
                    );
                }
            }
 
            // Return data only on successful 200 response.
            if ( VISA_ACCEPTANCE_TWO_ZERO_ZERO === $status_code && $data ) {
                return $data;
            }
            
            return false;
 
        } catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
            $status_code = $e->getCode();
            if ( VISA_ACCEPTANCE_FOUR_ZERO_FOUR === $status_code || VISA_ACCEPTANCE_FOUR_TEN === $status_code ) {
                $this->gateway->add_logs_data(
                    array(
                        'TMS token unavailable (HTTP ' . $status_code . '): ' . $payment_instrument_id,
                        'Deleting token from local store',
                    ),
                    true,
                    'TMS Token Refresh'
                );
                
                // Delete token from WooCommerce database.
                if ( $token instanceof \WC_Payment_Token ) {
                    \WC_Payment_Tokens::delete( $token->get_id() );
                }
                
                // Return null to indicate token unavailable (not an error).
                return null;
            }
            
            // Other API errors.
            $this->gateway->add_logs_data(
                array(
                    'TMS API error (HTTP ' . $status_code . '): ' . $e->getMessage(),
                    'response_body' => $e->getResponseBody(),
                ),
                false,
                'TMS Token Refresh',
                true
            );
            return false;
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data(
                array( 'TMS API error: ' . $e->getMessage() ),
                false,
                'TMS Token Refresh',
                true
            );
            return false;
        }
    }
 
    /**
     * Updates a WooCommerce payment token with data from TMS API response.
     *
     * @param \WC_Payment_Token $token         The WooCommerce payment token to update.
     * @param array             $tms_data      Data from TMS API paymentinstruments endpoint.
     * @return bool True if token was updated, false otherwise.
     */
    public function update_token_from_tms_data( $token, $tms_data ) {
        if ( ! $token instanceof \WC_Payment_Token || empty( $tms_data ) ) {
            return false;
        }
 
        // Extract tokenizedCard from nested structure: _embedded.instrumentIdentifier.tokenizedCard.
        $embedded = $tms_data['_embedded'] ?? array();
        $instrument_identifier = $embedded['instrumentIdentifier'] ?? array();
        $tokenized_card = $instrument_identifier['tokenizedCard'] ?? array();
        
        // If tokenizedCard is absent, token exists but no network token data yet - skip update.
        if ( empty( $tokenized_card ) ) {
            $this->gateway->add_logs_data(
                array( 'TMS token refresh: tokenizedCard absent for token ID ' . $token->get_id() . ' - retaining existing data' ),
                true,
                'TMS Token Refresh'
            );
            return false;
        }
        
        $card_state = $tokenized_card['state'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
 
        if ( 'ACTIVE' !== $card_state ) {
            $this->gateway->add_logs_data(
                array( 'TMS token refresh: tokenized card is not ACTIVE. State: ' . $card_state . ' for token ID ' . $token->get_id() ),
                false,
                'TMS Token Refresh',
                true
            );
            return false;
        }
 
        // Extract new card details from tokenizedCard.
        $tokenized_card_info = $tokenized_card['card'] ?? array();
        $new_suffix = $tokenized_card_info['suffix'] ?? null;
        $new_expiry_month = $tokenized_card_info['expirationMonth'] ?? null;
        $new_expiry_year = $tokenized_card_info['expirationYear'] ?? null;
        $updated = false;
        $formatted_month = $token->get_expiry_month();

        // Update last4 if changed.
        if ( $new_suffix && $token->get_last4() !== $new_suffix ) {
            $token->set_last4( $new_suffix );
            $updated = true;
        }
 
        // Update expiry month if changed.
        if ( $new_expiry_month ) {
            $formatted_month = str_pad( $new_expiry_month, VISA_ACCEPTANCE_VAL_TWO, '0', STR_PAD_LEFT );
            if ( $token->get_expiry_month() !== $formatted_month ) {
                $token->set_expiry_month( $formatted_month );
                $updated = true;
            }
        }
 
        // Update expiry year if changed.
        if ( $new_expiry_year && $token->get_expiry_year() !== (string) $new_expiry_year ) {
            $token->set_expiry_year( $new_expiry_year );
            $updated = true;
        }
 
        if ( $updated ) {
            // Save token to database.
            $token->save();
 
            $this->gateway->add_logs_data(
                array(
                    'TMS token refresh: updated token ID ' . $token->get_id(),
                    'new_last4' => $new_suffix,
                    'new_expiry' => $formatted_month . '/' . $new_expiry_year,
                ),
                true,
                'TMS Token Refresh'
            );
        }
        return $updated;
    }
 
    /**
     * Refreshes all customer tokens from TMS API at checkout load.
     * Only runs if Network Token feature is enabled.
     *
     * @param array  $tokens   Array of WC_Payment_Token objects.
     * @return void
     */
    public function refresh_all_tokens_from_tms( $tokens ) {
        // Check if Network Token feature is enabled.
        $settings = $this->gateway->get_gateway_settings();
        $network_token_enabled = isset( $settings['enable_network_token_webhook'] ) ? $settings['enable_network_token_webhook'] : VISA_ACCEPTANCE_NO;
        
        if ( VISA_ACCEPTANCE_YES !== $network_token_enabled ) {
            return;
        }
       
        if ( empty( $tokens ) || ! is_array( $tokens ) ) {
            return;
        }
 
        // Get current user ID to ensure we only process THEIR tokens.
        $current_user_id = get_current_user_id();
        
        if ( ! $current_user_id ) {
            return; // Not logged in - no tokens to refresh.
        }
 
        // Throttle: this runs on every checkout render (including AJAX re-renders), so
        // debounce repeat TMS API calls for the same user within a short window.
        $throttle_key = 'vas_tms_refresh_' . $current_user_id . '_' . $this->gateway->get_id();
        if ( false !== get_transient( $throttle_key ) ) {
            return;
        }
        set_transient( $throttle_key, VISA_ACCEPTANCE_VAL_ONE, 15 );
 
        // Filter tokens to only include the current user's tokens (security safeguard).
        $user_tokens = array();
        foreach ( $tokens as $token ) {
            if ( $token instanceof \WC_Payment_Token && (int) $token->get_user_id() === (int) $current_user_id ) {
                $user_tokens[] = $token;
            }
        }
 
        if ( empty( $user_tokens ) ) {
            return; 
        }
 
        $this->gateway->add_logs_data(
            array(
                'TMS token refresh: starting refresh for user ' . $current_user_id,
                'user_token_count' => count( $user_tokens ),
                'total_tokens_passed' => count( $tokens ),
            ),
            true,
            'TMS Token Refresh'
        );
 
        $refreshed_count = VISA_ACCEPTANCE_VAL_ZERO;
        $skipped_count = VISA_ACCEPTANCE_VAL_ZERO;
        $error_count = VISA_ACCEPTANCE_VAL_ZERO;
       
        foreach ( $user_tokens as $token ) {
            if ( ! $token instanceof \WC_Payment_Token ) {
                continue;
            }
 
            // Skip eCheck tokens - TMS is only for card tokens.
            if ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() ) {
                continue;
            }
 
            // Get payment instrument ID from token metadata (recommended token type).
            $token_info = $token->get_meta( 'token_information', true );
            if ( is_string( $token_info ) ) {
                $token_info = maybe_unserialize( $token_info );
            }
 
            $payment_instrument_id = is_array( $token_info ) ? ( $token_info['payment_instrument_id'] ?? null ) : null;
 
            if ( empty( $payment_instrument_id ) ) {
                $skipped_count++;
                continue;
            }
 
            // Call TMS API to fetch latest token data.
            // Pass token object for automatic deletion on 404/410.
            $tms_data = $this->fetch_tms_payment_instrument( $payment_instrument_id, $token );
 
            // Handle token unavailable (404/410) - already deleted by fetch method.
            if ( null === $tms_data ) {
                $error_count++;
                continue; // Token deleted, move to next.
            }
            
            // Handle other errors.
            if ( false === $tms_data ) {
                $error_count++;
                continue;
            }
			// Apply the fetched TMS data (expiry/last4) onto the local token record.
            $this->update_token_from_tms_data( $token, $tms_data );
            $refreshed_count++;
        } 
        $this->gateway->add_logs_data(
            array(
                'TMS token refresh complete for user ' . $current_user_id,
                'tokens_refreshed' => $refreshed_count,
                'tokens_skipped' => $skipped_count,
                'errors' => $error_count,
            ),
            true,
            'TMS Token Refresh'
        );
    }
 
 
	/**
	 * Gets card details.
	 *
	 * @param array $tokens tokens array.
	 * @param array $settings settings.
	 *
	 * @return array
	 */
	public function get_card_details( $tokens, $settings ) {
        try {
            $customer_id           = $tokens['id'];
            $payment_instrument_id = $tokens['payment_instrument_id'];
 
            // Guard: if either ID is empty the API call would be malformed — return a non-200 placeholder.
            if ( empty( $customer_id ) || empty( $payment_instrument_id ) ) {
                return array( null, VISA_ACCEPTANCE_VAL_ZERO, array() );
            }
 
            $request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $api_client             = $request->get_api_client( true );
            $payment_instrument_api = new \Pymt_Vas\Dependencies\CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );
 
            list( $response, $status_code, $http_header ) = $payment_instrument_api->getCustomerPaymentInstrumentWithHttpInfo(
                $customer_id,
                $payment_instrument_id
            );
 
            return array( $response, $status_code, $http_header );
        } catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get card details.', true );
            return array( null, $e->getCode(), array() );
        } catch ( \Throwable $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get card details.', true );
			return array( null, VISA_ACCEPTANCE_VAL_ZERO, array() );
        }
    }
	/**
	 * Gets token response payload.
	 *
	 * @param string  $transient_token transient token.
	 * @param array   $customer customer data.
	 * @param mixed   $order order details.
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function get_token_response( $transient_token, $customer, $order = null, $is_echeck = false ) {
        $action_list = array( VISA_ACCEPTANCE_DECISION_SKIP, VISA_ACCEPTANCE_TOKEN_CREATE );
        $total_amount=VISA_ACCEPTANCE_ZERO_AMOUNT;
        // Initialize CyberSource API Client.
        $request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
        $api_client   = $request->get_api_client();
        $payments_api = new \Pymt_Vas\Dependencies\CyberSource\Api\PaymentsApi( $api_client );
        $decoded_transient_token = ! empty( $transient_token ) ? json_decode( base64_decode( explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token )[VISA_ACCEPTANCE_VAL_ONE] ), true ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
 
       if ( $is_echeck ) {
            // eCheck requires minimum $0.01 for tokenization.
            $total_amount = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
        } elseif ( isset( $decoded_transient_token['content']['paymentInformation'][VISA_ACCEPTANCE_CARD]['type']['value'] ) ) {
            $card_type_value = $decoded_transient_token['content']['paymentInformation'][VISA_ACCEPTANCE_CARD]['type']['value'];
            if ( VISA_ACCEPTANCE_CUP_CARD_TYPE === $card_type_value || VISA_ACCEPTANCE_JAYWAN_CARD_TYPE === $card_type_value ) {
                $total_amount = VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT;
            }
        }
		
		// Build the payload.
		$client_reference_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsClientReferenceInformation(
			array(
				'code'               => strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) ),
				'partner'            => $request->client_reference_information_partner(),
				'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
				'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
			)
		);
		$order_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformation(
			array(
				'billTo'        => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationBillTo(
					array_filter(
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
						),
						function( $v ) { return ! empty( $v ); }
					)
				),
				'amountDetails' => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
					array(
						'totalAmount' => $total_amount,
						'currency'    => get_woocommerce_currency(),
					)
				),
			)
		);

		$authorization_options = new \Pymt_Vas\Dependencies\CyberSource\Model\ProcessingInfoAuthorizationOptions(
			array(
				'initiator' => new \Pymt_Vas\Dependencies\CyberSource\Model\ProcessingInfoAuthorizationOptionsInitiator(
					array(
						'credentialStoredOnFile' => true,
						'type'                   => 'customer',
					)
				),
			)
		);
		if ( ! empty( $order ) && $order instanceof \WC_Order ) {
			$payment_solution = $order->get_meta( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, true, VISA_ACCEPTANCE_EDIT );
			if ( ! empty( $payment_solution ) ) {
				$processing_information['paymentSolution'] = $payment_solution;
			}
		}

		// For eCheck, determine existing customer early so actionTokenTypes can be set correctly.
		$existing_echeck_customer_id = $is_echeck ? $request->get_customer_id() : null;

		$processing_info_array = array(
			'actionList' => $action_list,
		);
		if ( $is_echeck ) {
			$processing_info_array += $this->get_echeck_bank_transfer_options();
			if ( empty( $existing_echeck_customer_id ) ) {
				$processing_info_array['actionTokenTypes'] = array( 'customer', 'paymentInstrument', 'instrumentIdentifier' );
			} else {
				$processing_info_array['actionTokenTypes'] = array( 'paymentInstrument', 'instrumentIdentifier' );
			}
		} else {
			$processing_info_array['authorizationOptions'] = $authorization_options;
		}

		$processing_information = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_info_array );

		$payload_array = array(
			'clientReferenceInformation' => $client_reference_information,
			'orderInformation'           => $order_information,
			'tokenInformation'           => $request->get_cybersource_token_information( $transient_token ),
			'deviceInformation'          => $request->get_device_information(),
			'processingInformation'      => $processing_information,
		);
		if ( $order ) {
			$payload_array['buyerInformation'] = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsBuyerInformation(
				array( 'merchantCustomerId' => strval($order->get_user_id() ) )
			);
		}

		if ( $is_echeck ) {
			$payload_array['paymentInformation'] = $request->get_echeck_payment_information( null );
			// Link to existing CyberSource customer profile if one exists.
			if ( ! empty( $existing_echeck_customer_id ) ) {
				$payment_info_with_customer = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformation(
					array(
						'paymentType' => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformationPaymentType( array( 'name' => VISA_ACCEPTANCE_CHECK ) ),
						'customer'    => new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsPaymentInformationCustomer( array( 'id' => $existing_echeck_customer_id ) ),
					)
				);
				$payload_array['paymentInformation'] = $payment_info_with_customer;
			}
		}
		
		$payload = new \Pymt_Vas\Dependencies\CyberSource\Model\CreatePaymentRequest( $payload_array );
		if ( ! $is_echeck ) {
		$payload = $request->get_action_token_type( $payload );
		}

			try {
			$this->gateway->add_logs_data( (string) $payload, true, 'Token Create' );
			$api_response = $payments_api->createPayment( $payload );
			// Log encrypted response FIRST (if it's encrypted).
			$response_body_raw = $api_response[ VISA_ACCEPTANCE_VAL_ZERO ];
			$this->gateway->add_logs_service_response(
				$response_body_raw,
				$api_response[ VISA_ACCEPTANCE_VAL_TWO ][ VISA_ACCEPTANCE_V_C_CORRELATION_ID ] ?? VISA_ACCEPTANCE_STRING_EMPTY,
				true,
				'Token Create'
			);
			
			// Decrypt MLE response if needed.
			$payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$response_body = $this->decrypt_mle_response( $response_body_raw, $payment_adapter, 'Token Create' );
			
			// Log decrypted response if it was encrypted.
			if ( $response_body !== $response_body_raw ) {
				$this->gateway->add_logs_service_response( 
					$response_body, 
					$api_response[ VISA_ACCEPTANCE_VAL_TWO ][ VISA_ACCEPTANCE_V_C_CORRELATION_ID ], 
					true, 
					'Token Create' 
				);
			}
			
			return array(
				'http_code' => $api_response[ VISA_ACCEPTANCE_VAL_ONE ],
				'body'      => $response_body,
				);
			} catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
				$response_body = $e->getResponseBody();
				$this->gateway->add_logs_header_response(
				wp_json_encode( array(
                    VISA_ACCEPTANCE_ERROR => $e->getMessage(),
                    'response_body'       => is_string( $response_body ) ? $response_body : wp_json_encode( $response_body ),
                ) ), false, 'Token Create Error'
			);
		}
	}
}
