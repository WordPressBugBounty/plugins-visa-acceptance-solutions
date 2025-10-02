<?php
/**
 * Trait Visa_Acceptance_Payment_Gateway_Public_Trait
 *
 * The admin-specific functionality of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Visa Acceptance Payment Gateway Public Trait
 */
trait Visa_Acceptance_Payment_Gateway_Public_Trait {
		/**
		 * Returns checkout page order id.
		 *
		 * @return int
		 */
	public function get_checkout_pay_page_order_id() {
		global $wp;
		return isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : VISA_ACCEPTANCE_VAL_ZERO;
	}

	/**
	 * This method handles Building payment request based on order/cart.
	 */
	public function get_payment_request() {
		if ( is_checkout() ) {
			$order = wc_get_order( $this->get_checkout_pay_page_order_id() );
			if ( $order ) {
				return wp_json_encode( $this->get_payment_request_for_order( $order ) );
			}
			return wp_json_encode( $this->get_payment_request_for_cart( WC()->cart ) );
		}
	}

	/**
	 * Builds payment request totals.
	 *
	 * @param object $totals total details.
	 *
	 * @return array
	 */
	protected function build_payment_request_totals( $totals ) {
		$totals = wp_parse_args(
			$totals,
			array(
				'subtotal' => VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO,
				'discount' => VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO,
				'shipping' => VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO,
				'fees'     => VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO,
				'taxes'    => VISA_ACCEPTANCE_VAL_ZERO_DOT_ZERO_ZERO,
			)
		);
		return array_filter(
			array(
				'subtotal'         => wc_format_decimal( $totals['subtotal'] ),
				'shippingHandling' => wc_format_decimal( $totals['shipping'] ),
				'tax'              => wc_format_decimal( $totals['taxes'] ),
				'discount'         => wc_format_decimal( $totals['discount'] ),
				'misc'             => wc_format_decimal( $totals['fees'] ),
			),
			'floatval'
		);
	}

	/**
	 * Gets the device data JS URL.
	 *
	 * @param string $organization_id organization_id.
	 * @param string $merchant_id merchant_id.
	 * @param string $session_id session_id.
	 * @param bool   $library_required library_required.
	 * @return string
	 */
	public static function get_dfp_url( $organization_id, $merchant_id, $session_id, $library_required = false ) {
		$arg = $library_required ? VISA_ACCEPTANCE_ONLINE_METRIX . '.js' : VISA_ACCEPTANCE_ONLINE_METRIX;
		return add_query_arg(
			array(
				'org_id'     => wc_clean( $organization_id ),
				'session_id' => wc_clean( $merchant_id ) . wc_clean( $session_id ),
			),
			$arg
		);
	}
	/**
	 * Marks order status as failed if any error occured.
	 *
	 * @param string $message error message.
	 *
	 * @return void
	 */
	public function mark_order_failed( $message ) {
		wc_add_notice( $message, VISA_ACCEPTANCE_STRING_ERROR );
	}

	/**
	 * Adds detailed message to the Error notices.
	 *
	 * @param string $message error message.
	 *
	 * @return string
	 */
	public function add_detailed_message( $message ) {
		$response         = VISA_ACCEPTANCE_STRING_EMPTY;
		$message_id_array = $this->create_message_id_array();
		if ( isset( $message_id_array[ $message ] ) ) {
			$response = $this->map_valid_detailed_message( $message_id_array[ $message ] );
		}

		return $response;
	}

	/**
	 * Builds message id array for mapping appropriate error messages.
	 *
	 * @return array
	 */
	public function create_message_id_array() {
		return array(
			VISA_ACCEPTANCE_REASON_INVALID_ACCOUNT      => 'card_number_invalid',
			VISA_ACCEPTANCE_REASON_STOLEN_LOST_CARD     => 'card_inactive',

			// CSC & AVS.
			VISA_ACCEPTANCE_REASON_INVALID_CVN          => 'csc_invalid',
			VISA_ACCEPTANCE_REASON_CVN_NOT_MATCH        => 'csc_mismatch',
			VISA_ACCEPTANCE_REASON_AVS_FAILED           => 'avs_mismatch',

			// expiration.
			VISA_ACCEPTANCE_REASON_EXPIRED_CARD         => 'card_expired',

			// general declines.
			VISA_ACCEPTANCE_REASON_EXCEEDS_CREDIT_LIMIT => 'credit_limit_reached',
			VISA_ACCEPTANCE_REASON_INSUFFICIENT_FUND    => 'insufficient_funds',
			VISA_ACCEPTANCE_REASON_GENERAL_DECLINE      => 'card_declined',
			VISA_ACCEPTANCE_REASON_PROCESSOR_DECLINED   => 'card_declined',

			VISA_ACCEPTANCE_REASON_CV_FAILED            => 'held_for_incorrect_csc',
			VISA_ACCEPTANCE_REASON_CONTACT_PROCESSOR    => 'held_for_review',

			VISA_ACCEPTANCE_API_RESPONSE_DECISION_PROFILE_REJECT     => 'declined_by_decision_manager',
		);
	}

