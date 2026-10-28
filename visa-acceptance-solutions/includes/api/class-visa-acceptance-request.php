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

require_once plugin_dir_path( __FILE__ ) . '../trait-visa-acceptance-payment-gateway-admin.php';
require_once plugin_dir_path( __FILE__ ) . '../trait-visa-acceptance-payment-gateway-public.php';
require_once plugin_dir_path( __FILE__ ) . '../trait-visa-acceptance-payment-gateway-includes.php';

/**
 *
 * Visa Acceptance Api request Class
 * Handles Api requests
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
abstract class Visa_Acceptance_Request {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * Gateway object.
	 *
	 * @var object
	 */
	public $gateway;

	/**
	 * Service object.
	 *
	 * @var object
	 */
	public $service;

	/**
	 * Order object
	 *
	 * @var \WC_Order order associated with the request, if any
	 * */
	protected $order;


	/**
	 * Request Constructor
	 *
	 * @param object $gateway gateway object.
	 *
	 * @return void
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

		/**
		 * Converts the transaction response to array.
		 *
		 * @param int    $http_code http code.
		 * @param string $response response.
		 * @param string $service service.
		 *
		 * @return array
		 */
	public function get_payment_response_array( $http_code, $response, $service ) {
		$response_array = array(
			'status'             					=> null,
			'amount'            					=> null,
			'currency'          					=> null,
			'credit_auth_code'   					=> null,
			'credit_auth_response' 					=> null,
			'credit_auth_network_transaction_id' 	=> null,
			'transaction_id'    					=> null,
			'request_id'                            => null,
			'processor_transaction_id'              => null,
			'merchant_ref_code'                     => null,
			'reason'            					=> null,
			'message'           					=> null,
			'cardholderMessage' 					=> null,
			'httpcode'          					=> null,
			'reconciliation_id'                     => null,
			'submit_time'                           => null,
			'auth_method'                            => null,
		);
		$response_str   = is_string( $response ) ? $response : (string) $response;
        $json           = json_decode( $response_str );
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->status ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['status'] = $json->status;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->id ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['transaction_id'] = $json->id;
			$response_array['request_id']     = $json->id;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->clientReferenceInformation->code ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['merchant_ref_code'] = $json->clientReferenceInformation->code;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        if ( ! ( empty( $json->reconciliationId ) ) ) {
            //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            $response_array['reconciliation_id'] = $json->reconciliationId;
        }
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->submitTimeUtc ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['submit_time'] = $json->submitTimeUtc;
		}
		// Refund response fields added for online refunds.
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! empty( $json->processorInformation ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! empty( $json->processorInformation->approvalCode ) ) {
				$response_array['credit_auth_code'] = $json->processorInformation->approvalCode; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( isset( $json->processorInformation->responseCode ) ) {
				$response_array['credit_auth_response'] = $json->processorInformation->responseCode; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! empty( $json->processorInformation->networkTransactionId ) ) {
				$response_array['credit_auth_network_transaction_id'] = $json->processorInformation->networkTransactionId; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! empty( $json->processorInformation->transactionId ) ) {
				$response_array['processor_transaction_id'] = $json->processorInformation->transactionId; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->errorInformation->reason ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['reason'] = $json->errorInformation->reason;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->errorInformation->message ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['message'] = $json->errorInformation->message;
		}
		if ( ! ( empty( $http_code ) ) ) {
			$response_array['httpcode'] = $http_code;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->consumerAuthenticationInformation->cardholderMessage ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['cardholderMessage'] = $json->consumerAuthenticationInformation->cardholderMessage;
		}
		// Build auth method label (ECI + authentication indicator) when 3DS/consumer auth data is present.
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! empty( $json->consumerAuthenticationInformation->eci ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$auth_method = 'ECI ' . $json->consumerAuthenticationInformation->eci;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! empty( $json->consumerAuthenticationInformation->authenticationResult ) ) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$auth_method .= ' (' . $json->consumerAuthenticationInformation->authenticationResult . ')';
			}
			$response_array['auth_method'] = $auth_method;
		}
		if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $http_code ) {
			if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_RISK_DECLINED === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $service || VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS === $service || VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED === $service ) {
				$is_echeck_service = ( VISA_ACCEPTANCE_API_RESPONSE_ECHECK_STATUS === $service || VISA_ACCEPTANCE_API_RESPONSE_ECHECK_DM_STATUS === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_TRANSMITTED === $service );
				if ( $is_echeck_service ) {
					//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$response_array['amount'] = isset( $json->orderInformation->amountDetails->totalAmount ) ? $json->orderInformation->amountDetails->totalAmount : null;
				} else {
					//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$response_array['amount'] = isset( $json->orderInformation->amountDetails->authorizedAmount ) ? $json->orderInformation->amountDetails->authorizedAmount : null;
				}
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['currency'] = isset( $json->orderInformation->amountDetails->currency ) ? $json->orderInformation->amountDetails->currency : null;
			}
			if ( VISA_ACCEPTANCE_CAPTURE === $service ) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['amount'] = isset( $json->orderInformation->amountDetails->totalAmount ) ? $json->orderInformation->amountDetails->totalAmount : null;
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['currency'] = isset( $json->orderInformation->amountDetails->currency ) ? $json->orderInformation->amountDetails->currency : null;
			}
			if ( VISA_ACCEPTANCE_AUTH_REVERSAL === $service ) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['amount'] = isset( $json->reversalAmountDetails->reversedAmount ) ? $json->reversalAmountDetails->reversedAmount : null;
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['currency'] = isset( $json->reversalAmountDetails->currency ) ? $json->reversalAmountDetails->currency : null;
			}
			if ( ( VISA_ACCEPTANCE_REFUND === $service )
				&& ( strtoupper( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ) === $response_array['status'] )
			) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['amount'] = isset( $json->refundAmountDetails->refundAmount ) ? $json->refundAmountDetails->refundAmount : null;
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['currency'] = isset( $json->refundAmountDetails->currency ) ? $json->refundAmountDetails->currency : null;
			}
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} elseif ( empty( $json->errorInformation ) ) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['reason'] = isset( $json->reason ) ? $json->reason : null;
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['message'] = isset( $json->message ) ? $json->message : null;
		} else {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['reason'] = isset( $json->errorInformation->reason ) ? $json->errorInformation->reason : null;
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['message'] = isset( $json->errorInformation->message ) ? $json->errorInformation->message : null;
		}
		return $response_array;
	}

	/**
	 * Adds capture data to the order.
	 *
	 * @param object $order order object.
	 * @param array  $payment_response_array transaction response.
	 *
	 * @return void
	 */
	public function add_capture_data( $order, $payment_response_array ) {
        $total_captured = $order->get_total();
        $this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL, $total_captured );
        $this->update_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED, VISA_ACCEPTANCE_YES );
        $this->add_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_METHOD, $order->get_payment_method() );
        // Persist the original merchant reference code so later capture/refund/reversal calls reuse it instead of the order ID.
        if ( ! empty( $payment_response_array['merchant_ref_code'] ) && VISA_ACCEPTANCE_STRING_EMPTY === (string) $this->get_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE ) ) {
            $this->add_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE, $payment_response_array['merchant_ref_code'], true );
        }
        // add capture transaction ID.
        if ( $payment_response_array && $payment_response_array['transaction_id'] ) {
            $this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $payment_response_array['transaction_id'] );
            $order->set_transaction_id( $payment_response_array['transaction_id'] );
            if ( ! empty( $payment_response_array['credit_auth_code'] ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_CODE, $payment_response_array['credit_auth_code'] );
            }
            if ( isset( $payment_response_array['credit_auth_response'] ) && VISA_ACCEPTANCE_STRING_EMPTY !== $payment_response_array['credit_auth_response'] ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_RESPONSE, $payment_response_array['credit_auth_response'] );
            }
            if ( ! empty( $payment_response_array['credit_auth_network_transaction_id'] ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID, $payment_response_array['credit_auth_network_transaction_id'] );
            }
        }
    }

	/**
	 * Adds transaction data to the order.
	 *
	 * @param object $order order object.
	 * @param mixed  $payment_response_array payment response array.
	 *
	 * @return void
	 */
	public function add_transaction_data( $order, $payment_response_array ) {
        if ( $payment_response_array['transaction_id'] ) {
            $this->update_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID, $payment_response_array['transaction_id'] );
            $this->add_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_METHOD, $order->get_payment_method() );            // Persist the original merchant reference code so later capture/refund/reversal calls reuse it instead of the order ID.
            if ( ! empty( $payment_response_array['merchant_ref_code'] ) && VISA_ACCEPTANCE_STRING_EMPTY === (string) $this->get_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE, $payment_response_array['merchant_ref_code'], true );
            } 
            $order->set_transaction_id( $payment_response_array['transaction_id'] );
            // Add credit authorization fields.
            if ( ! empty( $payment_response_array['credit_auth_code'] ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_CODE, $payment_response_array['credit_auth_code'] );
            }
            if ( isset( $payment_response_array['credit_auth_response'] ) && VISA_ACCEPTANCE_STRING_EMPTY !== $payment_response_array['credit_auth_response'] ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_CREDIT_AUTH_RESPONSE, $payment_response_array['credit_auth_response'] );
            }
            if ( ! empty( $payment_response_array['credit_auth_network_transaction_id'] ) ) {
                $this->add_order_meta( $order, VISA_ACCEPTANCE_NETWORK_TRANSACTION_ID, $payment_response_array['credit_auth_network_transaction_id'] );
            }
        }
 
        // Adding Auth Amount.
        if ( $payment_response_array['amount'] ) {
            $this->update_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT, $payment_response_array['amount'] );
        }
        // transaction date.
        $this->update_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_DATE, current_time( 'mysql' ) );
    }
 

	/**
	 * Adds transaction data to the order after the transaction is reviewed.
	 *
	 * @param object $order order object.
	 * @param mixed  $payment_response_array payment response array.
	 *
	 * @return void
	 */
	public function add_review_transaction_data( $order, $payment_response_array ) {
		if ( $payment_response_array['transaction_id'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID, $payment_response_array['transaction_id'] );
			$this->add_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_METHOD, $order->get_payment_method() );
			$this->update_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_STATUS, $payment_response_array['status'] );

			// Added Data for Payment Acceptance Service (deprecated: auth vs charge now determined from response).
			$order->set_transaction_id( $payment_response_array['transaction_id'] );

		}
		// Persist the original merchant reference code so later capture/refund/reversal calls reuse it instead of the order ID.
		if ( ! empty( $payment_response_array['merchant_ref_code'] ) && VISA_ACCEPTANCE_STRING_EMPTY === (string) $this->get_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE ) ) {
			$this->add_order_meta( $order, VISA_ACCEPTANCE_MERCHANT_REF_CODE, $payment_response_array['merchant_ref_code'], true );
		}
		$amount = $payment_response_array['amount'] ? $payment_response_array['amount'] : $order->get_total();
		if ( $amount ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT, $amount );
		}
		// transaction date.
		$this->update_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_DATE, current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Adds order meta data
	 *
	 * @param object $order order object.
	 * @param string $key meta key.
	 * @param string $value meta value.
	 * @param bool   $unique indicates whether the meta value should be unique.
	 *
	 * @return boolean
	 */
	public function add_order_meta( $order, $key, $value, $unique = false ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( $order instanceof \WC_Order ) {
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$order->add_meta_data( VISA_ACCEPTANCE_WC_UC_ID . $key, $value, $unique );
				$order->save_meta_data();
			} else {
				add_post_meta( $order->get_id(), VISA_ACCEPTANCE_WC_UC_ID . $key, $value );
			}
		}
		return $order instanceof \WC_Order;
	}

	/**
	 * Delete order meta data
	 *
	 * @param object $order order object.
	 * @param string $key meta key.
	 *
	 * @return boolean
	 */
	public function delete_order_meta( $order, $key ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( $order instanceof \WC_Order ) {
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$order->delete_meta_data( VISA_ACCEPTANCE_WC_UC_ID . $key );
				$order->save_meta_data();
			} else {
				delete_post_meta( $order->get_id(), VISA_ACCEPTANCE_WC_UC_ID . $key );
			}
		}
		return $order instanceof \WC_Order;
	}

	/**
	 * Gets cybersource billing information.
	 *
	 * @param mixed $order Order object.
	 * @return array
	 */
	public function get_cybersource_billing_information( $order ) {
		$country = $order->get_billing_country();
		$state   = $order->get_billing_state();

		$bill_to = array(
			'firstName'   => $order->get_billing_first_name(),
			'lastName'    => $order->get_billing_last_name(),
			'address1'    => $order->get_billing_address_1(),
			'locality'    => $order->get_billing_city(),
			'postalCode'  => $order->get_billing_postcode(),
			'country'     => $country,
			'email'       => $order->get_billing_email(),
			'phoneNumber' => $order->get_billing_phone(),
		);

		if ( in_array( $country, array( 'US', 'CA', 'CN' ), true ) ) {
			$bill_to['administrativeArea'] = $state;
		}

		return new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationBillTo( $bill_to );
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
			// Filter out empty values to avoid sending invalid data.
			$bill_to_information = array_filter( $bill_to_information, function( $v ) { return ! empty( $v ); } );
			return $bill_to_information;
	}

	/**
	 * Gets cybersource shipping information.
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_cybersource_shipping_information( $order ) {
		$country = $order->get_shipping_country();
		$state   = $order->get_shipping_state();

		$ship_to = array(
			'firstName'   => $order->get_shipping_first_name(),
			'lastName'    => $order->get_shipping_last_name(),
			'address1'    => $order->get_shipping_address_1(),
			'address2'    => $order->get_shipping_address_2(),
			'postalCode'  => $order->get_shipping_postcode(),
			'locality'    => $order->get_shipping_city(),
			'country'     => $country,
			'phoneNumber' => $order->get_shipping_phone(),
			'email'       => $order->get_billing_email(),
		);

		if ( in_array( $country, array( 'US', 'CA', 'CN' ), true ) ) {
			$ship_to['administrativeArea'] = $state;
		}

		return new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationShipTo( $ship_to );
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
	 * Get amount information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_amount_information( $order ) {
		$amount_information = array(
			'totalAmount' => $order->get_total(),
			'currency'    => $order->get_currency(),
		);
		return $amount_information;
	}

	/**
	 * Gets items information.
	 *
	 * @param object $order order details.
	 *
	 * @return array
	 */
	protected function get_items_information( $order ) {
		$items = array();
		if ( $order ) {

			foreach ( $this->get_order_line_items( $order ) as $line_item ) {

				$item = array(
					'productName' => $line_item->item->get_name(),
					'unitPrice'   => $line_item->item_total,
					'quantity'    => $line_item->quantity,
					'taxAmount'   => $line_item->item->get_total_tax(),
				);

				// if visa acceptance have a product object, add the SKU if available.
				if ( $line_item->product instanceof \WC_Product && $line_item->product->get_sku() ) {
					$item['productSku'] = $line_item->product->get_sku();
				}

				$items[] = $item;
			}

			foreach ( $order->get_shipping_methods() as $shipping_method ) {
				if ( (float) $shipping_method->get_total() > VISA_ACCEPTANCE_VAL_ZERO ) {
					$items[] = array(
						'productCode' => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
						'productName' => $shipping_method->get_name(),
						'productSku'  => $shipping_method->get_method_id(),
						'unitPrice'   => $shipping_method->get_total(),
						'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
						'taxAmount'   => $shipping_method->get_total_tax(),
					);
				}
			}
			if ( ! empty( $order->get_coupons() ) ) {
				$items[] = array(
					'productCode' => 'coupon',
					'productName' => 'voucher',
					'productSku'  => 'voucher',
					'unitPrice'   => $order->get_discount_total(),
					'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
					'taxAmount'   => $order->get_discount_tax(),
				);
			}

			foreach ( $order->get_fees() as $fee ) {

				$items[] = array(
					'productName' => $fee->get_name(),
					'unitPrice'   => $fee->get_total(),
					'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
					'taxAmount'   => $fee->get_total_tax(),
				);
			}
		}

		// sanitize dynamic values: quotes, question marks and other characters could trigger an API error.
		foreach ( $items as $key => $item ) {

			$items[ $key ]['productName'] = $this->sanitize_item_name( $item['productName'] );

			if ( ! empty( $item['productSku'] ) ) {
				$items[ $key ]['productSku'] = $this->sanitize_item_name( $item['productSku'] );
			}
		}

		return $items;
	}

	/**
	 * Gets order line items (products) as an array of objects.
	 *
	 * @param \WC_Order $order order.
	 * @return \stdClass[] array of line item objects.
	 */
	public function get_order_line_items( $order ) {

		$line_items = array();

		foreach ( $order->get_items() as $id => $item ) {

			$line_item = new \stdClass();
			$product   = $item->get_product();
			$name      = $item->get_name();
			$quantity  = $item->get_quantity();
			$sku       = $product instanceof \WC_Product ? $product->get_sku() : VISA_ACCEPTANCE_STRING_EMPTY;
			$item_desc = array();

			// add SKU to description if available.
			if ( ! empty( $sku ) ) {
				$item_desc[] = sprintf( 'SKU: %s', $sku );
			}

			$item_meta = $this->get_item_formatted_meta_data( $item, VISA_ACCEPTANCE_UNDERSCORE, true );

			if ( ! empty( $item_meta ) ) {

				foreach ( $item_meta as $meta ) {

					$item_desc[] = sprintf( '%s: %s', $meta['label'], $meta['value'] );
				}
			}

			$item_desc = implode( ', ', $item_desc );

			$line_item->id          = $id;
			$line_item->name        = htmlentities( $name, ENT_QUOTES, VISA_ACCEPTANCE_UTF_8, false );
			$line_item->description = htmlentities( $item_desc, ENT_QUOTES, VISA_ACCEPTANCE_UTF_8, false );
			$line_item->quantity    = $quantity;
			$line_item->item_total  = isset( $item['recurring_line_total'] ) ? $item['recurring_line_total'] : $order->get_item_total( $item );
			$line_item->line_total  = $order->get_line_total( $item );
			$line_item->meta        = $item_meta;
			$line_item->product     = is_object( $product ) ? $product : null;
			$line_item->item        = $item;

			$line_items[] = $line_item;
		}

		return $line_items;
	}

	/**
	 * Gets the formatted meta data for an order item.
	 *
	 * @param \WC_Order_Item $item order item object.
	 * @param string         $hide_prefix prefix for meta that is considered hidden.
	 * @param bool           $include_all whether to include all meta (attributes, etc...), or just custom fields.
	 * @return array $item_meta {
	 *     @type string $label meta field label
	 *     @type mixed $value meta value
	 * }.
	 */
	public function get_item_formatted_meta_data( $item, $hide_prefix = VISA_ACCEPTANCE_UNDERSCORE, $include_all = false ) {

		if ( $item instanceof \WC_Order_Item && $this->is_wc_version_gte( '3.1' ) ) {

			$meta_data = $item->get_formatted_meta_data( $hide_prefix, $include_all );
			$item_meta = array();

			foreach ( $meta_data as $meta ) {

				$item_meta[] = array(
					'label' => $meta->display_key,
					'value' => $meta->value,
				);
			}
		} else {
			$item_meta = new \WC_Order_Item_Meta( $item );
			$item_meta = $item_meta->get_formatted( $hide_prefix );
		}
		return $item_meta;
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
	 * Gets line item information.
	 *
	 * @param object $order order details.
	 *
	 * @return array
	 */
	public function get_line_items_information( $order ) {
		$items = array();
		if ( $order ) {
			foreach ( $this->get_order_line_items( $order ) as $line_item ) {
				$item = new \Pymt_Vas\Dependencies\CyberSource\Model\Ptsv2paymentsOrderInformationLineItems(
					array(
						'productName' => $line_item->item->get_name(),
						'unitPrice'   => strval( $line_item->item_total ),
						'quantity'    => $line_item->quantity,
						'taxAmount'   => strval( $line_item->item->get_total_tax() ),
					)
				);

				// if visa acceptance have a product object, add the SKU if available.
				if ( $line_item->product instanceof \WC_Product && $line_item->product->get_sku() ) {
					$item['productSku'] = $line_item->product->get_sku();
				}
				$items[] = $item;
			}
			foreach ( $order->get_shipping_methods() as $shipping_method ) {
				if ( (float) $shipping_method->get_total() > VISA_ACCEPTANCE_VAL_ZERO ) {
					$items[] = array(
						'productCode' => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
						'productName' => $shipping_method->get_name(),
						'productSku'  => $shipping_method->get_method_id(),
						'unitPrice'   => strval( $shipping_method->get_total() ),
						'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
						'taxAmount'   => strval( $shipping_method->get_total_tax() ),
					);
				}
			}
			if ( ! empty( $order->get_coupons() ) ) {
				$items[] = array(
					'productCode' => 'coupon',
					'productName' => 'voucher',
					'productSku'  => 'voucher',
					'unitPrice'   => strval( $order->get_discount_total() ),
					'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
					'taxAmount'   => strval( $order->get_discount_tax() ),
				);
			}

			foreach ( $order->get_fees() as $fee ) {

				$items[] = array(
					'productName' => $fee->get_name(),
					'unitPrice'   => strval( $fee->get_total() ),
					'quantity'    => VISA_ACCEPTANCE_VAL_ONE,
					'taxAmount'   => strval( $fee->get_total_tax() ),
				);
			}
		}
		foreach ( $items as $key => $item ) {

			$items[ $key ]['productName'] = $this->sanitize_item_name( $item['productName'] );

			if ( ! empty( $item['productSku'] ) ) {
				$items[ $key ]['productSku'] = $this->sanitize_item_name( $item['productSku'] );
			}
		}
		return $items;
	}

	/**
	 * Sanitizes an item name or SKU for API use.
	 *
	 * @param string $original_name original string.
	 * @return string
	 */
	public function sanitize_item_name( $original_name ) {

		$sanitized_name = $original_name;

		// strip unsupported characters.
		$unsupported_characters = array( VISA_ACCEPTANCE_QUESTION_MARK, '"' );

		foreach ( $unsupported_characters as $character ) {

			$sanitized_name = str_replace( $character, VISA_ACCEPTANCE_STRING_EMPTY, $sanitized_name );
		}

		// convert special characters to HTML entities.
		$sanitized_name = htmlentities( $sanitized_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		// trim down to max 255 characters.
		return $this->str_truncate( trim( $sanitized_name ), 255 );
	}

	/**
	 * Truncates a string to a specified length, taking into account multibyte characters if the mbstring extension is available.
	 *
	 * @param string $text text to truncate.
	 * @param int    $length total desired length of string, including omission.
	 * @param string $omission omission text, defaults to '...'.
	 *
	 * @return string
	 */
	private function str_truncate( $text, $length, $omission = '...' ) {

		if ( extension_loaded( 'mbstring' ) ) {

			// bail if string doesn't need to be truncated.
			if ( mb_strlen( $text, VISA_ACCEPTANCE_UTF_8 ) <= $length ) {
				return $text;
			}

			$length -= mb_strlen( $omission, VISA_ACCEPTANCE_UTF_8 );

			return mb_substr( $text, VISA_ACCEPTANCE_VAL_ZERO, $length, VISA_ACCEPTANCE_UTF_8 ) . $omission;

		} else {

			$text = self::str_to_ascii( $text );

			// bail if string doesn't need to be truncated.
			if ( strlen( $text ) <= $length ) {
				return $text;
			}

			$length -= strlen( $omission );

			return substr( $text, VISA_ACCEPTANCE_VAL_ZERO, $length ) . $omission;
		}
	}

	/**
	 * Converts a string to ASCII by stripping out low and high ASCII characters.
	 *
	 * @param string $text string to make ASCII.
	 * @return string
	 */
	public function str_to_ascii( $text ) {

		// strip ASCII chars 32 and under.
		$text = htmlspecialchars( filter_var( $text, FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		// strip ASCII chars 127 and higher.
		return htmlspecialchars( filter_var( $text, FILTER_DEFAULT, FILTER_FLAG_STRIP_HIGH ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	}

	/**
	 * Determines if the installed version of WooCommerce is equal or greater than a given version.
	 *
	 * @param string $version version number to compare.
	 *
	 * @return bool
	 */
	public function is_wc_version_gte( $version ) {

		$wc_version = defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;

		return $wc_version && version_compare( $wc_version, $version, VISA_ACCEPTANCE_GREATER_THAN_OR_EQUAL_TO );
	}

	/**
	 * Returns the bankTransferOptions array required for eCheck processing information.
	 *
	 * @return array
	 */
	public function get_echeck_bank_transfer_options(): array {
		return array( 'bankTransferOptions' => array( 'secCode' => VISA_ACCEPTANCE_ECHECK_SEC_CODE_WEB ) );
	}

	/**
	 * Gets the client IP address from the server variables.
	 *
	 * @return string Client IP address or empty string if not found.
	 */
	protected function get_client_ip_address() {
		$ip_keys = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( ! empty( $ip ) ) {
					// Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs).
					if ( strpos( $ip, ',' ) !== false ) {
						$ip = trim( explode( ',', $ip )[VISA_ACCEPTANCE_VAL_ZERO] );
					}
					
					// Validate IP address.
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		// Fallback to REMOTE_ADDR even if it's a private IP.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
	}

	/**
	 * Decrypt MLE encrypted response.
	 *
	 * @param string $response_body The response body from API.
	 * @param Visa_Acceptance_Payment_Adapter $payment_adapter Payment adapter instance.
	 * @param string $log_header Log header for debugging.
	 * @return string Decrypted response or original if not encrypted.
	 */
	protected function decrypt_mle_response( $response_body, $payment_adapter, $log_header ) {
		// Check if response is encrypted.
		$decoded = is_string( $response_body ) ? json_decode( $response_body, true ) : null;
		
		if ( ! is_array( $decoded ) || ! isset( $decoded['encryptedResponse'] ) ) {
			return $response_body;
		}

		try {
			// Get merchant configuration with Response MLE settings.
			$merchant_config = $payment_adapter->get_merchant_configuration( false, null );
			
			// Decrypt using MLE Utility.
			$decrypted = \Pymt_Vas\Dependencies\CyberSource\Authentication\Util\MLEUtility::decryptMleResponsePayload(
				$merchant_config[VISA_ACCEPTANCE_VAL_ONE],
				$response_body
			);
			return $decrypted;
		} catch ( \Exception $e ) {
			$this->gateway->add_logs_data( 
				array( 
					'Response MLE Decryption' => 'FAILED',
					'Error Message' => $e->getMessage()
				), 
				true, 
				$log_header 
			);
			return $response_body;
		}
	}
}
