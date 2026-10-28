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
require_once __DIR__ . '/../payments/class-visa-acceptance-payment-methods.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-authorization-request.php';
require_once __DIR__ . '/../response/payments/class-visa-acceptance-authorization-response.php';
require_once __DIR__ . '/../request/payments/class-visa-acceptance-payment-adapter.php';
require_once __DIR__ . '/class-visa-acceptance-auth-reversal.php';
require_once __DIR__ . '/../../class-visa-acceptance-payment-gateway-subscriptions.php';
require_once __DIR__ . '/class-visa-acceptance-key-generation.php';

use Pymt_Vas\Dependencies\CyberSource\Api\PaymentsApi;

/**
 * Visa Acceptance Authorization Saved Card Class
 * Handles Authorization requests using saved cards
 */
class Visa_Acceptance_Authorization_Saved_Card extends Visa_Acceptance_Request {

	/**
	 * Gateway object
	 *
	 * @var object $gateway */
	public $gateway;

	/**
	 * AuthorizationSavedCard constructor.
	 *
	 * @param object $gateway gateway.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Get the complete mandate type (EBC configuration) from session or fresh capture context.
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return string Complete mandate type ('CAPTURE', 'PREFER_AUTH', 'AUTH', etc.) or empty string.
     */
    private function get_complete_mandate_type( $order = null ) {
        $complete_mandate_type = VISA_ACCEPTANCE_STRING_EMPTY;
 
        // 1. Try the order meta directly (set during the initial payment for all order types).
        if ( $order instanceof \WC_Abstract_Order && ! empty( $order->get_meta( '_vas_ebc_transaction_type' ) ) ) {
            return (string) $order->get_meta( '_vas_ebc_transaction_type' );
        }
 
        // 2. For renewal orders, walk up to the parent subscription(s) which always carry the type.
        if (
            $order instanceof \WC_Abstract_Order &&
            $this->gateway->is_subscriptions_activated &&
            function_exists( 'wcs_get_subscriptions_for_order' )
        ) {
            $parent_subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
            foreach ( $parent_subscriptions as $subscription ) {
                $type = (string) $subscription->get_meta( '_vas_ebc_transaction_type' );
                if ( ! empty( $type ) ) {
                    return $type;
                }
            }
        }
 
        // 3. Fall back to the WooCommerce session (available during interactive checkout only).
        if ( isset( WC()->session ) ) {
            $gateway_id = $this->gateway->id ?? 'unified_checkout';
            $complete_mandate_type = WC()->session->get( "wc_{$gateway_id}_complete_mandate_type", VISA_ACCEPTANCE_STRING_EMPTY );
        }
        return $complete_mandate_type;
    }

	/**
	 * Initiates saved Credit-card transaction
	 *
	 * @param \WC_Order         $order order.
	 * @param \WC_Payment_Token $token token.
	 * @param boolean           $merchant_initiated merchant initiated transaction.
	 *
	 * @return array|null
	 */
	public function do_transaction( $order, $token, $merchant_initiated = false ) {
        try {
            if ( $token->get_meta( VISA_ACCEPTANCE_ENVIRONMENT ) === $this->gateway->get_environment() ) {
                return $this->do_saved_card_transaction( $order, $token, $merchant_initiated );
            } else {
                $return_response[ VISA_ACCEPTANCE_SUCCESS] = false;
                $return_response[ VISA_ACCEPTANCE_ERROR ]   = VISA_ACCEPTANCE_INVALID_PAYMENT_DETAIL_ERROR;
                return $return_response; // phpcs:ignore WordPress.Security.NonceVerification
            }
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to initiates Saved Credit-card transaction', true );
            $return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
            $return_response[ VISA_ACCEPTANCE_ERROR ]   = VISA_ACCEPTANCE_TIMEOUT_ERROR;
            return $return_response;
        }
    }

