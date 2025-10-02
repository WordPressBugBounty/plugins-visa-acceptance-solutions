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
 * Visa Acceptance Authorization Reversal Request Class
 *
 * Handles Authorization Reversal requests
 * Provides common functionality to Credit Card & other transaction response classes
 */
class Visa_Acceptance_Authorization_Reversal_Request extends Visa_Acceptance_Request {

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	public $gateway;

	/**
	 * Authorization_Reversal_Request constructor.
	 *
	 * @param object $gateway Gateway object.
	 */
	public function __construct( $gateway ) {
		parent::__construct( $gateway );
		$this->gateway = $gateway;
	}

	/**
	 * Generates the reversal information for the request.
	 *
	 * @param string $amount amount to be reversed.
	 */
	public function get_reversal_information( $amount ) {
		$reversal_information = array(
			'amountDetails' => array(
				'totalAmount' => $amount,
			),
		);
		return $reversal_information;
	}

	/**
	 * Generates the order information for the request.
	 *
	 * @param object $order order object.
	 */
	public function get_order_information( $order ) {
		$order_information = array(
			'lineItems' => $this->get_items_information( $order ),
		);
		return $order_information;
	}
}
