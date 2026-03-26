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
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/class-visa-acceptance-refund.php';
require_once __DIR__ . '/../../class-visa-acceptance-payment-gateway-subscriptions.php';
require_once plugin_dir_path( __DIR__ ) . '/../../public/class-visa-acceptance-payment-gateway-unified-checkout-public.php';
require_once plugin_dir_path( __DIR__ ) . '/../../public/class-visa-acceptance-payment-gateway-expresspay-public.php';
use CyberSource\Api\PaymentsApi;
use CyberSource\Api\TransientTokenDataApi;

/**
 * Visa Acceptance Unified Checkout Authorization Class
 *
 * Handles Unified Checkout Authorization requests
 */
class Visa_Acceptance_Payment_UC extends Visa_Acceptance_Request {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * PaymentUC constructor.
	 *
	 * @param object $gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Returns true if the transient token JWT indicates an eCheck payment type.
	 *
	 * @param string $transient_token The transient token JWT to check.
	 * @return bool True if the token represents an eCheck payment, false otherwise.
	 */
	public function is_echeck_from_transient_token( string $transient_token ): bool {
		$is_echeck = false;
        $parts = explode( VISA_ACCEPTANCE_FULL_STOP, $transient_token );
        if ( isset( $parts[VISA_ACCEPTANCE_VAL_ONE] ) ) {
            $json = base64_decode( $parts[VISA_ACCEPTANCE_VAL_ONE] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding JWT payload.
            $obj = json_decode( $json, true );
            if ( is_array( $obj ) ) {
                $name = $obj['content']['paymentInformation']['paymentType']['name']['value'] ?? $obj['content']['paymentInformation']['paymentType']['name'] ?? $obj['metadata']['paymentType'] ?? null;
                $is_echeck = is_string( $name ) && VISA_ACCEPTANCE_CHECK === strtoupper( $name );
            }
        }

		return $is_echeck;
	}

	/**
	 * Retrieve payment details including address from transient token
	 *
	 * @param string $transient_token Transient Token.
	 * @return array|null Payment details or null on failure.
	 */
	public function get_payment_details_from_transient_token( $transient_token ) {
		try {
			$request    = new Visa_Acceptance_Payment_Adapter( $this->gateway );
			$api_client = $request->get_api_client(true);
			$transient_token_api = new TransientTokenDataApi( $api_client );

			// Call the API to get transient token data.
			$api_response = $transient_token_api->getTransactionForTransientToken( $transient_token );

			// Return the response body which contains orderInformation with billTo and shipTo.
			return array(
				'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
				'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
			);

		} catch ( \CyberSource\ApiException $e ) {
			$this->gateway->add_logs_header_response( 
				wp_json_encode( array( VISA_ACCEPTANCE_ERROR => $e->getMessage() ) ), 
				false, 
				'Get Payment Details Error' 
			);
			return null;
		}
	}

	/**
	 * Extract and update order addresses from payment details
	 *
	 * @param \WC_Order $order Order object.
	 * @param object    $payment_details Payment details from API.
	 * @return void
	 */
	public function update_order_addresses_from_payment_details( $order, $payment_details ) {
		if ( ! $payment_details || ! is_object( $payment_details ) ) {
			return;
		}

		// Extract billing address.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- CyberSource API uses camelCase.
		if ( isset( $payment_details->orderInformation ) && isset( $payment_details->orderInformation->billTo ) ) {
			$bill_to = $payment_details->orderInformation->billTo;
			
			if ( isset( $bill_to->firstName ) ) {
				$order->set_billing_first_name( sanitize_text_field( $bill_to->firstName ) );
			}
			if ( isset( $bill_to->lastName ) ) {
				$order->set_billing_last_name( sanitize_text_field( $bill_to->lastName ) );
			}
			if ( isset( $bill_to->address1 ) ) {
				$order->set_billing_address_1( sanitize_text_field( $bill_to->address1 ) );
			}
			if ( isset( $bill_to->address2 ) ) {
				$order->set_billing_address_2( sanitize_text_field( $bill_to->address2 ) );
			}
			if ( isset( $bill_to->locality ) ) {
				$order->set_billing_city( sanitize_text_field( $bill_to->locality ) );
			}
			if ( isset( $bill_to->administrativeArea ) ) {
				$order->set_billing_state( sanitize_text_field( $bill_to->administrativeArea ) );
			}
			if ( isset( $bill_to->postalCode ) ) {
				$order->set_billing_postcode( sanitize_text_field( $bill_to->postalCode ) );
			}
			if ( isset( $bill_to->country ) ) {
				$order->set_billing_country( sanitize_text_field( $bill_to->country ) );
			}
			if ( isset( $bill_to->phoneNumber ) ) {
				$order->set_billing_phone( sanitize_text_field( $bill_to->phoneNumber ) );
			}
			if ( isset( $bill_to->email ) ) {
				$order->set_billing_email( sanitize_email( $bill_to->email ) );
			}
		}

		// Extract shipping address.
		if ( isset( $payment_details->orderInformation ) && isset( $payment_details->orderInformation->shipTo ) ) {
			$ship_to = $payment_details->orderInformation->shipTo;
			
			if ( isset( $ship_to->firstName ) ) {
				$order->set_shipping_first_name( sanitize_text_field( $ship_to->firstName ) );
			}
			if ( isset( $ship_to->lastName ) ) {
				$order->set_shipping_last_name( sanitize_text_field( $ship_to->lastName ) );
			}
			if ( isset( $ship_to->address1 ) ) {
				$order->set_shipping_address_1( sanitize_text_field( $ship_to->address1 ) );
			}
			if ( isset( $ship_to->address2 ) ) {
				$order->set_shipping_address_2( sanitize_text_field( $ship_to->address2 ) );
			}
			if ( isset( $ship_to->locality ) ) {
				$order->set_shipping_city( sanitize_text_field( $ship_to->locality ) );
			}
			if ( isset( $ship_to->administrativeArea ) ) {
				$order->set_shipping_state( sanitize_text_field( $ship_to->administrativeArea ) );
			}
			if ( isset( $ship_to->postalCode ) ) {
				$order->set_shipping_postcode( sanitize_text_field( $ship_to->postalCode ) );
			}
			if ( isset( $ship_to->country ) ) {
				$order->set_shipping_country( sanitize_text_field( $ship_to->country ) );
			}
			if ( isset( $ship_to->phoneNumber ) ) {
				$order->set_shipping_phone( sanitize_text_field( $ship_to->phoneNumber ) );
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Save the order with updated addresses.
		$order->save();
	}

	/**
	 * Initiates Unified Checkout transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function do_transaction( $order, $transient_token, $is_save_card ) {
		try {
			return $this->do_uc_transaction( $order, $transient_token, $is_save_card );
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to initiates UC payment transaction', true );
		}
	}

	/**
	 * Handles Unified Checkout payment transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient Token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function do_uc_transaction( $order, $transient_token, $is_save_card ) {
		$settings        = $this->gateway->get_gateway_settings();
		$is_echeck       = $this->is_echeck_from_transient_token( $transient_token );
		$payment_methods = new Visa_Acceptance_Payment_Methods( $this->gateway );
		$auth_response   = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$request         = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions   = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$refund          = new Visa_Acceptance_Refund( $this->gateway );

		$return_response[ VISA_ACCEPTANCE_SUCCESS ] = null;
		$return_response[ VISA_ACCEPTANCE_ERROR ]   = null;

		// Check if this is a CUP or JAYWAN card for free trial (zero amount order).
		$is_zero_amount_order         = ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() );
		$unsupported_zero_amount_card = $is_zero_amount_order && $payment_methods->unsupported_zero_amount_card( $transient_token );
		$is_zero_amount_echeck        = $is_zero_amount_order && $is_echeck;

		$payment_response = $this->get_uc_payment_response( $order, $transient_token, $is_save_card );

		// Handle case where payment response is not valid.
		if ( ! is_array( $payment_response ) || empty( $payment_response ) ) {
			$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_STRING_EMPTY );
			$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
			$return_response[ VISA_ACCEPTANCE_ERROR ]   = VISA_ACCEPTANCE_TIMEOUT_ERROR;
			return $return_response;
		}

		$http_code    = $payment_response['http_code'];
		// Ensure body is JSON string for get_payment_response_array.
		$payment_body = is_array( $payment_response['body'] ) ? wp_json_encode( $payment_response['body'] ) : $payment_response['body'];

		$payment_response_array = $this->get_payment_response_array(
			$http_code,
			$payment_body,
			$is_echeck ? VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS : VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED
		);
		$status          = $payment_response_array['status'];
		$is_echeck_valid = $is_echeck && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED ), true );
		$is_dm_review    = (
			VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $status ||
			VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $status
		);

		// Helper: save subscription token when applicable.
		$maybe_save_subscription_token = function ( $token_value ) use ( $subscriptions, $order ) {
			if ( $this->gateway->is_subscriptions_activated
				&& ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) )
			) {
				$subscriptions->update_order_subscription_token( $order, $token_value );
			}
		};

		try {
			if ( ! $auth_response->is_transaction_approved( $payment_response, $status ) ) {
				$return_response = $request->get_error_message( $payment_response_array, $order );
			} elseif ( $is_dm_review ) {
				$order->update_meta_data( '_vas_payment_type', $is_echeck ? VISA_ACCEPTANCE_CHECK : VISA_ACCEPTANCE_PAYMENT_TYPE_CARD );
				$order->save();

				// Save payment method if requested (for both card and eCheck DM review).
				if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
					$response = $this->save_payment_method( $payment_response, $order, $is_echeck );
					if ( $response['status'] && isset( $response['token'] ) ) {
						$maybe_save_subscription_token( $response['token'] );
					}
				}

				// Store whether this was a charge or auth transaction for later status update.
				$is_charge_transaction = $is_echeck || ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) ) );
				$this->update_order_meta( $order, '_dm_review_is_charge', $is_charge_transaction ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO );
				$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
				$this->add_review_transaction_data( $order, $payment_response_array );
				$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING );
				$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;

			} elseif ( $is_echeck_valid || $auth_response->is_transaction_status_approved( $status ) ) {
				// Save payment method for cards (AUTHORIZED) or eCheck (PENDING/TRANSMITTED).
				$should_save_token = VISA_ACCEPTANCE_YES === $is_save_card && (
					VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ||
					( $is_echeck && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED ), true ) )
				);
				if ( $should_save_token ) {
					$response = $this->save_payment_method( $payment_response, $order, $is_echeck );
					if ( $response['status'] && isset( $response['token'] ) ) {
						$maybe_save_subscription_token( $response['token'] );
					}
				}

				$is_charge_transaction = $is_echeck || ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $settings['transaction_type'] || $request->check_virtual_order_enabled( $settings, $order ) ) );
				$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
				$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );

				if ( $is_echeck_valid ) {
					$order->update_meta_data( '_vas_payment_type', VISA_ACCEPTANCE_CHECK );
					$order->save();
					$this->add_capture_data( $order, $payment_response_array );
					$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
				} elseif ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
					$order->update_meta_data( '_vas_payment_type', VISA_ACCEPTANCE_PAYMENT_TYPE_CARD );
					$order->save();

					if ( $is_charge_transaction ) {
						$this->add_capture_data( $order, $payment_response_array );
						$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
					} else {
						$this->add_transaction_data( $order, $payment_response_array );
						if ( $order->get_total() === VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT ) {
							$this->update_order_notes( VISA_ACCEPTANCE_CHARGE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING );
						} else {
							$this->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
						}
					}
				}

				// Execute automatic authorization reversal for CUP/JAYWAN cards on free trial orders.
				if ( $unsupported_zero_amount_card && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
					$code = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getClientReferenceInformation' ) && $payment_response['body']->getClientReferenceInformation() )
						? $payment_response['body']->getClientReferenceInformation()->getCode()
						: ( $payment_response_array['client_reference_code'] ?? wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) );
					$payment_methods->process_card_auth_reversal( $payment_response, $code, $order );
				}

				// Execute automatic refund for zero-amount eCheck orders.
				if ( $is_zero_amount_echeck && $is_echeck_valid ) {
					$txn_id = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getId' ) ) ? $payment_response['body']->getId() : null;
					if ( $txn_id ) {
						$ref_code = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getClientReferenceInformation' ) && $payment_response['body']->getClientReferenceInformation() )
							? $payment_response['body']->getClientReferenceInformation()->getCode()
							: null;
						$refund->get_refund_response( null, VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT, $txn_id, true, $ref_code );
					}
				}

				$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;

			} else {
				$this->add_transaction_data( $order, $payment_response_array );
				$this->update_order_notes( VISA_ACCEPTANCE_AUTH_REJECT, $order, $payment_response_array, null );
				$this->update_order_notes( VISA_ACCEPTANCE_REJECT_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED );
				if ( ! $is_echeck && ! $request->auth_reversal_exists( $order, $payment_response_array ) ) {
					$request->do_auth_reversal( $order, $payment_response_array );
				}
				$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
			}

			return $return_response;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles UC payment transaction', true );
		}
	}

	/**
	 * Generate payment response payload for Unified Checkout transaction
	 *
	 * @param \WC_Order $order order object.
	 * @param string    $transient_token Transient Token.
	 * @param string    $is_save_card Represents yes/no.
	 *
	 * @return array
	 */
	public function get_uc_payment_response( $order, $transient_token, $is_save_card ) {
		$gateway_settings = $this->gateway->get_gateway_settings();
		$is_echeck_payment = $this->is_echeck_from_transient_token( $transient_token );

		$log_header = ( $is_echeck_payment || VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $gateway_settings['transaction_type'] )
			? ucfirst( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE )
			: VISA_ACCEPTANCE_AUTHORIZATION;
		
		$request          = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$api_client       = $request->get_api_client();
		$payments_api     = new PaymentsApi( $api_client );
		$payment_methods  = new Visa_Acceptance_Payment_Methods($this->gateway);

		// For zero-amount (free trial) orders, temporarily override get_total() while building the API payload:
		// CUP/JAYWAN cards → $1.00 auth (reversed after); eCheck → $0.01 charge (refunded after).
		$is_zero_amount_order         = ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() );
		$unsupported_zero_amount_card = $is_zero_amount_order && $payment_methods->unsupported_zero_amount_card( $transient_token );

		if ( $is_zero_amount_order && ( $unsupported_zero_amount_card || $is_echeck_payment ) ) {
			$override_amount = $unsupported_zero_amount_card ? VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT : VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
			add_filter( 'woocommerce_order_get_total', function ( $total, $filter_order ) use ( $order, $override_amount ) {
				return $filter_order->get_id() === $order->get_id() ? $override_amount : $total;
			}, VISA_ACCEPTANCE_VAL_TEN, VISA_ACCEPTANCE_VAL_TWO );
		}

		// Build the payload using CyberSource SDK models.
		$processing_information_data = $request->get_processing_info($order, $gateway_settings, $is_save_card);

		// For CUP/JAYWAN cards on free trial, force authorization-only (no capture) so we can reverse it.
		if ($unsupported_zero_amount_card) {
			$processing_information_data[VISA_ACCEPTANCE_CAPTURE] = false;
		}
		if ( $is_echeck_payment ) {
			$processing_information_data += $this->get_echeck_bank_transfer_options();
		}		
		$processing_information      = new \CyberSource\Model\Ptsv2paymentsProcessingInformation($processing_information_data);

		$payment_request_data = array(
			'clientReferenceInformation' => $request->client_reference_information( $order ),
			'processingInformation'      => $processing_information,
			'tokenInformation'           => $request->get_cybersource_token_information( $transient_token ),
			'orderInformation'           => $request->get_payment_order_information( $order ),
			'deviceInformation'          => $request->get_device_information(),
			'buyerInformation'           => $request->get_payment_buyer_information( $order ),
		);
		if ( $is_echeck_payment ) {
			$payment_request_data['paymentInformation'] = $request->get_echeck_payment_information( $order );
		}

		$payment_request = new \CyberSource\Model\CreatePaymentRequest( $payment_request_data );
		
		// Remove the temporary filter after building the request.
		if ( $is_zero_amount_order && ( $unsupported_zero_amount_card || $is_echeck_payment ) ) {
			remove_all_filters( 'woocommerce_order_get_total' );
		}

		if ( VISA_ACCEPTANCE_YES === $is_save_card ) {
			$payment_request = $request->get_action_token_type( $payment_request );
		}
		if ( ! empty( $payment_request ) ) {
			$this->gateway->add_logs_data( $payment_request, true, $log_header );
			try {
				$api_response = $payments_api->createPayment( $payment_request );
				$this->gateway->add_logs_service_response( $api_response[VISA_ACCEPTANCE_VAL_ZERO], $api_response[VISA_ACCEPTANCE_VAL_TWO][VISA_ACCEPTANCE_V_C_CORRELATION_ID], true, $log_header );
				$return_array = array(
					'http_code' => $api_response[VISA_ACCEPTANCE_VAL_ONE],
					'body'      => $api_response[VISA_ACCEPTANCE_VAL_ZERO],
				);
				return $return_array;
			} catch ( \Throwable $e ) {
				$this->gateway->add_logs_header_response( array( $e->getMessage() ), true, $log_header );
			}
		}
	}
}
