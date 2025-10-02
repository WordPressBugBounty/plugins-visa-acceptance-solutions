<?php
/**
 * WooCommerce Visa Acceptance Solutions
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@visa-acceptance-solutions.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Visa Acceptance Solutions to newer
 * versions in the future.
 *
 * @package    Visa_Acceptance_Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include all the necessary dependencies.
 */
require_once __DIR__ . '/../../class-visa-acceptance-request.php';
require_once __DIR__ . '/../../class-visa-acceptance-api-client.php';

use CyberSource\Model\Upv1capturecontextsCaptureMandate;
use CyberSource\Model\Upv1capturecontextsOrderInformation;

/**
 * Visa Acceptance Credit Card API Key Generation Request Class
 *
 * Handles key generation requests
 */
class Visa_Acceptance_Key_Generation_Request extends Visa_Acceptance_Request {

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	public $gateway;

	/**
	 * Key_Generation_Request constructor.
	 *
	 * @param object $gateway Gateway Variable.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Gives admin orders checkout total amount
	 *
	 * @return array
	 */
	public function get_admin_checkout_total_amount() {
		$get_data     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$response     = array(
			'total_amount'            => VISA_ACCEPTANCE_ZERO_AMOUNT,
			'is_admin_order_pay_page' => isset( $get_data['pay_for_order'], $get_data['key'] ),
		);
		$total_amount = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$base_url     = $this->get_base_url();
		// Logic for updating request for Pay_For_Order Page.
		if ( $response['is_admin_order_pay_page'] ) {
			$order_id     = null !== ( get_query_var( 'order-pay' ) ) ? get_query_var( 'order-pay' ) : get_query_var( get_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' ) );
			$order        = isset( $order_id ) ? wc_get_order( $order_id ) : null;
			$total_amount = isset( $order ) ? $order->get_total() : VISA_ACCEPTANCE_ZERO_AMOUNT;
		}
		$response['total_amount'] = $total_amount;
		return $response;
	}


	/**
	 * Builds request for Capture Context Generation in UC.
	 */
	public function get_uc_request() {
		$total_amount         = VISA_ACCEPTANCE_ZERO_AMOUNT;
		$payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
		$payment_method       = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting           = get_option( $payment_method, array() );

		if ( ! empty ($uc_setting['enabled_payment_methods'] ) ) {
			foreach($uc_setting['enabled_payment_methods'] as $digital_method) {
				if ('enable_gpay' === $digital_method) {
					array_push( $payment_method_array, VISA_ACCEPTANCE_GOOGLEPAY );
				} if ('enable_vco' === $digital_method) {
					array_push( $payment_method_array, VISA_ACCEPTANCE_CLICKTOPAY );
				} if ('enable_apay' === $digital_method) {
					array_push( $payment_method_array, VISA_ACCEPTANCE_APPLEPAY );
				}
			}
		}
		$checkout_total_amount = $this->get_admin_checkout_total_amount();
		if ( $checkout_total_amount['is_admin_order_pay_page'] ) {
			$total_amount = $checkout_total_amount['total_amount'];
		} else {
			$total_amount = isset( WC()->cart ) ? WC()->cart->get_totals()['total'] : $total_amount;
		}
		WC()->session->set( "wc_{$this->gateway->id }_capture_context_total_amount", wc_clean( $total_amount ) );
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Builds request for Zero Dollar Auth Capture Context Generation in UC.
	 *
	 * @return array $capture_context_payload payload.
	 */
	public function get_zero_uc_request() {

		$payment_method_array = array( VISA_ACCEPTANCE_PANENTRY );
		$total_amount         = WC()->cart->get_totals()['total'];
		if ( ! is_add_payment_method_page() && ( WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ||
		( VISA_ACCEPTANCE_ZERO_AMOUNT === $total_amount && WC_Subscriptions_Cart::cart_contains_subscription() ) ) ) {
			$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
			$uc_setting     = get_option( $payment_method, array() );

			if ( ! empty 	($uc_setting['enabled_payment_methods'] ) ) {
				foreach($uc_setting['enabled_payment_methods'] as $digital_method) {
					if ('enable_gpay' === $digital_method) {
						array_push( $payment_method_array, VISA_ACCEPTANCE_GOOGLEPAY );
					} if ('enable_vco' === $digital_method) {
						array_push( $payment_method_array, VISA_ACCEPTANCE_CLICKTOPAY );
					} if ('enable_apay' === $digital_method) {
						array_push( $payment_method_array, VISA_ACCEPTANCE_APPLEPAY );
					}
				}
            }
		}
		$total_amount            = VISA_ACCEPTANCE_PLACEHOLDER_AMOUNT;
		$capture_context_payload = $this->get_capture_context_request( $payment_method_array, $total_amount );
		return $capture_context_payload;
	}

	/**
	 * Generates capture context request.
	 *
	 * @param array $payment_method_array   payment method array.
	 * @param mixed $total_amount total     amount.
	 * @return array $payload
	 */
	public function get_capture_context_request( $payment_method_array, $total_amount ) {
		$base_url       = $this->get_base_url();
		$payment_method = VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->id . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS;
		$uc_setting     = get_option( $payment_method, array() );
		$allowed_cards  = is_array( $uc_setting['card_types'] ) ? $uc_setting['card_types'] : VISA_ACCEPTANCE_DEFAULT_CARD_TYPES;

		$order_information = new Upv1capturecontextsOrderInformation();
		$order_information->setAmountDetails(
			array(
				'totalAmount' => $total_amount,
				'currency'    => get_woocommerce_currency(),
			)
		);
		$transient_token_response_options = new \CyberSource\Model\Microformv2sessionsTransientTokenResponseOptions(
			array(
				'includeCardPrefix' => false,
			)
		);

		$capture_mandate = new Upv1capturecontextsCaptureMandate();
			$capture_mandate->setBillingType( VISA_ACCEPTANCE_UC_BILLING_TYPE );
			$capture_mandate->setRequestEmail( false );
			$capture_mandate->setRequestPhone( false );
			$capture_mandate->setRequestShipping( false );
			$capture_mandate->setShowAcceptedNetworkIcons( true );

		$payload = array(
			'targetOrigins'                 => array( $base_url ),
			'allowedCardNetworks'           => $allowed_cards,
			'allowedPaymentTypes'           => $payment_method_array,
			'country'                       => WC()->countries->get_base_country(),
			'locale'                        => get_locale(),
			'captureMandate'                => $capture_mandate,
			'orderInformation'              => $order_information,
			'clientVersion'                 => VISA_ACCEPTANCE_UC_CLIENT_VERSION,
			'transientTokenResponseOptions' => $transient_token_response_options,
		);
		return $payload;
	}

	/**
	 * Gets base url.
	 *
	 * @return string $base_url base url.
	 */
	private function get_base_url() {

		$complete_url = wp_parse_url( get_site_url() );
		if ( ! empty( $complete_url['port'] ) ) {
			$base_url = $complete_url['scheme'] . VISA_ACCEPTANCE_COLON_SLASH . $complete_url['host'] . VISA_ACCEPTANCE_COLON . $complete_url['port'];
		} else {
			$base_url = $complete_url['scheme'] . VISA_ACCEPTANCE_COLON_SLASH . $complete_url['host'];
		}
		return $base_url;
	}
}