	/**
	 * Handles saved Credit-card transaction
	 *
	 * @param \WC_Order         $order order object.
	 * @param \WC_Payment_Token $token token object.
	 * @param boolean           $merchant_initiated merchant initiated transaction.
	 *
	 * @return array
	 */
	public function do_saved_card_transaction( $order, $token, $merchant_initiated ) {
		$settings                                        = $this->gateway->get_config_settings();
		$payment_method                                  = new Visa_Acceptance_Payment_Methods( $this->gateway );
		$auth_response          						 = new Visa_Acceptance_Authorization_Response( $this->gateway );
		$request                						 = new Visa_Acceptance_Payment_Adapter( $this->gateway );
		$subscriptions 									 = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		$return_response[ VISA_ACCEPTANCE_SUCCESS ]      = null;
		$return_response[ VISA_ACCEPTANCE_ERROR ] = null;
		// Check if this is a CUP or JAYWAN stored card for free trial.
		$is_zero_amount_order = ( VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total() );
		$unsupported_zero_amount_card = $is_zero_amount_order && $token && $payment_method->unsupported_zero_amount_saved_card( $token );
		$refund = new Visa_Acceptance_Refund( $this->gateway );

		try {
			if ( $token ) {
				$is_echeck             = ( VISA_ACCEPTANCE_TOKEN_TYPE_ECHECK === $token->get_type() );
				$is_zero_amount_echeck = $is_echeck && $is_zero_amount_order;
				if ( ! $is_echeck && $token instanceof \WC_Payment_Token_CC ) {
					$order->update_meta_data( VISA_ACCEPTANCE_WC_UC_ID . 'card_type', $token->get_card_type() );
					$order->save();
					$order = wc_get_order( $order->get_id() );
				}
                // Set the WooCommerce payment token ID on the order so subscriptions can be linked to the payment method.
                $order->update_meta_data( '_payment_token_id', $token->get_id() );
                $order->save();
				$data                  = $payment_method->build_token_data( $token );
				if ( ! $data ) {
					$return_response[ VISA_ACCEPTANCE_ERROR ] = VISA_ACCEPTANCE_INVALID_DATA;
				} else {
					$payment_response = $this->get_payment_response_saved_card( $order, $data, $merchant_initiated, $token );
					if ( empty( $payment_response ) || ! is_array( $payment_response ) ) {
						$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_STRING_EMPTY );
					} else {
						$http_code    = $payment_response['http_code'];
						$payment_body = $payment_response['body'];

						// Ensure body is JSON string for get_payment_response_array.
						if ( VISA_ACCEPTANCE_FOUR_ZERO_ONE === $http_code || is_array( $payment_body ) || is_object( $payment_body ) ) {
                            // stdClass (from json_decode) has no __toString(), so encode instead of casting.
                            $payment_body = wp_json_encode( $payment_body );
                        }
						$payment_response_array = $this->get_payment_response_array(
							$http_code,
							$payment_body,
							$is_echeck ? VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS : VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED
						);
						$status          = $payment_response_array['status'];
						$is_dm_review    = ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $status || VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $status );
						$is_echeck_valid = $is_echeck && in_array( $status, array( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS, VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED ), true );
						$is_card_valid   = ! $is_echeck && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status;
						$has_subscription = $this->gateway->is_subscriptions_activated && ( wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order ) || wcs_is_subscription( $order ) );
                        
						if ( $is_dm_review ) {
							if ( $has_subscription ) {
								$subscriptions->update_order_subscription_token( $order, $data['token'] );
							}
							$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_MESSAGE, $order, $payment_response_array, null );
							$this->add_review_transaction_data( $order, $payment_response_array );
							$this->update_order_notes( VISA_ACCEPTANCE_REVIEW_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING );
							$return_response[ VISA_ACCEPTANCE_SUCCESS ] = true;
						} elseif ( $auth_response->is_transaction_approved( $payment_response, $status ) ) {
							if ( $is_echeck_valid || $is_card_valid ) {
							// Auth vs capture is controlled entirely by EBC.
							// is always present — use detect_ics_bill() to determine charge vs auth.
							$is_virtual_charge = $request->check_virtual_order_enabled( $settings, $order );
                                $is_charge_transaction = $is_echeck || $is_virtual_charge || $this->detect_ics_bill( $payment_response['body'] ?? null );
                               
                                // Fallback to transaction details so EBC auth/sale config is respected.
                                if ( ! $is_charge_transaction && ! $is_echeck && ! empty( $payment_response_array['transaction_id'] ) ) {
                                    $tx_detail_response = $request->get_transaction_details_sdk( $payment_response_array['transaction_id'] );
                                    $is_charge_transaction = $this->detect_ics_bill( $tx_detail_response['body'] ?? null );
                                }
                                // When EBC is CAPTURE-configured but returns AUTHORIZED (no ics_bill), treat as charge — mirrors public class override.
                                if ( ! $is_charge_transaction && ! $is_echeck && VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE === $this->get_complete_mandate_type( $order ) && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
                                    $is_charge_transaction = true;
                                }
								$transaction_type      = $is_charge_transaction ? VISA_ACCEPTANCE_CHARGE_APPROVED : VISA_ACCEPTANCE_AUTH_APPROVED;
								if ( ! $is_echeck ) {
									$payment_action = $is_charge_transaction ? VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE : VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION;
									$this->update_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE, $payment_action );
								}
								$this->update_order_notes( $transaction_type, $order, $payment_response_array, null );
								if ( $has_subscription ) {
									$subscriptions->update_order_subscription_token( $order, $data['token'] );
								}

								if ( $is_charge_transaction ) {
									$this->add_capture_data( $order, $payment_response_array );
                                    $order->payment_complete( $payment_response_array['transaction_id'] );
                                    $_note_title = method_exists( $this, 'get_title' ) ? $this->get_title() : $this->gateway->get_title();
                                    $order->add_order_note( sprintf( $_note_title . ' - ' . VISA_ACCEPTANCE_CHARGE_TRANSACTION, $payment_response_array['transaction_id'] ) );
								} else {
									$this->add_transaction_data( $order, $payment_response_array );
									$this->update_order_notes( VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION, $order, $payment_response_array, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD );
								}

								// Execute automatic authorization reversal for CUP/JAYWAN stored cards on free trial orders.
								if ( ! $is_echeck && $unsupported_zero_amount_card && VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $status ) {
									$code = ( is_object( $payment_response['body'] ) && method_exists( $payment_response['body'], 'getClientReferenceInformation' ) && $payment_response['body']->getClientReferenceInformation() )
										? $payment_response['body']->getClientReferenceInformation()->getCode()
										: ( $payment_response_array['client_reference_code'] ?? $order->get_id() );
									$payment_method->process_card_auth_reversal( $payment_response, $code, $order );
								}

								// Refund the $0.01 verification charge for zero-amount eCheck free trial orders.
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
								if ( ! $request->auth_reversal_exists( $order, $payment_response_array ) ) {
									$request->do_auth_reversal( $order, $payment_response_array );
								}

								$return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
                                $return_response[ VISA_ACCEPTANCE_ERROR ]   = VISA_ACCEPTANCE_TIMEOUT_ERROR;
                                if ( isset( $payment_response_array['reason'] ) ) {
                                    $return_response['detailed_reason'] = $payment_response_array['reason'];
                                }
							}
						} else {
							$return_response = $request->get_error_message( $payment_response_array, $order );
                            $return_response[ VISA_ACCEPTANCE_SUCCESS ] = false;
                            $return_response[ VISA_ACCEPTANCE_ERROR ]   = VISA_ACCEPTANCE_TIMEOUT_ERROR;
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to handles saved Credit-card transaction', true );
		}

