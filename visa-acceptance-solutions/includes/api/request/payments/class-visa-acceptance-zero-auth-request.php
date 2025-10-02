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
 * Do not edit or add to this file if you wish to upgrade WooCommerce Visa Acceptance Solutions newer
 * versions in the future. If you wish to customize WooCommerce Visa Acceptance Solutions for your
 * needs please refer to http://docs.woocommerce.com/document/visa-acceptance-solutions-payment-gateway/
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

/**
 * Visa Acceptance Zero Auth Request Class
 *
 * Handles Zero Auth requests
 * Provides common functionality to Credit Card & other transaction request classes
 */
class Visa_Acceptance_Zero_Auth_Request extends Visa_Acceptance_Request {

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	public $gateway;

	/**
	 * Zero_Auth_Request constructor.
	 *
	 * @param object $gateway Gateway Variable.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Generates the client reference information for the request.
	 *
	 * @return array
	 */
	public function get_client_reference_information() {
		$client_reference_information_data = array(
			// To randomly generate 5 char value.
			'code'               => strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) ),
			'partner'            => array(
				'developerId' => VISA_ACCEPTANCE_DEVELOPER_ID,
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			),
			'applicationName'    => VISA_ACCEPTANCE_PLUGIN_APPLICATION_NAME . VISA_ACCEPTANCE_SPACE . VISA_ACCEPTANCE_PLUGIN_API_TYPE,
			'applicationVersion' => VISA_ACCEPTANCE_PLUGIN_VERSION,
		);
		return $client_reference_information_data;
	}

	/**
	 * Generates the order information for the request.
	 *
	 * @param object $customer customer object.
	 *
	 * @return array
	 */
	public function get_order_information( $customer ) {
		$order_information = array(
			'billTo'        => array(
				'firstName'          => $customer->get_billing_first_name(),
				'lastName'           => $customer->get_billing_last_name(),
				'address1'           => $customer->get_billing_address_1(),
				'administrativeArea' => $customer->get_billing_state(),
				'postalCode'         => $customer->get_billing_postcode(),
				'locality'           => $customer->get_billing_city(),
				'country'            => $customer->get_billing_country(),
				'email'              => $customer->get_billing_email(),
				'phoneNumber'        => $customer->get_billing_phone(),
			),
			'amountDetails' => array(
				'totalAmount' => VISA_ACCEPTANCE_ZERO_AMOUNT,
				'currency'    => get_woocommerce_currency(),
			),
		);
		return $order_information;
	}

	/**
	 * Generates the token information for the request.
	 *
	 * @param string $transient_token transient token.
	 *
	 * @return array
	 */
	public function get_token_information( $transient_token ) {
		$token_information = array(
			'transientTokenJwt' => $transient_token,
		);
		return $token_information;
	}

	/**
	 * Generates the device information for the request.
	 *
	 * @param string $session_id Session ID generated.
	 *
	 * @return array
	 */
	public function get_device_information( $session_id ) {

		$device_information = array(
			'fingerprintSessionId' => $session_id,
		);
		return $device_information;
	}

	/**
	 * Get processing information.
	 *
	 * @param array  $action_list action list.
	 * @param object $order order details.
	 *
	 * @return array $processing_information
	 */
	public function get_processing_information( $action_list, $order ) {
		$processing_information                         = array();
		$processing_information['actionList']           = $action_list;
		$processing_information['authorizationOptions'] = array(
			'initiator' => array(
				'credentialStoredOnFile' => true,
				'type'                   => 'customer',
			),
		);
		if ( ! empty( $order ) && $order instanceof \WC_Order ) {
			$payment_solution = $order->get_meta( VISA_ACCEPTANCE_WC_UC_ID . 'payment_solution', true, VISA_ACCEPTANCE_EDIT );
			if ( ! empty( $payment_solution ) ) {
				$processing_information['paymentSolution'] = $payment_solution;
			}
		}
		return $processing_information;
	}

	/**
	 * Get buyer information.
	 *
	 * @param object $order order details.
	 *
	 * @return array $buyer_information
	 */
	public function get_buyer_information( $order ) {
		$buyer_information = array();
		if ( ! empty( $order ) && $order instanceof \WC_Order ) {
			$buyer_information['merchantCustomerId'] = $order->get_user_id() ? strval( $order->get_user_id() ) : 'wc-vas-guest-' . $order->get_id();
		} else {
			$buyer_information['merchantCustomerId'] = get_current_user_id();
		}
		return $buyer_information;
	}
}
