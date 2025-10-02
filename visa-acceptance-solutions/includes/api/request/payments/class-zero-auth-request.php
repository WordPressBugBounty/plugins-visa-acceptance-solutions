<?php
/**
 * WooCommerce VISA_ACCEPTANCE_SOLUTIONS
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
 * Do not edit or add to this file if you wish to upgrade WooCommerce VISA_ACCEPTANCE_SOLUTIONS newer
 * versions in the future. If you wish to customize WooCommerce VISA_ACCEPTANCE_SOLUTIONS for your
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
		return array(
			// To randomly generate 5 char value.
			'code'    => strtoupper( wp_generate_password( VISA_ACCEPTANCE_VAL_FIVE, false, false ) ),
			'partner' => array(
				'developerId' => VISA_ACCEPTANCE_DEVELOPER_ID,
				'solutionId'  => VISA_ACCEPTANCE_SOLUTION_ID,
			),
		);
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
		return array(
			'transientTokenJwt' => $transient_token,
		);
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
}