		return $return_response;
	}

	/**
	 * Generate payment response payload for Credit-card transaction.
	 *
	 * @param \WC_Order $order order.
	 * @param array     $token_data saved card token data.
	 * @param boolean   $merchant_initiated merchant initiated transaction.
	 * @param \WC_Payment_Token $token payment token object.
	 */
	public function get_payment_response_saved_card( $order, $token_data, $merchant_initiated, $token = null ) {
        $settings     = $this->gateway->get_config_settings();
        $request      = new Visa_Acceptance_Payment_Adapter( $this->gateway );
        $api_client   = $request->get_api_client();
        $payments_api = new PaymentsApi( $api_client );
        $is_echeck    = isset($token_data['is_echeck']) && $token_data['is_echeck'];
 
        if ( $is_echeck ) {
            $log_header = ucfirst(VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE);
        } else {
            // Check EBC type to determine if this will be a charge (CAPTURE) or auth (PREFER_AUTH).
            $complete_mandate_type = $this->get_complete_mandate_type($order);
            $log_header = ( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE === $complete_mandate_type ) ? ucfirst(VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE) : VISA_ACCEPTANCE_AUTHORIZATION;
        }
 
        // Tag eCheck orders with payment type meta.
        if ( $is_echeck ) {
            $order->update_meta_data('_vas_payment_type', VISA_ACCEPTANCE_CHECK);
            $order->save();
        }
 
        // Determine whether a zero-amount override is needed.
        $is_zero_amount_order         = (VISA_ACCEPTANCE_ZERO_AMOUNT === $order->get_total());
        $payment_method               = new Visa_Acceptance_Payment_Methods($this->gateway);
        $unsupported_zero_amount_card = ! $is_echeck && $is_zero_amount_order && $token && $payment_method->unsupported_zero_amount_saved_card($token);
 
        // Temporarily override order total: CUP/JAYWAN → $1.00, eCheck free-trial → $0.01.
        if ($is_zero_amount_order && ($is_echeck || $unsupported_zero_amount_card)) {
            $override_amount = $is_echeck ? VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT : VISA_ACCEPTANCE_ONE_DOLLAR_AMOUNT;
            add_filter(
                'woocommerce_order_get_total',
                function ($total, $filter_order) use ($order, $override_amount) {
                    return $filter_order->get_id() === $order->get_id() ? $override_amount : $total;
                },
                VISA_ACCEPTANCE_VAL_TEN,
                VISA_ACCEPTANCE_VAL_TWO
            );
        }
 
        // Build processing information.
        $processing_information = $request->get_processing_info($order, $settings, null, null, true, $merchant_initiated);
        if ( $is_echeck ) {
            $processing_information[ VISA_ACCEPTANCE_CAPTURE ] = true;
            $processing_information += $this->get_echeck_bank_transfer_options();
        } elseif ( $unsupported_zero_amount_card ) {
            $processing_information[ VISA_ACCEPTANCE_CAPTURE ] = false;
        } else {
            // Check if capture is already set to true (virtual-order flow).
            $capture_value = $processing_information[ VISA_ACCEPTANCE_CAPTURE ] ?? null;
            if ( is_string( $capture_value ) ) {
                $capture_value = strtolower( trim( $capture_value ) );
                $capture_value = in_array( $capture_value, array( '1', 'true', VISA_ACCEPTANCE_YES, 'on' ), true );
            } else {
                $capture_value = (bool) $capture_value;
            }
 
            if ( true === $capture_value ) {
                // Virtual order: keep capture=true.
                $processing_information[ VISA_ACCEPTANCE_CAPTURE ] = true;
            } else {
                // For normal saved-card payments, check the MID's EBC configuration from capture context.
                if ( ! isset( $complete_mandate_type ) ) {
                    $complete_mandate_type = $this->get_complete_mandate_type($is_zero_amount_order);
                }
                if ( VISA_ACCEPTANCE_COMPLETE_MANDATE_TYPE_CAPTURE === $complete_mandate_type ) {
                    // CAPTURE MID: send capture=true to perform sale/auto-capture for saved cards.
                    $processing_information[ VISA_ACCEPTANCE_CAPTURE ] = true;
                } else {
                    // PREFER_AUTH, AUTH, or unknown: omit capture flag for authorization-only.
                    unset( $processing_information[ VISA_ACCEPTANCE_CAPTURE ] );
                }
            }
        }
       
        $payload_data = array(
            'clientReferenceInformation' => $request->client_reference_information( $order ),
            'processingInformation'      => $processing_information,
            'paymentInformation'         => $request->get_cybersource_payment_information( $token_data, null ),
            'deviceInformation'          => $request->get_device_information(),
            'orderInformation'           => $request->get_payment_order_information( $order ),
            'buyerInformation'           => $request->get_payment_buyer_information( $order ),
        );
 
        // Only include deviceInformation when it has actual content.
        $device_information = $request->get_device_information();
        if ( ! empty( $device_information ) ) {
            $payload_data['deviceInformation'] = $device_information;
        }
 
        // Remove the temporary total override filter now that the payload is built.
        if ($is_zero_amount_order && ($is_echeck || $unsupported_zero_amount_card)) {
            remove_all_filters('woocommerce_order_get_total');
        }
       
        $payload = new \Pymt_Vas\Dependencies\CyberSource\Model\CreatePaymentRequest( $payload_data );
        try {
            list( $response_body, $http_code, $http_header ) = $payments_api->createPayment( $payload );
 
            // Log the incoming payment response as the complete/final transaction result.
            $correlation_id = isset( $http_header['v-c-correlation-id'] ) ? $http_header['v-c-correlation-id'] : '';
            $response_json  = is_object( $response_body ) ? (string) $response_body : wp_json_encode( $response_body );
            $this->gateway->add_logs_data(
                array(
                    'http_code'      => $http_code,
                    'correlation_id' => $correlation_id,
                    'body'           => json_decode( $response_json ),
                ),
                false,
                VISA_ACCEPTANCE_COMPLETE_MANDATE_STRING
            );
 
            // Decrypt MLE response if needed (decrypt_mle_response expects the raw JSON string).
            $payment_adapter    = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $decrypted_response = $this->decrypt_mle_response( $response_json, $payment_adapter, $log_header );
 
            // Log decrypted response only if it was actually decrypted (string content differs).
            if ( $decrypted_response !== $response_json ) {
                $this->gateway->add_logs_service_response(
                    $decrypted_response,
                    $correlation_id,
                    true,
                    VISA_ACCEPTANCE_COMPLETE_MANDATE_STRING
                );
                $response_body = json_decode( $decrypted_response );
            } else {
                $response_body = json_decode( $response_json );
            }
            return array(
                'http_code' => $http_code,
                'body'      => $response_body,
            );
        } catch ( \Pymt_Vas\Dependencies\CyberSource\ApiException $e ) {
            // Log the API exception as a response error.
            $this->gateway->add_logs_data(
                array( 'error' => $e->getMessage(), 'code' => $e->getCode() ),
                false,
                $log_header,
                true
            );
        }
    }
}
