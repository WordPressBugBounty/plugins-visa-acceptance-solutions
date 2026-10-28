<?php
/**
 * ECheck Payment Token Class
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes/api/payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Visa_Acceptance_Payment_Token_ECheck' ) ) {
    /**
     * ECheck Payment Token Class
     */
    class Visa_Acceptance_Payment_Token_ECheck extends WC_Payment_Token {
        /**
         * Token type.
         *
         * @var string
         */
        protected $type = 'eCheck';

        /**
         * Extra token data.
         *
         * @var array
         */
        protected $extra_data = array(
            'last4'        => '',
            'account_type' => '',
        );
       
        /**
         * Get the display name for the token.
         *
         * @param string $deprecated Deprecated argument, unused.
         */
        public function get_display_name( $deprecated = '' ) {
			/* translators: 1: Account type 2: Last 4 digits of the account number */
            return sprintf( __( '%1$s ending in %2$s', 'visa-acceptance-solutions' ), $this->get_account_type(), $this->get_last4() );
        }
       
        /**
         * Get the hook prefix for the token.
         */
        protected function get_hook_prefix() {
            return 'woocommerce_payment_token_echeck_get_';
        }
       
        /**
         * Validates the eCheck token.
         *
         * @return bool
         */
        public function validate() {
            return parent::validate() && $this->get_last4() && $this->get_account_type();
        }
       
        /**
         * Get the last 4 digits of the account number.
         *
         * @param string $context What the value is for. Valid values are 'view' and 'edit'.
         */
        public function get_last4( $context = 'view' ) {
            return $this->get_prop( 'last4', $context );
        }
       
        /**
         * Set the last 4 digits of the account number.
         *
         * @param string $last4 Last 4 digits of the account number.
         */
        public function set_last4( $last4 ) {
            $this->set_prop( 'last4', $last4 );
        }
       
        /**
         * Get the account type.
         *
         * @param string $context What the value is for. Valid values are 'view' and 'edit'.
         */
        public function get_account_type( $context = 'view' ) {
            return $this->get_prop( 'account_type', $context );
        }
       
        /**
         * Set the account type.
         *
         * @param string $type Account type.
         */
        public function set_account_type( $type ) {
            $this->set_prop( 'account_type', $type );
        }
    }
}
