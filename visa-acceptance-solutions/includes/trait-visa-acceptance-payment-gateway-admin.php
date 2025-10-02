<?php
/**
 * Class Visa_Acceptance_Payment_Gateway_Includes_Trait
 * The admin-specific functionality of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Caches\OrderCache;

/**
 * Trait Visa Acceptance Payment Gateway Admin Trait
 */
trait Visa_Acceptance_Payment_Gateway_Admin_Trait {

	/**
	 * This method validates refunded/voided amount and returns boolean to represent whether to show/hide refund button
	 *
	 * @param \WC_Order $order order details.
	 * @return bool
	 */
	public function validate_refund_amount( \WC_Order $order ) {
		// Fetching Total amount.
		$total_amount  = $order->get_total();
		$refund_amount = VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO;

		// Fetching Array with all MetaData objects.
		$order_meta = $order->get_meta_data();

		$transaction_id_exist = false;
		// Looping through the array.
		foreach ( $order_meta as $om ) {
			// Checking if transaction id exists for void/refund.
			if ( ( VISA_ACCEPTANCE_VAL_ZERO === strcmp( $om->get_data()['key'], VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_REFUND_TRANSACTION_ID ) && VISA_ACCEPTANCE_STRING_EMPTY !== $om->get_data()['value'] ) || ( VISA_ACCEPTANCE_VAL_ZERO === strcmp( $om->get_data()['key'], VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_VOID_TRANSACTION_ID ) && VISA_ACCEPTANCE_STRING_EMPTY !== $om->get_data()['value'] ) ) {
				$transaction_id_exist = true;
			}
			// Checking if any key contains void/refund amount.
			if ( ( VISA_ACCEPTANCE_VAL_ZERO === strcmp( $om->get_data()['key'], VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_VOID_AMOUNT ) || VISA_ACCEPTANCE_VAL_ZERO === strcmp( $om->get_data()['key'], VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_REFUND_AMOUNT ) ) && ( $order->has_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED ) || $order->has_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED ) ) ) {
				$refund_amount += (float) $om->get_data()['value'];
			}
		}

		// Return false to Refund Button Visibility.
		return ( $total_amount === $refund_amount && $transaction_id_exist ) ? false : true; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Returns list of environments supported by the gateway
	 *
	 * @return array
	 */
	public function get_environments() {

		// default set of environments consists of 'production'.
		if ( ! isset( $this->environments ) ) {
			$this->environments = array(
				VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION => esc_html_x( 'Production', 'software environment', 'visa-acceptance-solutions' ),
				VISA_ACCEPTANCE_ENVIRONMENT_TEST       => esc_html_x( 'Test', 'software environment', 'visa-acceptance-solutions' ),
			);
		}

		return $this->environments;
	}

	/**
	 * Returns gateway id
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Returns version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Lists an array as text.
	 *
	 * Takes an array and returns a list like "one, two, three, and four"
	 * with a (mandatory) oxford comma.
	 *
	 * @param array       $items items to list.
	 * @param string|null $conjunction coordinating conjunction, like "or" or "and".
	 * @param string      $separator list separator, like a comma.
	 * @return string
	 */
	public static function list_array_items( array $items, $conjunction = null, $separator = VISA_ACCEPTANCE_STRING_EMPTY ) {
		if ( ! is_string( $conjunction ) ) {
			$conjunction = _x( 'and', 'coordinating conjunction for a list of items: a, b, and c', 'visa-acceptance-solutions' );
		}
		// append the conjunction to the last item.
		if ( count( $items ) > VISA_ACCEPTANCE_VAL_ONE ) {
			$last_item = array_pop( $items );
			array_push( $items, trim( "{$conjunction} {$last_item}" ) );
			// only use a comma if needed and no separator was passe.
			if ( count( $items ) < 3 ) {
				$separator = VISA_ACCEPTANCE_SPACE;
			} elseif ( ! is_string( $separator ) || VISA_ACCEPTANCE_STRING_EMPTY === $separator ) {
				$separator = ', ';
			}
		}

		return implode( $separator, $items );
	}

	/**
	 * Insert the given element after the given key in the array.
	 *
	 * Sample usage:
	 *
	 * given
	 *
	 * array( 'item_1' => 'foo', 'item_2' => 'bar' )
	 *
	 * array_insert_after( $array, 'item_1', array( 'item_1.5' => 'w00t' ) )
	 *
	 * becomes
	 *
	 * array( 'item_1' => 'foo', 'item_1.5' => 'w00t', 'item_2' => 'bar' )
	 *
	 * @param array  $main_array main_array to insert the given element into.
	 * @param string $insert_key key to insert given element after.
	 * @param array  $element element to insert into array.
	 * @return array
	 */
	public static function array_insert_after( array $main_array, $insert_key, array $element ) {

		$new_array = array();
		foreach ( $main_array as $key => $value ) {
			$new_array[ $key ] = $value;
			if ( $insert_key === $key ) { // phpcs:ignore WordPress.Security.NonceVerification
				foreach ( $element as $k => $v ) {
					$new_array[ $k ] = $v;
				}
			}
		}

		return $new_array;
	}

	/**
	 * Gets the full URL to the log file for a given $handle.
	 *
	 * @param string $handle log handle.
	 * @return string URL to the WC log file identified by $handle.
	 */
	public static function get_wc_log_file_url( $handle ) {
		return admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', $handle, sanitize_file_name( wp_hash( $handle ) ) ) );
	}

	/**
	 * Adds Capture Button in Admin Page.
	 *
	 * @param object $order order details.
	 *
	 * @return void
	 */
	public function add_capture_button( $order ) {
		$class_value                      = array(
			'button',
			'partial-capture',
			'wc-' . $this->get_id_dasherized() . '-capture',
			'button-primary',
		);
		$tooltip                          = VISA_ACCEPTANCE_STRING_EMPTY;
		$order_data                       = $order->get_data();
		$order_id                         = $order_data['parent_id'];
		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscription_active              = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
		if ( $subscription_active ) {
			if ( ( $order_data['payment_method'] === $this->get_id() || VISA_ACCEPTANCE_SV_GATEWAY_ID === $order_data['payment_method'] ) && true !== wcs_order_contains_subscription( $order_id ) ) {
				if ( $this->is_order_fully_captured( $order ) || ( in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true ) ) ) {
					$class_value[] = 'tips disabled';
					if ( $this->is_order_fully_captured( $order ) ) {
						$tooltip = __( 'This charge has been fully captured.', 'visa-acceptance-solutions' );
					} else {
						$tooltip = __( 'This Order cannot be captured.', 'visa-acceptance-solutions' );
					}
				}
				?>
					<button type="button" id="capture-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>" <?php echo esc_attr( $tooltip ) ? 'data-tip="' . esc_attr( $tooltip ) . '"' : esc_attr( implode( VISA_ACCEPTANCE_STRING_EMPTY, $class_value ) ); ?>><?php esc_html_e( 'Capture Charge', 'visa-acceptance-solutions' ); ?></button>
				<?php
			}
		} elseif ( ( $order_data['payment_method'] === $this->get_id() || VISA_ACCEPTANCE_SV_GATEWAY_ID === $order_data['payment_method'] ) ) {
			if ( $this->is_order_fully_captured( $order ) || ( in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true ) ) ) {
				$class_value[] = 'tips disabled';
				if ( $this->is_order_fully_captured( $order ) ) {
					$tooltip = __( 'This charge has been fully captured.', 'visa-acceptance-solutions' );
				} else {
					$tooltip = __( 'This Order cannot be captured.', 'visa-acceptance-solutions' );
				}
			}
			?>
					<button type="button" id="capture-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>" <?php echo esc_attr( $tooltip ) ? 'data-tip="' . esc_attr( $tooltip ) . '"' : esc_attr( implode( VISA_ACCEPTANCE_STRING_EMPTY, $class_value ) ); ?>><?php esc_html_e( 'Capture Charge', 'visa-acceptance-solutions' ); ?></button>
				<?php

		}
	}

	/**
	 * Converts _ to - for our gateway id.
	 *
	 * @return string
	 */
	public function get_id_dasherized() {
		return str_replace( VISA_ACCEPTANCE_UNDERSCORE, VISA_ACCEPTANCE_HYPHEN, $this->get_id() );
	}

	/**
	 * Converts _ to - for our gateway name id.
	 *
	 * @param string $new_title string value.
	 *
	 * @return string dasherized value.
	 */
	public function get_title_dasherized( $new_title ) {
		return str_replace( VISA_ACCEPTANCE_SPACE, VISA_ACCEPTANCE_HYPHEN, strtolower( $new_title ) );
	}

	/**
	 * Checks whether order is fully capture or not.
	 *
	 * @param object $order oder object.
	 *
	 * @return bool
	 */
	public function is_order_fully_captured( $order ) {
		if ( class_exists( OrderCache::class ) ) {
			$order_cache = wc_get_container()->get( OrderCache::class );
			$order_cache->remove( $order->get_id() );
			$order = wc_get_order( $order->get_id() );
		}
		$captured = $this->get_order_meta( $order, 'charge_captured' );
		return VISA_ACCEPTANCE_YES === $captured ? true : false;
	}

	/**
	 * Provides order meta data.
	 *
	 * @param object $order order details.
	 * @param string $key key.
	 *
	 * @return array
	 */
	public function get_order_meta( $order, $key ) {
		$meta = false;
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( $order instanceof \WC_Order ) {
			$prefix = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? '_wc_' . VISA_ACCEPTANCE_SV_GATEWAY_ID . '_' : VISA_ACCEPTANCE_WC_UC_ID;
			if ( handle_hpos_compatibility() ) {
				$meta = $order->get_meta( $prefix . $key, true, VISA_ACCEPTANCE_EDIT );
			} else {
				$meta = get_post_meta( $order->get_id(), $prefix . $key, true );

			}
		}
		return $meta;
	}

	/**
	 * Handles ajax call for capture sends response data to js.
	 *
	 * @return void
	 */
	public function ajax_process_capture() {
		check_ajax_referer( VISA_ACCEPTANCE_WC_CAPTURE_ACTION, VISA_ACCEPTANCE_NONCE );
		$payment_gateway_uc = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$post_data = $_POST;
		if ( isset( $post_data['order_id'] ) ) {
			$order_id = (int) $post_data['order_id'];
		}
		$order = wc_get_order( $order_id );
		if ( isset( $post_data['gateway_id'] ) ) {
			$gateway_id = sanitize_text_field( wp_unslash( $post_data['gateway_id'] ) );
		}

		$response = array(
			'error'                 => VISA_ACCEPTANCE_VAL_ZERO,
			'message'               => VISA_ACCEPTANCE_STRING_EMPTY,
			VISA_ACCEPTANCE_SUCCESS => VISA_ACCEPTANCE_VAL_ZERO,
		);
		if ( ( VISA_ACCEPTANCE_UC_ID === $gateway_id || VISA_ACCEPTANCE_SV_GATEWAY_ID === $gateway_id ) && false !== $order ) {
			$result_response    = $payment_gateway_uc->init_process_capture( $order_id, $gateway_id );
			if ( ! $result_response[ VISA_ACCEPTANCE_STRING_ERROR ] && ( VISA_ACCEPTANCE_YES === $result_response[ VISA_ACCEPTANCE_SUCCESS ] ) ) {
				$response[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_VAL_ONE;
				$response['message']                 = $result_response['alert_message'];
			} else {
				$response[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_VAL_ZERO;
				$response['message']                 = $result_response['error_message'];
			}
		}
		wp_send_json( $response );
	}

	/**
	 * Checks whether order is captured or not.
	 *
	 * @param object $order order details.
	 */
	public function is_order_captured( $order ) {
		if ( class_exists( OrderCache::class ) ) {
			$order_cache = wc_get_container()->get( OrderCache::class );
			$order_cache->remove( $order->get_id() );
			$order = wc_get_order( $order->get_id() );
		}
		return in_array( $this->get_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED ), array( VISA_ACCEPTANCE_YES, VISA_ACCEPTANCE_PARTIAL ), true );
	}

	/**
	 * Updates order notes and status based on payment response.
	 *
	 * @param string $order_note_message order note.
	 * @param object $order order object.
	 * @param array  $payment_response_array payment response array.
	 * @param string $update_status status to be updated.
	 *
	 * @return void
	 */
	public function update_order_notes( $order_note_message, $order, $payment_response_array, $update_status ) {
		$title_value = method_exists( $this, 'get_title' ) ? $this->get_title() : $this->gateway->get_title();
		$message     = sprintf(
			$title_value . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_HYPHEN . VISA_ACCEPTANCE_SPACE . $order_note_message,
			$payment_response_array['transaction_id']
		);
		if ( $update_status ) {
			$order->update_status( $update_status, $message );
		} else {
			$order->add_order_note( $message );
		}
	}
}
