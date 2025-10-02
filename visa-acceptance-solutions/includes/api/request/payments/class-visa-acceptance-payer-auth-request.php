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
 * Visa Acceptance API Payer Auth Request Class
 *
 * Handles Visa Acceptance payer auth requests
 */
class Visa_Acceptance_Payer_Auth_Request extends Visa_Acceptance_Request {

	/**
	 * Enrollment_Request constructor.
	 */
	public function __construct() {
	}

		/**
		 * Generates the consumer authetication information for the request.
		 *
		 * @param string $reference_id Reference ID for Payer Auth.
		 * @param string $return_url Return URL.
		 * @param string $sca_case Flag to verify SCA.
		 * @param string $token_checkbox Tokenization Enabled or not.
		 * @param array  $settings Settings Array.
		 */
	public function get_enroll_consumer_authentication_info( $reference_id, $return_url, $sca_case, $token_checkbox, $settings ) {
		$consumer_authentication_info = array(
			'referenceId' => $reference_id,
			'returnUrl'   => $return_url,
		);

		if ( VISA_ACCEPTANCE_YES === $sca_case || ( VISA_ACCEPTANCE_YES === $settings['enable_saved_sca'] && VISA_ACCEPTANCE_YES === $token_checkbox ) ) {
			$consumer_authentication_info['challengeCode'] = VISA_ACCEPTANCE_SCA_CHALLANGE_CODE;
		}

		return $consumer_authentication_info;
	}

	/**
	 * Gets validation customer authentication information.
	 *
	 * @param mixed $validation_tid Validation ID.
	 * @param mixed $pareq          Payment authentication request.
	 * @param mixed $sca_case       SCA case.
	 * @return array
	 */
	public function get_validation_consumer_authentication_info( $validation_tid, $pareq, $sca_case ) {
		if ( ! empty( $pareq ) && VISA_ACCEPTANCE_YES === $sca_case ) {
				$consumer_authentication_information = array(
					'authenticationTransactionId' => $validation_tid,
					'signedPares'                 => $pareq,
				);
		} else {
			$consumer_authentication_information = array(
				'authenticationTransactionId' => $validation_tid,
				'signedPares'                 => null,
			);
		}

		return $consumer_authentication_information;
	}
}