	/**
	 * Maps message ids to appropriate detailed error messages.
	 *
	 * @param string $message_id message id.
	 *
	 * @return string
	 */
	public function map_valid_detailed_message( $message_id ) {
		switch ( $message_id ) {

			// generic messages.
			case VISA_ACCEPTANCE_STRING_ERROR:
				$message = esc_html__( 'An error occurred, please try again or try an alternate form of payment', 'visa-acceptance-solutions' );
				break;
			case 'decline':
				$message = esc_html__( 'We cannot process your order with the payment information that you provided. Please use a different payment account or an alternate payment method.', 'visa-acceptance-solutions' );
				break;
			case 'held_for_review':
				$message = esc_html__( 'The order is being placed on hold for review. Please contact us to complete the transaction.', 'visa-acceptance-solutions' );
				break;

			/* missing/invalid info */

			// csc.
			case 'held_for_incorrect_csc':
				$message = esc_html__( 'The order is being placed on hold for review. Please contact us to complete the transaction.', 'visa-acceptance-solutions' );
				break;
			case 'csc_invalid':
				$message = esc_html__( 'We are unable to complete your order. Please check your payment details and try again.', 'visa-acceptance-solutions' );
				break;
			case 'csc_missing':
				$message = esc_html__( 'Please enter your card verification number and try again.', 'visa-acceptance-solutions' );
				break;

			// card type.
			case 'card_type_not_accepted':
				$message = esc_html__( 'The card type is not accepted, please use an alternate card or other form of payment.', 'visa-acceptance-solutions' );
				break;
			case 'card_type_invalid':
				$message = esc_html__( 'The card type is invalid or does not correlate with the credit card number.  Please try again or use an alternate card or other form of payment.', 'visa-acceptance-solutions' );
				break;
			case 'card_type_missing':
				$message = esc_html__( 'Please select the card type and try again.', 'visa-acceptance-solutions' );
				break;

			// card number.
			case 'card_number_type_invalid':
				$message = esc_html__( 'The card type is invalid or does not correlate with the credit card number.  Please try again or use an alternate card or other form of payment.', 'visa-acceptance-solutions' );
				break;
			case 'card_number_invalid':
				$message = esc_html__( 'The card number is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;
			case 'card_number_missing':
				$message = esc_html__( 'Please enter your card number and try again.', 'visa-acceptance-solutions' );
				break;

			// card expiry.
			case 'card_expiry_invalid':
				$message = esc_html__( 'The card expiration date is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;
			case 'card_expiry_month_invalid':
				$message = esc_html__( 'The card expiration month is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;
			case 'card_expiry_year_invalid':
				$message = esc_html__( 'The card expiration year is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;
			case 'card_expiry_missing':
				$message = esc_html__( 'Please enter your card expiration date and try again.', 'visa-acceptance-solutions' );
				break;

			// bank.
			case 'bank_aba_invalid':
				$message = esc_html__( 'The bank routing number is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;
			case 'bank_account_number_invalid':
				$message = esc_html__( 'The bank account number is invalid, please re-enter and try again.', 'visa-acceptance-solutions' );
				break;

			/* decline reasons */
			case 'card_expired':
				$message = esc_html__( 'The provided card is expired, please use an alternate card or other form of payment.', 'visa-acceptance-solutions' );
				break;
			case 'card_declined':
				$message = esc_html__( 'We are unable to complete your order. Please check your payment details and try again.', 'visa-acceptance-solutions' );
				break;
			case 'insufficient_funds':
				$message = esc_html__( 'Your card issuer was unable to authorize your order. Please check with them before trying again', 'visa-acceptance-solutions' );
				break;
			case 'card_inactive':
				$message = esc_html__( 'The card is inactive or not authorized for card-not-present transactions, please use an alternate card or other form of payment.', 'visa-acceptance-solutions' );
				break;
			case 'credit_limit_reached':
				$message = esc_html__( 'Your card issuer was unable to authorize your order. Please check with them before trying again', 'visa-acceptance-solutions' );
				break;
			case 'csc_mismatch':
				$message = esc_html__( 'We are unable to complete your order. Please check your payment details and try again.', 'visa-acceptance-solutions' );
				break;
			case 'avs_mismatch':
				$message = esc_html__( 'The provided address does not match the billing address for cardholder. Please verify the address and try again.', 'visa-acceptance-solutions' );
				break;
			case 'declined_by_decision_manager':
				$message = esc_html__( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' );
				break;
		}

		return $message;
	}

	/**
	 * Sends the request data to js.
	 */
	public function get_payment_data() {
		wp_send_json( $this->get_payment_request() );
	}

	/**
	 * Gets the Cart order total.
	 *
	 * @param \WC_Cart $cart cart object.
	 *
	 * @return array
	 */
	protected function get_cart_totals( \WC_Cart $cart ) {
		$cart->calculate_totals();
		$cart_total = array(
			'subtotal' => $cart->subtotal_ex_tax,
			'discount' => $cart->get_cart_discount_total(),
			'shipping' => $cart->shipping_total,
			'fees'     => $cart->fee_total,
			'taxes'    => $cart->tax_total + $cart->shipping_tax_total,
		);
		return $cart_total;
	}
}
