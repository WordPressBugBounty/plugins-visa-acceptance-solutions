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
 * Visa Acceptance Authorization Request Class
 *
 * Handles Authorization requests
 * Provides common functionality to Credit Card & other transaction response classes
 */
class Visa_Acceptance_Authorization_Request extends Visa_Acceptance_Request {

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	public $gateway;

	/**
	 * Authorization_Request constructor.
	 *
	 * @param object $gateway Gateway Variable.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Generates the payment information for the request.
	 *
	 * @param array  $token_data Token Data.
	 * @param string $saved_card_cvv Saved Card CVV.
	 */
	public function get_payment_information_saved_card( $token_data, $saved_card_cvv ) {
		$payment_information = array(
			'paymentInstrument' => array(
				'id' => $token_data['token_information']['payment_instrument_id'],
			),
			'customer'          => array(
				'id' => $token_data['token_information']['id'],
			),
			'card'              => array(
				'securityCode'           => $saved_card_cvv,
				'typeSelectionIndicator' => VISA_ACCEPTANCE_VAL_ONE,
			),
		);
		return $payment_information;
	}
}
