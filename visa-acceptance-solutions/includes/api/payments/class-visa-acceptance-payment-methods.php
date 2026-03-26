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
		$api_client             = $request->get_api_client(true);
		$payment_instrument_api = new \CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );

		try {
			// Call the SDK method to delete the payment instrument.
			$response     = $payment_instrument_api->deleteCustomerPaymentInstrument( $token, $token_data['payment_instrument_id'] );
			$return_array = array(
				'http_code' => $response[VISA_ACCEPTANCE_VAL_ONE], // CyberSource returns 204 No Content on successful delete.
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
	 * Enhanced with automatic authorization reversal for China UnionPay (CUP) cards
	 *
	 * @param string  $transient_token transient token value.
	 * @param object  $order order details.
	 * @param boolean $is_echeck whether this is an eCheck token.
	 *
	 * @return array
	 */
	public function create_token( $transient_token, $order = null, $is_echeck = false ) {
		try {
			$settings                 = $this->gateway->get_gateway_settings();
			$return_result['message'] = null;
			$return_result['status']  = null;
			$customer_data            = $this->get_order_for_add_payment_method();
			$customer                 = WC()->customer;
			$refund          		  = new Visa_Acceptance_Refund( $this->gateway );
		
			// Check if this is a CUP or JAYWAN card for automatic authorization reversal (only for cards).
			$unsupported_zero_amount_card = ! $is_echeck ? $this->unsupported_zero_amount_card( $transient_token ) : false;

			if ( $customer->get_billing_first_name() && $customer->get_billing_last_name() ) {
				$response = $this->get_token_response( $transient_token, $customer, $order, $is_echeck );
				if ( empty( $response ) || ! isset( $response['body'] ) || ! $response['body'] ) {
                    $return_result['message'] = __( 'Unable to save card. Please try again', 'visa-acceptance-solutions' );
                    $return_result['status']  = false;
                    return $return_result;
                }
				$client_reference_info = $response['body']->getClientReferenceInformation();
				$code = $client_reference_info->getCode();
			
				if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] ) {
					$core_token = $this->check_token_exist( $response, $customer_data, $is_echeck );
				
					if ( $core_token ) {
						if ( $is_echeck ) {
							$return_result['message'] = __( 'Account is already saved. Please try with another account.', 'visa-acceptance-solutions' );
							$return_result['status']  = true;
							$return_result['token']   = $core_token->get_token();
						} else {
							$return_result = $this->update_token( $core_token, $response, $settings, $customer_data );
						}
					} else {
						$return_result = $this->get_token_Response_Array( $response, $customer_data, $is_echeck, $transient_token );
					}
					if ( $unsupported_zero_amount_card && isset( $return_result['status'] ) && $return_result['status'] ) {
						// Execute automatic authorization reversal for CUP/JAYWAN verification amount.
						$reversal_result = $this->process_card_auth_reversal( $response, $code, $order );

						// Process authorization reversal results.
						if ( $reversal_result['status'] ) {
							$return_result['auth_reversal_status'] = VISA_ACCEPTANCE_SUCCESS;
							$return_result['auth_reversal_transaction_id'] = $reversal_result['reversal_id'];
							$return_result['original_transaction_id'] = $reversal_result['original_id'];
							$return_result['message'] = $return_result['message'] . ' ' . __( 'Verification amount ($1) automatically reversed.', 'visa-acceptance-solutions' );
						} else {
							$return_result['auth_reversal_status'] = VISA_ACCEPTANCE_FAILURE;
							$return_result['auth_reversal_message'] = $reversal_result['message'];
							$return_result['message'] = $return_result['message'] . ' ' . __( 'Note: Verification amount reversal pending.', 'visa-acceptance-solutions' );
						}
					}
				if ( $is_echeck ) {
					$transaction_id  = $response['body']->getId();
					$refund_response = $refund->get_refund_response( null, VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT, $transaction_id, true, $code );
					if ( isset( $refund_response['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ONE === $refund_response['http_code'] ) {
						$return_result['refund_status']           = VISA_ACCEPTANCE_SUCCESS;
						$return_result['refund_transaction_id']   = is_object( $refund_response['body'] ) && method_exists( $refund_response['body'], 'getId' ) ? $refund_response['body']->getId() : VISA_ACCEPTANCE_STRING_EMPTY;
						$return_result['original_transaction_id'] = $transaction_id;
						$return_result['message']                 = $return_result['message'] . ' ' . __( 'Verification amount ($0.01) automatically refunded.', 'visa-acceptance-solutions' );
					} else {
						$return_result['refund_status']  = VISA_ACCEPTANCE_FAILURE;
						$return_result['refund_message'] = $refund_response['message'];
						$return_result['message']        = $return_result['message'] . ' ' . __( 'Note: Verification amount refund pending.', 'visa-acceptance-solutions' );
					}
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
		
			// Return error result.
			return array(
				'message' => __( 'An error occurred while processing your card. Please try again.', 'visa-acceptance-solutions' ),
				'status' => false
			);
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
		$old_token          = $this->build_token_data( $core_token );
		$json               = json_decode( $response['body'] );
		$tokens             = $this->get_token_information( $json );
		$card_data_response = $this->get_card_details( $tokens, $settings );
		$token_obj 			= new \WC_Payment_Token_CC();
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
			$valid_statuses = array( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED, VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS );
			if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $response['http_code'] && in_array( $status, $valid_statuses, true ) ) {
				$tokens = $this->get_token_information( $json );
				$card_data_response = $this->get_card_details( $tokens, $settings );
				$token_obj          = $is_echeck ? new \WC_Payment_Token_eCheck() : new \WC_Payment_Token_CC();
				if ( VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $card_data_response[VISA_ACCEPTANCE_VAL_ONE] ) {
					$tokens = $this->get_card_token_information( $card_data_response, $tokens, $is_echeck );
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
			if ($is_echeck) {
				$data['token']                        			= $tokens['payment_instrument_id'];
				$data['last4']                        			= $tokens['account_number_last_four'];
				$data['account_type']                 			= $tokens['account_type'];
				$token_identifier['instrument_identifier_id'] 	= $tokens['instrument_identifier_id'];
				$data['account_type']                 = $tokens['account_type'];
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
					$tokens['account_number_last_four'] = strlen( $cleaned ) < 4
						? str_pad( $cleaned, 4, 'X', STR_PAD_LEFT )
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
	 * Sets card number details
	 *
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
	 * Gets properties of card or eCheck
	 *
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
	 * Gets card details.
	 *
	 * @param array $tokens tokens array.
	 * @param array $settings settings.
	 *
	 * @return array
	 */
	public function get_card_details( $tokens, $settings ) {
		try {
			$request                = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$api_client             = $request->get_api_client(true);
			$payment_instrument_api = new \CyberSource\Api\CustomerPaymentInstrumentApi( $api_client );
			$card_details_response  = $payment_instrument_api->getCustomerPaymentInstrument( $tokens['id'], $tokens['payment_instrument_id'] );
			return $card_details_response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to get card details.', true );
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
        $payments_api = new \CyberSource\Api\PaymentsApi( $api_client );
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
						'totalAmount' => $total_amount,
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
			$payment_solution = $order->get_meta( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_PAYMENT_SOLUTION, true, VISA_ACCEPTANCE_EDIT );
			if ( ! empty( $payment_solution ) ) {
				$processing_information['paymentSolution'] = $payment_solution;
			}
		}

		$processing_info_array = array(
			'actionList'           => $action_list,
			'authorizationOptions' => $authorization_options,
		);
		if ( $is_echeck ) {
			$processing_info_array += $this->get_echeck_bank_transfer_options();
		}

		$processing_information = new \CyberSource\Model\Ptsv2paymentsProcessingInformation( $processing_info_array );

		$payload_array = array(
			'clientReferenceInformation' => $client_reference_information,
			'orderInformation'           => $order_information,
			'tokenInformation'           => $request->get_cybersource_token_information( $transient_token ),
			'deviceInformation'          => $request->get_device_information(),
			'processingInformation'      => $processing_information,
		);
		if ( $order ) {
			$payload_array['buyerInformation'] = new \CyberSource\Model\Ptsv2paymentsBuyerInformation(
				array( 'merchantCustomerId' => $order->get_user_id() )
			);
		}

		if ( $is_echeck ) {
			$payload_array['paymentInformation'] = $request->get_echeck_payment_information( null );
		}
		
		$payload = new \CyberSource\Model\CreatePaymentRequest( $payload_array );
		$payload = $request->get_action_token_type( $payload );

		if ( ! empty( $payload ) ) {
			$log_header = $is_echeck ? 'eCheck Tokenization' : ucfirst( VISA_ACCEPTANCE_TOKENIZATION );
			$this->gateway->add_logs_data( $payload, true, $log_header );
			try {
				// Make the API call.
				$api_response = $payments_api->createPayment( $payload );
				$this->gateway->add_logs_service_response( $api_response[VISA_ACCEPTANCE_VAL_ZERO],$api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, $log_header );
				$return_array = array(
					'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
					'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
				);
				return $return_array;
			} catch ( \CyberSource\ApiException $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}
		}
	}
}
