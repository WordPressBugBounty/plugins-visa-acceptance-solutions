<?php
/**
 * Trait Visa_Acceptance_Payment_Gateway_Includes_Trait
 * The admin-specific functionality of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *
 * Authorization Approved order note.
 */
/* translators: Placeholders: %1$s - <a> tag*/
define( 'VISA_ACCEPTANCE_AUTH_APPROVED', __( 'Authorization Approved: (Transaction ID %s)', 'visa-acceptance-solutions' ) );

/**
 *
 * Charge Approved order note.
 */
/* translators: Placeholders: %1$s - <a> tag*/
define( 'VISA_ACCEPTANCE_CHARGE_APPROVED', __( 'Charge Approved: (Transaction ID %s).', 'visa-acceptance-solutions' ) );

/**
 *
 * Transaction Authorized order note.
 */
define( 'VISA_ACCEPTANCE_CHARGE_TRANSACTION', __( 'Transaction Authorized and Charged (Charge only transaction).', 'visa-acceptance-solutions' ) );

/**
 *
 * Transaction Authorized order note.
 */
define( 'VISA_ACCEPTANCE_AUTHORIZE_TRANSACTION', __( 'Transaction Authorized (Authorization only transaction).', 'visa-acceptance-solutions' ) );

/**
 *
 * DM Review order note.
 */
define( 'VISA_ACCEPTANCE_REVIEW_MESSAGE', __( 'Order requires manual review in Visa Acceptance Solutions Case Management system.', 'visa-acceptance-solutions' ) );

/**
 *
 * Transaction Held order note.
 */
define( 'VISA_ACCEPTANCE_REVIEW_TRANSACTION', __( 'Transaction held for Review (Authorization only transaction).', 'visa-acceptance-solutions' ) );

/**
 *
 * Authorization Rejected order note.
 */
/* translators: Placeholders: %1$s - <a> tag*/
define( 'VISA_ACCEPTANCE_AUTH_REJECT', __( 'Authorization Rejected by Decision Manager: (Transaction ID %s)', 'visa-acceptance-solutions' ) );

/**
 *
 * Transaction Rejection order note.
 */
define( 'VISA_ACCEPTANCE_REJECT_TRANSACTION', __( 'Transaction Rejected (Authorization only transaction).', 'visa-acceptance-solutions' ) );

/**
 *
 * Void Approved order note.
 */
/* translators: Placeholders: %1$s - <a> tag*/
define( 'VISA_ACCEPTANCE_VOID_APPROVED', __( 'Void Approved with Transaction ID %s', 'visa-acceptance-solutions' ) );

/**
 *
 * Capture String order note.
 */
define( 'VISA_ACCEPTANCE_CAPTURE_OF', __( 'Capture of', 'visa-acceptance-solutions' ) );

/**
 *
 * Approved String order note.
 */
define( 'VISA_ACCEPTANCE_APPROVED_TRANSACTION', __( 'Approved (Transaction ID ', 'visa-acceptance-solutions' ) );

/**
 *
 * Order not captured error message.
 */
define( 'VISA_ACCEPTANCE_ORDER_NOT_CAPTURED', __( 'Failed to capture the order.', 'visa-acceptance-solutions' ) );

/**
 *
 * Invalid Request error message.
 */
define( 'VISA_ACCEPTANCE_INVALID_REQUEST', __( 'Invalid request', 'visa-acceptance-solutions' ) );

/**
 *
 * Invalid Amount error message.
 */
define( 'VISA_ACCEPTANCE_INVALID_AMOUNT_ERROR', __( 'Enter valid amount', 'visa-acceptance-solutions' ) );

/**
 *
 * Refund reason message.
 */
/* translators: Placeholders: %1$s - <a> tag*/
define( 'VISA_ACCEPTANCE_REFUND_MESSAGE', __( 'Refunded because of reason - %1$s.', 'visa-acceptance-solutions' ) );


/**
 * Trait Visa_Acceptance_Payment_Gateway_Includes_Trait
 */
trait Visa_Acceptance_Payment_Gateway_Includes_Trait {

	/**
	 * Gets the token based on token id.
	 *
	 * @param string $token_id token id.
	 * @param string $current_user_id customer user id.
	 */
	public function get_wc_token( $token_id, $current_user_id ) {
		$selected_token = null;
		$core_tokens    = \WC_Payment_Tokens::get_customer_tokens( $current_user_id, $this->gateway->get_id() );
		if ( ! empty( $core_tokens ) ) {
			foreach ( $core_tokens as $token ) {
				$data = $token->get_data();
				if ( ! strcmp( $token_id, $data['token'] ) ) {
					$selected_token = $token;
					break;
				}
			}
		}
		return $selected_token;
	}

