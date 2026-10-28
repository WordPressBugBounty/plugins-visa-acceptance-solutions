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
			if ( count( $items ) < VISA_ACCEPTANCE_VAL_THREE ) {
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
	 * Checks if any recorded capture for this order lacks a per-item breakdown.
	 *
	 * @param object $order order details.
	 * @return bool True if there is an untracked partial capture, false otherwise.
	 */
	public function has_untracked_partial_capture( $order ) {
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = is_array( $capture_history ) ? $capture_history : array();
		foreach ( $capture_history as $capture ) {
		if ( ! empty( $capture['items_untracked'] ) ) {
		return true;
		}
		}
		return false;
	}

	/**
	 * Checks if partial capture button should be shown for this order.
	 *
	 * @param object $order order details.
	 *
	 * @return bool
	 */
	private function should_show_partial_capture_button( $order ) {
		// Check if order has any items.
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}

		// Load partial capture class if not already loaded.
		if ( ! class_exists( 'Visa_Acceptance_Partial_Capture' ) ) {
			require_once __DIR__ . '/api/payments/class-visa-acceptance-partial-capture.php';
		}
		if ( $this->has_untracked_partial_capture( $order ) ) {
            return false;
        }
		$gateway = property_exists( $this, 'gateway' ) && $this->gateway instanceof \WC_Payment_Gateway ? $this->gateway : $this;
		$partial_capture = new Visa_Acceptance_Partial_Capture( $gateway );
		$remaining_quantities = $partial_capture->get_remaining_quantities( $order );

		// Count items with remaining quantity > 0.
		$items_with_remaining_qty = VISA_ACCEPTANCE_VAL_ZERO;
		$total_remaining_qty = VISA_ACCEPTANCE_VAL_ZERO;

		foreach ( $items as $item_id => $item ) {
			$remaining_qty = $remaining_quantities[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO;
			if ( $remaining_qty > VISA_ACCEPTANCE_VAL_ZERO ) {
				$items_with_remaining_qty++;
				$total_remaining_qty += $remaining_qty;
			}
		}
		if ( VISA_ACCEPTANCE_VAL_ZERO === $items_with_remaining_qty ) {
			// No items to capture.
			return false;
		} elseif ( VISA_ACCEPTANCE_VAL_ONE === $items_with_remaining_qty && VISA_ACCEPTANCE_VAL_ONE === $total_remaining_qty ) {
			// Only 1 item with quantity 1 - no point in partial capture.
			return false;
		}

		return true;
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
					// Only show partial capture button if it makes sense for this order.
					if ( $this->should_show_partial_capture_button( $order ) ) :
						?>
						<button type="button" id="partial-capture-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>" <?php echo esc_attr( $tooltip ) ? 'data-tip="' . esc_attr( $tooltip ) . '"' : esc_attr( implode( VISA_ACCEPTANCE_STRING_EMPTY, $class_value ) ); ?>><?php esc_html_e( 'Partial Capture', 'visa-acceptance-solutions' ); ?></button>
						<?php
					endif;
					?>
				<?php
			}
		} elseif ( ( $order_data['payment_method'] === $this->get_id() || VISA_ACCEPTANCE_SV_GATEWAY_ID === $order_data['payment_method'] ) ) {
			$capture_pending = ( 'pending' === $this->get_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED ) );
			if ( $capture_pending || $this->is_order_fully_captured( $order ) || ( in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true ) ) ) {
				$class_value[] = 'tips disabled';
				if ( $capture_pending ) {
					$tooltip = __( 'A settlement is already pending for this order. Manual capture is disabled to prevent duplicate charges.', 'visa-acceptance-solutions' );
				} elseif ( $this->is_order_fully_captured( $order ) ) {
					$tooltip = __( 'This charge has been fully captured.', 'visa-acceptance-solutions' );
				} else {
					$tooltip = __( 'This Order cannot be captured.', 'visa-acceptance-solutions' );
				}
			}
			?>
					<button type="button" id="capture-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>" <?php echo esc_attr( $tooltip ) ? 'data-tip="' . esc_attr( $tooltip ) . '"' : esc_attr( implode( VISA_ACCEPTANCE_STRING_EMPTY, $class_value ) ); ?>><?php esc_html_e( 'Capture Charge', 'visa-acceptance-solutions' ); ?></button>
					<?php
					// Only show partial capture button if it makes sense for this order.
					if ( $this->should_show_partial_capture_button( $order ) ) :
						?>
						<button type="button" id="partial-capture-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>" <?php echo esc_attr( $tooltip ) ? 'data-tip="' . esc_attr( $tooltip ) . '"' : esc_attr( implode( VISA_ACCEPTANCE_STRING_EMPTY, $class_value ) ); ?>><?php esc_html_e( 'Partial Capture', 'visa-acceptance-solutions' ); ?></button>
						<?php
					endif;
					?>
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
	 * Retrieves the context for partial capture and refund calculations for a given order.
	 *
	 * @param \WC_Order $order              order object.
	 * @param float     $pending_refund_amount Amount of a refund currently being processed.
	 * @return array 	Context for partial capture and refund calculations.
	 */
	public function get_partial_capture_refund_context( $order, $pending_refund_amount = VISA_ACCEPTANCE_VAL_ZERO ) {
		// Force a fresh read; the order object cache can still hold the pre-capture meta
		// immediately after a partial capture is saved.
		if ( class_exists( OrderCache::class ) ) {
			$order_cache = wc_get_container()->get( OrderCache::class );
			$order_cache->remove( $order->get_id() );
			$order = wc_get_order( $order->get_id() );
		}
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = is_array( $capture_history ) ? $capture_history : array();

		$total_captured      = array_sum( array_column( $capture_history, 'amount' ) );
		$capture_total_meta  = (float) $this->get_order_meta( $order, VISA_ACCEPTANCE_CAPTURE_TOTAL );

		// 'capture_total' is updated on every capture independently of the history array,
		// so trust whichever source reports the higher (more complete) captured amount.
		if ( $capture_total_meta > $total_captured ) {
			$total_captured = $capture_total_meta;
		}

		// Legacy single/full capture that never went through the partial-capture flow at all.
		if ( VISA_ACCEPTANCE_VAL_ZERO === $total_captured && $this->is_order_fully_captured( $order ) ) {
			$total_captured = (float) $order->get_total();
		}

		$authorized_amount_meta = $this->get_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT );
		$authorized_amount      = $authorized_amount_meta ? (float) $authorized_amount_meta : (float) $order->get_total();

		$already_refunded = (float) $order->get_total_refunded();
		// Adjust the already refunded amount to exclude the current pending refund request.
		$already_refunded     = max( VISA_ACCEPTANCE_VAL_ZERO, $already_refunded - (float) $pending_refund_amount );
		$remaining_captured   = round( max( VISA_ACCEPTANCE_VAL_ZERO, $total_captured - $already_refunded ), VISA_ACCEPTANCE_VAL_TWO );
		$remaining_authorized = round( max( VISA_ACCEPTANCE_VAL_ZERO, $authorized_amount - $total_captured ), VISA_ACCEPTANCE_VAL_TWO );

		return array(
			'total_captured'       => round( $total_captured, VISA_ACCEPTANCE_VAL_TWO ),
			'already_refunded'     => round( $already_refunded, VISA_ACCEPTANCE_VAL_TWO ),
			'remaining_captured'   => $remaining_captured,
			'authorized_amount'    => round( $authorized_amount, VISA_ACCEPTANCE_VAL_TWO ),
			'remaining_authorized' => $remaining_authorized,
			'has_partial_history'  => ! empty( $capture_history ),
		);
	}

	/**
	 * Builds the list of capture transactions a refund must be split across for an
	 * APM (e.g. PayPal) order. Unlike card processing - where CyberSource nets a
	 * refund against the whole payment chain regardless of which capture ID is
	 * referenced - APM refunds are validated by the processor against each capture
	 * transaction's own remaining (not-yet-refunded) balance. Refunds are always
	 * requested as a plain dollar amount (WooCommerce core collapses any line-item/
	 * quantity selection in the refund UI down to a single amount before calling the
	 * gateway), so allocation here only ever needs to reason about dollars, not
	 * quantities - captures are consumed oldest first.
	 *
	 * @param \WC_Order $order  order object.
	 * @param float     $amount requested refund amount.
	 *
	 * @return array|false List of ['transaction_id' => string, 'amount' => string]
	 *                      entries that sum to $amount; false if there is no capture
	 *                      history, or the combined remaining balance across all
	 *                      captures still can't cover the requested amount.
	 */
	public function get_apm_refund_allocations( $order, $amount ) {
		if ( class_exists( OrderCache::class ) ) {
			$order_cache = wc_get_container()->get( OrderCache::class );
			$order_cache->remove( $order->get_id() );
			$order = wc_get_order( $order->get_id() );
		}
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = is_array( $capture_history ) ? $capture_history : array();
		if ( empty( $capture_history ) ) {

			$transaction_id = $order->get_meta(
				'_wc_visa_acceptance_solutions_unified_checkout_capture_transaction_id',
				true
			);
			$capture_total = $order->get_meta(
				'_wc_visa_acceptance_solutions_unified_checkout_capture_total',
				true
			);
			if (in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED ), true ) && ! empty( $transaction_id ) && (float) $capture_total > VISA_ACCEPTANCE_VAL_ZERO ) {

				$capture_history = array(
					array(
						'transaction_id' => $transaction_id,
						'amount'         => $capture_total,
						'refunded'       => VISA_ACCEPTANCE_VAL_ZERO,
					),
				);
			} else {
				return false;
			}
		}

		$amount_epsilon        = 0.01;
		$remaining_to_allocate = round( (float) $amount, VISA_ACCEPTANCE_VAL_TWO );
		$allocations           = array();

		foreach ( $capture_history as $capture ) {
			if ( $remaining_to_allocate <= $amount_epsilon ) {
				break;
			}
			if ( empty( $capture['transaction_id'] ) ) {
				continue;
			}
			$captured_amount = round( (float) ( $capture['amount'] ?? VISA_ACCEPTANCE_VAL_ZERO ), VISA_ACCEPTANCE_VAL_TWO );
			$refunded_amount = round( (float) ( $capture['refunded'] ?? VISA_ACCEPTANCE_VAL_ZERO ), VISA_ACCEPTANCE_VAL_TWO );
			$available       = round( $captured_amount - $refunded_amount, VISA_ACCEPTANCE_VAL_TWO );
			if ( $available <= $amount_epsilon ) {
				continue;
			}
			$take                   = min( $available, $remaining_to_allocate );
			$allocations[]          = array(
				'transaction_id' => $capture['transaction_id'],
				'amount'         => number_format( $take, VISA_ACCEPTANCE_VAL_TWO, '.', '' ),
			);
			$remaining_to_allocate = round( $remaining_to_allocate - $take, VISA_ACCEPTANCE_VAL_TWO );
		}

		if ( $remaining_to_allocate > $amount_epsilon ) {
			// Combined remaining balance across all captures still doesn't cover the request.
			return false;
		}

		return $allocations;
	}

	/**
	 * Records a refund against a specific capture transaction for an APM order.
	 *
	 * @param \WC_Order $order          order object.
	 * @param string    $transaction_id capture transaction id the refund was issued against.
	 * @param float     $amount         refunded amount.
	 */
	public function record_apm_capture_refund( $order, $transaction_id, $amount ) {
		$capture_history = $order->get_meta( '_vas_partial_captures', true );
		if ( ! is_array( $capture_history ) || empty( $transaction_id ) ) {
			return;
		}

		$requested_amount = round( (float) $amount, VISA_ACCEPTANCE_VAL_TWO );
		foreach ( $capture_history as &$capture ) {
			if ( isset( $capture['transaction_id'] ) && $capture['transaction_id'] === $transaction_id ) {
				$capture['refunded'] = round( (float) ( $capture['refunded'] ?? VISA_ACCEPTANCE_VAL_ZERO ) + $requested_amount, VISA_ACCEPTANCE_VAL_TWO );
				break;
			}
		}
		unset( $capture );

		$order->update_meta_data( '_vas_partial_captures', $capture_history );
		$order->save();
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
			$prefix = VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method() ? '_wc_' . VISA_ACCEPTANCE_SV_GATEWAY_ID . VISA_ACCEPTANCE_UNDERSCORE : VISA_ACCEPTANCE_WC_UC_ID;
			if ( visa_acceptance_handle_hpos_compatibility() ) {
				$meta = $order->get_meta( $prefix . $key, true, VISA_ACCEPTANCE_EDIT);
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
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_orders is a valid WooCommerce capability.
			wp_send_json_error( array( 'message' => __( 'You do not have permission to capture payments.', 'visa-acceptance-solutions' ) ) );
			return;
		}
		$payment_gateway_uc = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		// Sanitize and validate POST data.
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;
		$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : '';

		// Validate required fields.
		if ( empty( $order_id ) || empty( $gateway_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'visa-acceptance-solutions' ) ) );
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'visa-acceptance-solutions' ) ) );
			return;
		}

		$response = array(
			VISA_ACCEPTANCE_ERROR                 => VISA_ACCEPTANCE_VAL_ZERO,
			'message'               => VISA_ACCEPTANCE_STRING_EMPTY,
			VISA_ACCEPTANCE_SUCCESS => VISA_ACCEPTANCE_VAL_ZERO,
		);
		if ( ( VISA_ACCEPTANCE_UC_ID === $gateway_id || VISA_ACCEPTANCE_SV_GATEWAY_ID === $gateway_id ) && false !== $order ) {
			$result_response    = $payment_gateway_uc->init_process_capture( $order_id, $gateway_id );
			if ( empty( $result_response[ VISA_ACCEPTANCE_ERROR ] ) && ( VISA_ACCEPTANCE_YES === ( $result_response[ VISA_ACCEPTANCE_SUCCESS ] ?? '' ) ) ) {
				$response[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_VAL_ONE;
				$response['message']                 = $result_response['alert_message'] ?? '';
			} else {
				$response[ VISA_ACCEPTANCE_SUCCESS ] = VISA_ACCEPTANCE_VAL_ZERO;
				$response['message']                 = $result_response['error_message'] ?? '';
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
		return in_array( $this->get_order_meta( $order, VISA_ACCEPTANCE_CHARGE_CAPTURED ), array( VISA_ACCEPTANCE_YES, VISA_ACCEPTANCE_PARTIAL, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PENDING ), true );
	}

	/**
	 * Builds the standard list of response detail lines (Request ID, Approval Code,
	 * Response Code, Transaction ID, Merchant Reference Code, Auth Method, Timestamp)
	 * shown on order notes for authorize, capture, partial capture, refund, void and
	 * Decision Manager reject/review responses.
	 *
	 * @param array $payment_response_array payment response array.
	 *
	 * @return array
	 */
	public function build_response_credit_fields( $payment_response_array ) {
		$credit_fields = array();
		if ( ! empty( $payment_response_array['processor_transaction_id'] ) ) {
			$credit_fields[] = 'Processor Transaction ID: ' . $payment_response_array['processor_transaction_id'];
		}
		if ( ! empty( $payment_response_array['request_id'] ) ) {
			$credit_fields[] = 'Request ID: ' . $payment_response_array['request_id'];
		}
		if ( ! empty( $payment_response_array['credit_auth_code'] ) ) {
			$credit_fields[] = 'Approval Code: ' . $payment_response_array['credit_auth_code'];
		}
		if ( isset( $payment_response_array['credit_auth_response'] ) && '' !== $payment_response_array['credit_auth_response'] ) {
			$credit_fields[] = 'Response Code: ' . $payment_response_array['credit_auth_response'];
		}
		if ( ! empty( $payment_response_array['merchant_ref_code'] ) ) {
			$credit_fields[] = 'Merchant Reference Code: ' . $payment_response_array['merchant_ref_code'];
		}
		if ( ! empty( $payment_response_array['auth_method'] ) ) {
			$credit_fields[] = 'Auth Method: ' . $payment_response_array['auth_method'];
		}
		if ( ! empty( $payment_response_array['submit_time'] ) ) {
			$credit_fields[] = 'Timestamp: ' . $payment_response_array['submit_time'];
		}
		return $credit_fields;
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
        if ( ! $update_status ) {
            // Add detailed response fields for approval, rejection and DM review messages.
            $should_add_credit_fields = ( false !== strpos( $order_note_message, 'Approved:' )
                || false !== strpos( $order_note_message, 'Rejected:' )
                || false !== strpos( $order_note_message, 'Rejected by Decision Manager' )
                || false !== strpos( $order_note_message, 'manual review' ) );

            if ( $should_add_credit_fields ) {
                $credit_fields = $this->build_response_credit_fields( $payment_response_array );
                if ( ! empty( $credit_fields ) ) {
                    // Remove "(Transaction ID" and everything after from message, then add credit fields.
                    $transaction_id_pos = strpos( $message, '(Transaction ID' );
                    if ( false !== $transaction_id_pos ) {
                        $message = substr( $message, 0, $transaction_id_pos );
                        $message = rtrim( $message );
                    }
                    $message .= "\n" . implode( "\n", $credit_fields );
                }
            }
        }
       
        if ( $update_status ) {
            // Update order status and add note.
            if ( $order instanceof \WC_Subscription && VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING === $update_status ) {
                // For subscriptions with "processing" status request, just add a note instead.
                $order->add_order_note( $message );
            } else {
                $order->update_status( $update_status, $message );
            }
        } else {
            $order->add_order_note( $message );
        }
    } 

	/**
	 * Handles AJAX call for partial capture
	 *
	 * @return void
	 */
	public function ajax_process_partial_capture() {
		check_ajax_referer( 'visa_acceptance_partial_capture', VISA_ACCEPTANCE_NONCE );
		
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => __( 'You do not have permission to capture payments.', 'visa-acceptance-solutions' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;
		$quantities = isset( $_POST['quantities'] ) ? array_map( 'absint', $_POST['quantities'] ) : array();
		$include_shipping = isset( $_POST['include_shipping'] ) && VISA_ACCEPTANCE_YES === $_POST['include_shipping'];

		$order = wc_get_order( $order_id );
		if ( empty( $order_id ) || ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'visa-acceptance-solutions' ) ) );
		}

		try {
			// Load partial capture class if not already loaded.
			if ( ! class_exists( 'Visa_Acceptance_Partial_Capture' ) ) {
				require_once __DIR__ . '/api/payments/class-visa-acceptance-partial-capture.php';
			}
			
			$gateway = property_exists( $this, 'gateway' ) && $this->gateway instanceof \WC_Payment_Gateway ? $this->gateway : $this;
			$partial_capture = new Visa_Acceptance_Partial_Capture( $gateway );
			
			$validation_errors = $this->validate_capture_quantities( $order, $quantities, $partial_capture );
			if ( ! empty( $validation_errors ) ) {
				wp_send_json_error( array( 'message' => implode( '<br>', $validation_errors ) ) );
			}
			
			$result = $partial_capture->perform_partial_capture( $order, $quantities, $include_shipping, $response_data );

			if ( VISA_ACCEPTANCE_YES === ( $result[ VISA_ACCEPTANCE_SUCCESS ] ?? '' ) ) {
				wp_send_json_success( array( 
					'success' => VISA_ACCEPTANCE_VAL_ONE,
					'message' => $result['alert_message'] ?? __( 'Partial capture completed successfully.', 'visa-acceptance-solutions' )
				) );
			} else {
				wp_send_json_error( array( 
					'success' => VISA_ACCEPTANCE_VAL_ZERO,
					'message' => $result['error_message'] ?? __( 'Partial capture failed.', 'visa-acceptance-solutions' )
				) );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 
				'success' => VISA_ACCEPTANCE_VAL_ZERO,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Error: %s', 'visa-acceptance-solutions' ), $e->getMessage() )
			) );
		}
	}

	/**
	 * Validates capture quantities against remaining quantities
	 *
	 * @param \WC_Order                          $order Order object.
	 * @param array                              $quantities Quantities to capture.
	 * @param Visa_Acceptance_Partial_Capture    $partial_capture Partial capture instance.
	 * 
	 * @return array Validation errors.
	 */
	private function validate_capture_quantities( $order, $quantities, $partial_capture ) {
		$remaining_quantities = $partial_capture->get_remaining_quantities( $order );
		$validation_errors = array();
		
		foreach ( $quantities as $item_id => $qty ) {
			if ( $qty <= VISA_ACCEPTANCE_VAL_ZERO ) {
				continue;
			}
			
			$item = $order->get_item( $item_id );
			if ( ! $item ) {
				/* translators: %d: item ID */
				$validation_errors[] = sprintf( __( 'Invalid item ID: %d', 'visa-acceptance-solutions' ), $item_id );
				continue;
			}
			
			$remaining_qty = $remaining_quantities[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO;
			if ( $qty > $remaining_qty ) {
				$validation_errors[] = sprintf(
					/* translators: 1: item name, 2: quantity to capture, 3: remaining quantity */
					__( '%1$s: Cannot capture %2$d (only %3$d remaining)', 'visa-acceptance-solutions' ),
					$item->get_name(),
					$qty,
					$remaining_qty
				);
			}
		}
		
		return $validation_errors;
	}

	/**
	 * Renders the inline partial capture UI (WooCommerce style)
	 *
	 * @param \WC_Order $order Order object.
	 * 
	 * @return void
	 */
	public function render_partial_capture_inline( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$capture_data = $this->get_capture_summary( $order );
		
		if ( ! $capture_data['has_remaining_items'] || $capture_data['remaining_amount'] <= VISA_ACCEPTANCE_VAL_ZERO ) {
			return;
		}

		$this->render_partial_capture_ui( $order, $capture_data );
	}

	/**
	 * Gets capture summary data for an order
	 *
	 * @param \WC_Order $order Order object.
	 * 
	 * @return array Capture summary data.
	 */
	private function get_capture_summary( $order ) {
		// Load partial capture class if not already loaded.
		if ( ! class_exists( 'Visa_Acceptance_Partial_Capture' ) ) {
			require_once __DIR__ . '/api/payments/class-visa-acceptance-partial-capture.php';
		}
		
		$gateway = property_exists( $this, 'gateway' ) && $this->gateway instanceof \WC_Payment_Gateway ? $this->gateway : $this;
		$partial_capture = new Visa_Acceptance_Partial_Capture( $gateway );
		
		$authorization_amount_meta = $this->get_order_meta( $order, VISA_ACCEPTANCE_AUTH_AMOUNT );
		$authorization_amount = $authorization_amount_meta ? $authorization_amount_meta : $order->get_total();
		$capture_history_meta = $order->get_meta( '_vas_partial_captures', true );
		$capture_history = $capture_history_meta ? $capture_history_meta : array();
		
		$total_captured = array_sum( array_column( $capture_history, 'amount' ) );
		$remaining_amount = max( VISA_ACCEPTANCE_VAL_ZERO, floatval( $authorization_amount ) - $total_captured );
		$remaining_quantities = $partial_capture->get_remaining_quantities( $order );
		
		$has_remaining_items = false;
		foreach ( $remaining_quantities as $qty ) {
			if ( $qty > VISA_ACCEPTANCE_VAL_ZERO ) {
				$has_remaining_items = true;
				break;
			}
		}
		
		return array(
			'authorization_amount' => floatval( $authorization_amount ),
			'total_captured' => $total_captured,
			'remaining_amount' => $remaining_amount,
			'remaining_quantities' => $remaining_quantities,
			'shipping_captured' => $partial_capture->is_shipping_captured( $order ),
			'has_remaining_items' => $has_remaining_items,
		);
	}

	/**
	 * Renders the partial capture UI HTML
	 *
	 * @param \WC_Order $order Order object.
	 * @param array     $capture_data Capture summary data.
	 * 
	 * @return void
	 */
	private function render_partial_capture_ui( $order, $capture_data ) {
		extract( $capture_data ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		?>
		<tr class="partial-capture" data-order_id="<?php echo esc_attr( $order->get_id() ); ?>" data-available-amount="<?php echo esc_attr( $remaining_amount ); ?>" style="display: none;">
			<td colspan="6">
				<div class="wc-order-data-row wc-order-partial-capture-data" style="background: #f8f8f8; padding: 12px;">
					<div class="wc-order-totals-items">
						<table class="wc-order-totals" style="width: 100%; border:none;">
							<tbody>
								<?php foreach ( $order->get_items() as $item_id => $item ) : 
									$remaining_qty = $remaining_quantities[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO;
									if ( $remaining_qty <= VISA_ACCEPTANCE_VAL_ZERO ) continue;
									
									$item_total = $item->get_total() + $item->get_total_tax();
									$unit_price = $item_total / $item->get_quantity();
									?>
									<tr class="capture-item" data-order_item_id="<?php echo esc_attr( $item_id ); ?>">
										<td class="thumb"></td>
										<td class="name">
											<?php echo esc_html( $item->get_name() ); ?>
											<small class="times">&times;</small>
											<small class="qty"><?php echo esc_html( $item->get_quantity() ); ?></small>
											<?php if ( $remaining_qty < $item->get_quantity() ) : ?>
												<br><small style="color: #999;">
													<?php 
													/* translators: %d: remaining quantity */
													printf( esc_html__( '(%d remaining to capture)', 'visa-acceptance-solutions' ), absint( $remaining_qty ) ); 
													?>
												</small>
											<?php endif; ?>
										</td>
										<td class="item_cost" width="1%"><?php echo wp_kses_post( wc_price( $unit_price, array( 'currency' => $order->get_currency() ) ) ); ?></td>
										<td class="quantity" width="1%">
											<label><?php esc_html_e( 'Qty to capture:', 'visa-acceptance-solutions' ); ?></label>
											<input type="number" class="capture-qty" name="capture_qty[<?php echo esc_attr( $item_id ); ?>]" 
												data-item-id="<?php echo esc_attr( $item_id ); ?>" data-unit-price="<?php echo esc_attr( $unit_price ); ?>"
												min="0" max="<?php echo esc_attr( $remaining_qty ); ?>" value="0" step="1" style="width: 70px;" />
										</td>
										<td class="line_cost" width="1%">
											<span class="capture-line-total" data-item-id="<?php echo esc_attr( $item_id ); ?>">
												<?php echo wp_kses_post( wc_price( VISA_ACCEPTANCE_VAL_ZERO, array( 'currency' => $order->get_currency() ) ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="wc-order-data-row wc-order-capture-totals" style="margin-top: 12px;">
						<table class="wc-order-totals">
							<tbody>
								<?php if ( ! $shipping_captured && $order->get_shipping_total() > VISA_ACCEPTANCE_VAL_ZERO ) : ?>
									<tr>
										<td class="label">
											<label>
												<input type="checkbox" id="capture-shipping" name="capture_shipping" value="yes"
													data-shipping-amount="<?php echo esc_attr( $order->get_shipping_total() + $order->get_shipping_tax() ); ?>" />
												<?php esc_html_e( 'Capture shipping:', 'visa-acceptance-solutions' ); ?>
											</label>
										</td>
										<td class="total" width="1%">
											<?php echo wp_kses_post( wc_price( $order->get_shipping_total() + $order->get_shipping_tax(), array( 'currency' => $order->get_currency() ) ) ); ?>
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<td class="label"><?php esc_html_e( 'Total authorized amount:', 'visa-acceptance-solutions' ); ?></td>
									<td class="total" width="1%"><?php echo wp_kses_post( wc_price( $authorization_amount, array( 'currency' => $order->get_currency() ) ) ); ?></td>
								</tr>
								<?php if ( $total_captured > VISA_ACCEPTANCE_VAL_ZERO ) : ?>
									<tr>
										<td class="label"><?php esc_html_e( 'Already captured:', 'visa-acceptance-solutions' ); ?></td>
										<td class="total" width="1%"><?php echo wp_kses_post( wc_price( $total_captured, array( 'currency' => $order->get_currency() ) ) ); ?></td>
									</tr>
								<?php endif; ?>
								<tr>
									<td class="label"><strong><?php esc_html_e( 'Total available to capture:', 'visa-acceptance-solutions' ); ?></strong></td>
									<td class="total" width="1%"><strong><?php echo wp_kses_post( wc_price( $remaining_amount, array( 'currency' => $order->get_currency() ) ) ); ?></strong></td>
								</tr>
								<tr>
									<td class="label"><label for="capture_amount"><?php esc_html_e( 'Amount to capture:', 'visa-acceptance-solutions' ); ?></label></td>
									<td class="total" width="1%">
										<input type="text" id="capture_amount" name="capture_amount" class="wc_input_price" value="0" style="width: 100px;" readonly />
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="wc-order-data-row wc-order-capture-actions" style="margin-top: 12px;">
						<button type="button" class="button button-primary do-api-capture" style="float:right;">
							<?php 
							printf( 
								/* translators: %s: amount to capture */
								esc_html__( 'Capture %s via Visa Acceptance Solutions', 'visa-acceptance-solutions' ), 
								'<span class="capture-amount-display">' . wp_kses_post( wc_price( VISA_ACCEPTANCE_VAL_ZERO, array( 'currency' => $order->get_currency() ) ) ) . '</span>'
							); 
							?>
						</button>
						<button type="button" class="button cancel-partial-capture" style="float:right; margin-right:4px;">
							<?php esc_html_e( 'Cancel', 'visa-acceptance-solutions' ); ?>
						</button>
						<div class="clear"></div>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Hook to render partial capture inline UI on order pages
	 * 
	 * @param int $order_id Order ID.
	 * 
	 * @return void
	 */
	public function render_partial_capture_inline_hook( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		
		// Only show for this gateway.
		if ( $order->get_payment_method() !== $this->get_id() && $order->get_payment_method() !== VISA_ACCEPTANCE_SV_GATEWAY_ID ) {
			return;
		}
		
		// Only show if not fully captured.
		if ( $this->is_order_fully_captured( $order ) ) {
			return;
		}

		$this->render_partial_capture_inline( $order );
	}

	/**
     * Adds Refresh Payment Status button.
     *
     * Displays only for PPRO/APM payments to check current transaction status from CyberSource.
     * @param \WC_Order $order Order object.
     * @return void
     */
    /* public function add_refresh_status_button( $order ) {
        // Check if we have a transaction ID to refresh.
        $transaction_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );
        if ( empty( $transaction_id ) ) {
            return;
        }
       
        // Only show for APM/PPRO payments.
        $payment_type = $order->get_meta( '_vas_payment_type', true );
        $is_apm = ( VISA_ACCEPTANCE_APM_STRING === strtolower( $payment_type ) );
        if ( ! $is_apm ) {
            return;
        }
       
        // Don't show for completed/cancelled/refunded orders.
        $excluded_statuses = array(
            VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED,
            VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED,
            VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_REFUNDED,
        );
        if ( in_array( $order->get_status(), $excluded_statuses, true ) ) {
            return;
        }
        // Render the button.
        $class_value = array(
            'button',
            'refresh-payment-status',
            'wc-' . $this->get_id_dasherized() . '-refresh-status',
        );
        ?>
        <button type="button" id="refresh-status-button" class="<?php echo esc_attr( implode( VISA_ACCEPTANCE_SPACE, $class_value ) ); ?>">
            <?php esc_html_e( 'Refresh Payment Status', 'visa-acceptance-solutions' ); ?>
        </button>
        <?php
    } */
 

	/**
	 * Handles AJAX call for refreshing payment status.
	 *
	 * @return void
	 */
	public function ajax_process_refresh_status() {
		check_ajax_referer( VISA_ACCEPTANCE_WC_REFRESH_STATUS_ACTION, VISA_ACCEPTANCE_NONCE );
		
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => __( 'You do not have permission to refresh payment status.', 'visa-acceptance-solutions' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;
		$transaction_id = isset( $_POST['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_POST['transaction_id'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order_id || ! $transaction_id || ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or transaction ID.', 'visa-acceptance-solutions' ) ) );
		}

		try {
			require_once __DIR__ . '/api/request/payments/class-visa-acceptance-payment-adapter.php';
			$response = ( new Visa_Acceptance_Payment_Adapter( $this->gateway ) )->get_refresh_payment_status_sdk( $transaction_id, $order_id );
			
			if ( isset( $response['http_code'] ) && VISA_ACCEPTANCE_TWO_ZERO_ZERO === (int) $response['http_code'] ) {
				$status = strtoupper( $response['status'] ?? 'UNKNOWN' );
				$updated = $this->update_order_from_payment_status( $order, $status, $transaction_id );
				
				wp_send_json_success( array(
					'message' => sprintf(
						/* translators: %s: payment status */
						__( 'Payment status refreshed: %s.', 'visa-acceptance-solutions' ) . ( $updated ? ' ' . __( 'Order updated.', 'visa-acceptance-solutions' ) : '' ),
						esc_html( $status )
					),
					'status' => $status,
				) );
			} else {
				wp_send_json_error( array( 'message' => $response['body'] ?? __( 'Failed to refresh payment status.', 'visa-acceptance-solutions' ) ) );
			}
		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( array( 'message' => sprintf( __( 'Error: %s', 'visa-acceptance-solutions' ), $e->getMessage() ) ) );
		}
	}

	/**
	 * Updates order based on refreshed payment status.
	 *
	 * @param \WC_Order $order          Order object.
	 * @param string    $status         Payment status from CyberSource.
	 * @param string    $transaction_id Transaction ID.
	 * @return bool True if order was updated, false otherwise.
	 */
	private function update_order_from_payment_status( $order, $status, $transaction_id ) {
		// Status mapping: CyberSource status => [new_order_status, excluded_statuses, message].
		$status_map = array(
			'COMPLETED' => array(
				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING,
				array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED ),
				/* translators: %s: transaction ID */
				__( 'Payment status: COMPLETED. Transaction ID: %s', 'visa-acceptance-solutions' ),
			),
			'PENDING|PENDING_REVIEW|AUTHORIZED|AUTHORIZED_PENDING_REVIEW|PAYER_ACTION_REQUIRE' => array(
				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD,
				array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_ON_HOLD ),
				/* translators: %s: payment status */
				__( 'Payment status: %s - pending.', 'visa-acceptance-solutions' ),
			),
			'DECLINED|INVALID_REQUEST|FAILED' => array(
				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED,
				array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED ),
				/* translators: %s: payment status */
				__( 'Payment status: %s - failed.', 'visa-acceptance-solutions' ),
			),
			'REVERSED' => array(
				VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED,
				array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_CANCELLED ),
				/* translators: %s: transaction ID */
				__( 'Payment status: REVERSED. Transaction ID: %s', 'visa-acceptance-solutions' ),
			),
		);

		$current_status = $order->get_status();
		$updated = false;
		foreach ( $status_map as $statuses => $config ) {
			if ( in_array( $status, explode( '|', $statuses ), true ) ) {
				list( $new_status, $excluded, $message ) = $config;
				$msg = sprintf( $message, esc_html( 'COMPLETED' === $status || 'REVERSED' === $status ? $transaction_id : $status ) );
				
				if ( ! in_array( $current_status, $excluded, true ) ) {
					$order->update_status( $new_status, $msg );
					$updated = true;
				} else {
					$order->add_order_note( $msg );
				}
				break;
			}
		}

		// Handle REFUNDED (note only) and unknown statuses.
		if ( ! $updated && ! in_array( $status, array( 'REFUNDED' ), true ) ) {
			/* translators: %s: payment status */
			$order->add_order_note( sprintf( __( 'Payment status: %s', 'visa-acceptance-solutions' ), esc_html( $status ) ) );
		} elseif ( 'REFUNDED' === $status ) {
			/* translators: %s: transaction ID */
			$order->add_order_note( sprintf( __( 'Payment REFUNDED. Transaction ID: %s', 'visa-acceptance-solutions' ), esc_html( $transaction_id ) ) );
		}

		// Store CyberSource status.
		if ( visa_acceptance_handle_hpos_compatibility() ) {
			$order->update_meta_data( '_visa_acceptance_cybersource_status', $status );
			$order->save_meta_data();
		} else {
			update_post_meta( $order->get_id(), '_visa_acceptance_cybersource_status', $status );
		}
		
		return $updated;
	}

	/**
	 * Fetch all webhook subscriptions from CyberSource API.
	 *
	 * @param bool $skip_cache Whether to skip cached data.
	 * @return array Array of webhook subscription data.
	 */
	public function fetch_webhooks_from_api( $skip_cache = false ) {
		// Try to get from cache first (5 minutes cache).
		if ( ! $skip_cache ) {
			$cached = get_transient( 'vas_webhooks_list_' . $this->get_merchant_id() );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			// Get API credentials.
			$settings     = $this->get_gateway_settings();
			$environment  = isset( $settings['environment'] ) ? $settings['environment'] : VISA_ACCEPTANCE_ENVIRONMENT_TEST;
			$is_test      = VISA_ACCEPTANCE_ENVIRONMENT_TEST === $environment;
			$merchant_id  = $this->get_merchant_id();
			$api_key      = $is_test ? ( $settings['test_api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
			$secret_key   = $is_test ? ( $settings['test_api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
			$host         = $is_test ? VISA_ACCEPTANCE_REQUEST_HOST_APITEST : VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION;

			if ( empty( $merchant_id ) || empty( $api_key ) || empty( $secret_key ) ) {
				return array();
			}

			// Query API for all webhooks.
			$resource = VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH . '?organizationId=' . rawurlencode( $merchant_id );
			$url      = 'https://' . $host . $resource;
			$headers  = $this->build_webhook_http_signature_headers( $host, $resource, 'GET', VISA_ACCEPTANCE_STRING_EMPTY, $merchant_id, $api_key, $secret_key );

			$response = wp_remote_get(
				$url,
				array(
					'headers'   => $headers,
					'timeout'   => 20,
					'sslverify' => ! $is_test,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$http_code = wp_remote_retrieve_response_code( $response );
			$body      = wp_remote_retrieve_body( $response );
			$data      = json_decode( $body, true );

			// 404 means no subscriptions exist for this merchant — treat as empty.
			if ( VISA_ACCEPTANCE_FOUR_ZERO_FOUR === (int) $http_code ) {
				return array();
			}

			if ( VISA_ACCEPTANCE_TWO_ZERO_ZERO !== (int) $http_code || ! is_array( $data ) ) {
				return array();
			}

			// Handle both response formats: array directly or nested in 'webhooks' property.
			$webhooks = array();
			if ( isset( $data['webhooks'] ) && is_array( $data['webhooks'] ) ) {
				$webhooks = $data['webhooks'];
			} elseif ( isset( $data[VISA_ACCEPTANCE_VAL_ZERO] ) ) {
				// Response is directly an array of webhooks.
				$webhooks = $data;
			}
			// Cache the result for 5 minutes.
			set_transient( 'vas_webhooks_list_' . $merchant_id, $webhooks, 5 * MINUTE_IN_SECONDS );

			return $webhooks;

		} catch ( Exception $e ) {
			$this->add_logs_data( array( 'Webhook fetch exception: ' . $e->getMessage() ), false, 'Fetch Webhooks', true );
			return array();
		}
	}

	/**
	 * Generate HTML for the registered webhooks table.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 * @return string HTML output.
	 */
	public function generate_registered_webhooks_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
       
        // Get webhooks from EBC API.
        $api_webhooks = $this->fetch_webhooks_from_api();
		 // Get webhook IDs stored by this plugin.
        $plugin_webhook_ids = array_filter( array(
            get_option( VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY ),
            get_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY ),
            get_option( VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY ),
            get_option( VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION, VISA_ACCEPTANCE_STRING_EMPTY ),
        ) );
       
        $valid_webhooks = array();
        if ( is_array( $api_webhooks ) ) {
            foreach ( $api_webhooks as $webhook ) {
				$webhook_id = $webhook['webhookId'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
               
                // Filter: only show webhooks that belong to this plugin.
                if ( empty( $webhook_id ) || ! in_array( $webhook_id, $plugin_webhook_ids, true ) ) {
                    continue;
                }
                // Extract product ID and event types from webhook data (from EBC API).
                $product_id = VISA_ACCEPTANCE_STRING_EMPTY;
                $event_types = array();
               
                if ( isset( $webhook['productId'] ) ) {
                    // Older format: productId directly on webhook.
                    $product_id = $webhook['productId'];
                } elseif ( isset( $webhook['products'] ) && is_array( $webhook['products'] ) && ! empty( $webhook['products'] ) ) {
                    // Newer format: products array.
                    $product_id = $webhook['products'][VISA_ACCEPTANCE_VAL_ZERO]['productId'] ?? VISA_ACCEPTANCE_STRING_EMPTY;
                    // Extract eventTypes from the products array (from EBC API response).
                    $event_types = $webhook['products'][VISA_ACCEPTANCE_VAL_ZERO]['eventTypes'] ?? array();
                }
                $webhook['product_id'] = $product_id;
                $webhook['eventTypes'] = $event_types; // Use actual event types from EBC API.
                $valid_webhooks[] = $webhook;
            }
        }
 
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
                   
                    <div id="vas-webhooks-container">
                        <?php if ( ! empty( $valid_webhooks ) ) : ?>
                            <table class="vas-webhooks-table widefat" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th class="vas-webhook-product"><?php esc_html_e( 'Product Id', 'visa-acceptance-solutions' ); ?></th>
                                        <th class="vas-webhook-events"><?php esc_html_e( 'Event Types', 'visa-acceptance-solutions' ); ?></th>
                                        <th class="vas-webhook-action"><?php esc_html_e( 'Action', 'visa-acceptance-solutions' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $valid_webhooks as $webhook ) : ?>
                                        <?php
                                        $webhook_id   = isset( $webhook['webhookId'] ) ? sanitize_text_field( $webhook['webhookId'] ) : '';
                                        $product_id   = isset( $webhook['product_id'] ) ? sanitize_text_field( $webhook['product_id'] ) : 'N/A';
                                        $event_types  = isset( $webhook['eventTypes'] ) && is_array( $webhook['eventTypes'] ) ? $webhook['eventTypes'] : array();
                                       
                                        if ( empty( $webhook_id ) ) {
                                            continue;
                                        }
                                        ?>
                                        <tr data-webhook-id="<?php echo esc_attr( $webhook_id ); ?>">
                                            <td class="vas-webhook-product"><?php echo esc_html( $product_id ); ?></td>
                                            <td class="vas-webhook-events">
                                                <?php
                                                if ( ! empty( $event_types ) ) {
                                                    $escaped_events = array_map( 'esc_html', $event_types );
                                                    echo wp_kses_post( implode( '<br>', $escaped_events ) );
                                                } else {
                                                    echo esc_html__( 'N/A', 'visa-acceptance-solutions' );
                                                }
                                                ?>
                                            </td>
                                            <td class="vas-webhook-action">
                                                <button type="button" class="button vas-delete-webhook"
                                                        data-webhook-id="<?php echo esc_attr( $webhook_id ); ?>"
                                                        data-product-id="<?php echo esc_attr( $product_id ); ?>">
                                                    <?php esc_html_e( 'Delete', 'visa-acceptance-solutions' ); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else : ?>
                            <p class="vas-no-webhooks">
                                <?php esc_html_e( 'No webhook subscriptions found.', 'visa-acceptance-solutions' ); ?>
                                <?php esc_html_e( 'Enable webhook features in settings above and save to automatically subscribe.', 'visa-acceptance-solutions' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
 
                    <?php if ( ! empty( $data['description'] ) ) : ?>
                        <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
                    <?php endif; ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
}
