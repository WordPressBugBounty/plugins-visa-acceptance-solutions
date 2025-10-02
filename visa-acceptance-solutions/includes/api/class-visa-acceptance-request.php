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
			'status'            => null,
			'amount'            => null,
			'currency'          => null,
			'transaction_id'    => null,
			'reason'            => null,
			'message'           => null,
			'cardholderMessage' => null,
			'httpcode'          => null,
		);
		$json           = json_decode( $response );
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->status ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['status'] = $json->status;
		}
		//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! ( empty( $json->id ) ) ) {
			//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$response_array['transaction_id'] = $json->id;
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
		if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $http_code ) {
			if ( VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_RISK_DECLINED === $service || VISA_ACCEPTANCE_API_RESPONSE_STATUS_AUTHORIZED_PENDING_REVIEW === $service ) {
				//phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$response_array['amount'] = isset( $json->orderInformation->amountDetails->authorizedAmount ) ? $json->orderInformation->amountDetails->authorizedAmount : null;
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
		// add capture transaction ID.
		if ( $payment_response_array && $payment_response_array['transaction_id'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TRANSACTION_ID, $payment_response_array['transaction_id'] );
			$order->set_transaction_id( $payment_response_array['transaction_id'] );
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
			$this->add_order_meta( $order, VISA_ACCEPTANCE_UNDERSCORE_PAYMENT_METHOD, $order->get_payment_method() );

			$order->set_transaction_id( $payment_response_array['transaction_id'] );
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

			// Added Data for Payment Acceptance Service.
			$settings = $this->gateway->settings;
			$order->set_transaction_id( $payment_response_array['transaction_id'] );
			$this->update_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE, $settings['transaction_type'] );

		}
		// Adding Auth Amount.
		if ( $payment_response_array['amount'] ) {
			$this->update_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT, $payment_response_array['amount'] );
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
			if ( handle_hpos_compatibility() ) {
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
			if ( handle_hpos_compatibility() ) {
				$order->delete_meta_data( VISA_ACCEPTANCE_WC_UC_ID . $key );
				$order->save_meta_data();
			} else {
				delete_post_meta_by_key( VISA_ACCEPTANCE_WC_UC_ID . $key );
			}
		}
		return $order instanceof \WC_Order;
	}

	/**
	 * Gets cybersource billing information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_cybersource_billing_information( $order ) {
		$order_information_bill_to = new \CyberSource\Model\Ptsv2paymentsOrderInformationBillTo(
			array(
				'firstName'          => $order->get_billing_first_name(),
				'lastName'           => $order->get_billing_last_name(),
				'address1'           => $order->get_billing_address_1(),
				'locality'           => $order->get_billing_city(),
				'administrativeArea' => $order->get_billing_state(),
				'postalCode'         => $order->get_billing_postcode(),
				'country'            => $order->get_billing_country(),
				'email'              => $order->get_billing_email(),
				'phoneNumber'        => $order->get_billing_phone(),
			)
		);
		return $order_information_bill_to;
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
	 * Gets cybersource shipping information.
	 *
	 * @param mixed $order  Order.
	 * @return array
	 */
	public function get_cybersource_shipping_information( $order ) {
		$order_information_ship_to = new \CyberSource\Model\Ptsv2paymentsOrderInformationShipTo(
			array(
				'firstName'          => $order->get_shipping_first_name(),
				'lastName'           => $order->get_shipping_last_name(),
				'address1'           => $order->get_shipping_address_1(),
				'address2'           => $order->get_shipping_address_2(),
				'postalCode'         => $order->get_shipping_postcode(),
				'locality'           => $order->get_shipping_city(),
				'administrativeArea' => $order->get_shipping_state(),
				'country'            => $order->get_shipping_country(),
				'phoneNumber'        => $order->get_shipping_phone(),
				'email'              => $order->get_billing_email(),
			)
		);
		return $order_information_ship_to;
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
				if ( (float) $shipping_method->get_total() > 0 ) {
					$items[] = array(
						'productCode' => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
						'productName' => $shipping_method->get_name(),
						'productSku'  => $shipping_method->get_method_id(),
						'unitPrice'   => $shipping_method->get_total(),
						'quantity'    => 1,
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
					'quantity'    => 1,
					'taxAmount'   => $order->get_discount_tax(),
				);
			}

			foreach ( $order->get_fees() as $fee ) {

				$items[] = array(
					'productName' => $fee->get_name(),
					'unitPrice'   => $fee->get_total(),
					'quantity'    => 1,
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
	 * Object properties:
	 *
	 * + id          - item ID
	 * + name        - item name, usually product title, processed through htmlentities()
	 * + description - formatted item meta (e.g. Size: Medium, Color: blue), processed through htmlentities()
	 * + quantity    - item quantity
	 * + item_total  - item total (line total divided by quantity, excluding tax & rounded)
	 * + line_total  - line item total (excluding tax & rounded)
	 * + meta        - formatted item meta array
	 * + product     - item product or null if getting product from item failed
	 * + item        - raw item array
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
		$order_information_amount_details = new \CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails(
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
				$item = new \CyberSource\Model\Ptsv2paymentsOrderInformationLineItems(
					array(
						'productName' => $line_item->item->get_name(),
						'unitPrice'   => $line_item->item_total,
						'quantity'    => $line_item->quantity,
						'taxAmount'   => $line_item->item->get_total_tax(),
					)
				);

				// if visa acceptance have a product object, add the SKU if available.
				if ( $line_item->product instanceof \WC_Product && $line_item->product->get_sku() ) {
					$item['productSku'] = $line_item->product->get_sku();
				}
				$items[] = $item;
			}
			foreach ( $order->get_shipping_methods() as $shipping_method ) {
				if ( (float) $shipping_method->get_total() > 0 ) {
					$items[] = array(
						'productCode' => VISA_ACCEPTANCE_SHIPPING_AND_HANDELING,
						'productName' => $shipping_method->get_name(),
						'productSku'  => $shipping_method->get_method_id(),
						'unitPrice'   => $shipping_method->get_total(),
						'quantity'    => 1,
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
					'quantity'    => 1,
					'taxAmount'   => $order->get_discount_tax(),
				);
			}

			foreach ( $order->get_fees() as $fee ) {

				$items[] = array(
					'productName' => $fee->get_name(),
					'unitPrice'   => $fee->get_total(),
					'quantity'    => 1,
					'taxAmount'   => $fee->get_total_tax(),
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
	 * Truncates a given $text after a given $length if string is longer than
	 * $length. The last characters will be replaced with the $omission string
	 * for a total length not exceeding $length
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

			return mb_substr( $text, 0, $length, VISA_ACCEPTANCE_UTF_8 ) . $omission;

		} else {

			$text = self::str_to_ascii( $text );

			// bail if string doesn't need to be truncated.
			if ( strlen( $text ) <= $length ) {
				return $text;
			}

			$length -= strlen( $omission );

			return substr( $text, 0, $length ) . $omission;
		}
	}

	/**
	 * Returns a string with all non-ASCII characters removed. This is useful
	 * for any string functions that expect only ASCII chars and can't
	 * safely handle UTF-8. Note this only allows ASCII chars in the range
	 * 33-126 (newlines/carriage returns are stripped)
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
}