	/**
	 * Checks whether the token already exists.
	 *
	 * @param array $payment_response payment response array.
	 *
	 * @return array
	 */
	public function save_payment_method( $payment_response ) {
		$settings        = $this->gateway->get_config_settings();
		$payment_methods = new Visa_Acceptance_Payment_Methods( $this->gateway );
		$customer_data   = $payment_methods->get_order_for_add_payment_method();
		$core_token      = $payment_methods->check_token_exist( $payment_response, $customer_data );
		if ( $core_token ) {
			$return_result = $payment_methods->update_token( $core_token, $payment_response, $settings, $customer_data );
		} else {
			$return_result = $payment_methods->gettokenResponseArray( $payment_response, $customer_data );
		}
		return $return_result;
	}

	/**
	 * Updates the order status if transaction is failed.
	 *
	 * @param WC_Order $order order details.
	 * @param array    $payment_response_array payment response array.
	 *
	 * @return void
	 */
	public function update_failed_order( $order, $payment_response_array ) {
		if ( ! empty( $payment_response_array ) ) {
				/* translators: %s - Transaction ID */
			$failed_message = __( ' Authorization Failed: (Transaction ID %s)', 'visa-acceptance-solutions' );
			$title_value    = method_exists( $this, 'get_title' ) ? $this->get_title() : $this->gateway->get_title();
			$message        = sprintf(
				$title_value . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . $failed_message,
				$payment_response_array['transaction_id']
			);
			$order->add_order_note( $message );
			$failed_message = __( ' Transaction Failed (', 'visa-acceptance-solutions' );
			$message        = $title_value . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . $failed_message . $payment_response_array['message'] . ').';
			$this->add_transaction_data( $order, $payment_response_array );
		} else {
			$failed_message = __( ' Authorization Failed', 'visa-acceptance-solutions' );
			$title_value    = method_exists( $this, 'get_title' ) ? $this->get_title() : $this->gateway->get_title();
			$message        = $title_value . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . $failed_message;
			$order->add_order_note( $message );
		}
		$order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, $message );
	}

	/**
	 * Creates a mock order for adding payment method.
	 *
	 * @return array
	 */
	public function get_order_for_add_payment_method() {

		$user = get_userdata( get_current_user_id() );

		$properties = array(
			'currency'    => get_woocommerce_currency(), // default to base store currency.
			'customer_id' => isset( $user->ID ) ? $user->ID : VISA_ACCEPTANCE_STRING_EMPTY,
		);
		return $properties;
	}

	/**
	 * Updates order meta.
	 *
	 * @param \WC_Order $order order details.
	 * @param string    $key array key.
	 * @param string    $value value.
	 *
	 * @return array
	 */
	public function update_order_meta( $order, $key, $value ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( $order instanceof \WC_Order ) {
			$prefix = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? '_wc_' . VISA_ACCEPTANCE_SV_GATEWAY_ID . '_' : $this->get_order_meta_prefix_includes();
			if ( handle_hpos_compatibility() ) {
				$order->update_meta_data( $prefix . $key, $value );
				$order->save_meta_data();
			} else {
				update_post_meta( $order->get_id(), $prefix . $key, $value );
			}
		}
		return $order instanceof \WC_Order;
	}

	/**
	 * Gets the order meta prefix used for order meta
	 *
	 * @return string
	 */
	public function get_order_meta_prefix_includes() {
		return VISA_ACCEPTANCE_UNDERSCORE . VISA_ACCEPTANCE_WC_UNDERSCORE . $this->gateway->get_id() . VISA_ACCEPTANCE_UNDERSCORE;
	}

	/**
	 * Gets supported features.
	 *
	 * @return array
	 */
	public function get_supported_features() {
		$services_supported = array(
			'products',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);
		return $services_supported;
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return true;
	}

	/**
	 * Order total.
	 *
	 * @param \WC_Order $order order details.
	 *
	 * @return array
	 */
	protected function get_order_totals( \WC_Order $order ) {
		$response = array();
		if ( isset( $order ) ) {
			$response = array(
				'subtotal' => $order->get_subtotal(),
				'discount' => $order->get_discount_total(),
				'shipping' => $order->get_shipping_total(),
				'fees'     => $order->get_total_fees(),
				'taxes'    => $order->get_total_tax(),
			);
		}
		return $response;
	}
}
