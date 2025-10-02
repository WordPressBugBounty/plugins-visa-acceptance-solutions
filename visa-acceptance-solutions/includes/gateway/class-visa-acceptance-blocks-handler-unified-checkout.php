<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

require_once plugin_dir_path( __DIR__ ) . 'api/payments/class-visa-acceptance-key-generation.php';
require_once plugin_dir_path( __DIR__ ) . 'class-visa-acceptance-payment-gateway-unified-checkout.php';
require_once plugin_dir_path( __DIR__ ) . 'api/payments/class-visa-acceptance-payment-methods.php';

/**
 *
 * Visa Acceptance Blocks Handler Unified Checkout Class
 * Handles Blocks Unified Checkout requests
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Blocks_Handler_Unified_Checkout extends AbstractPaymentMethodType {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * Payment Gateway Name
	 *
	 * @var string $name
	 */
	protected $name = VISA_ACCEPTANCE_UC_ID;

	/**
	 * Plugin
	 *
	 * @var Plugin $plugin
	 */
	protected $plugin = null;

	/**
	 * Gateway
	 *
	 * @var Object $gateway gateway object.
	 */
	protected $gateway = null;

	/**
	 * Init Square Cart and Checkout Blocks handler class
	 */
	public function __construct() {
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$general_settings    = $this->gateway->get_config_settings();
		$payment_method_data = array();
		$enable_tokenization = false;
		$token_type          = array();
		$last_four           = array();
		$force_tokenization  = false;
		$uc_settings         = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->get_id() . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
		$token_key 			 = isset( $uc_settings['test_api_key'] ) ? $uc_settings['test_api_key'] : '';
		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		if ( isset( $uc_settings['enabled'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enabled'] && is_checkout() ) {
			$saved_card_token_cvv = ( isset( $uc_settings['enable_token_csc'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_token_csc'] ) ? true : false;
			$enable_tokenization  = ( isset( $uc_settings['tokenization'] ) && VISA_ACCEPTANCE_YES === $general_settings['tokenization'] && is_user_logged_in() ) ? true : false;
			$payer_auth_enabled   = ( isset( $uc_settings['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_threed_secure'] ) ? $uc_settings['enable_threed_secure'] : VISA_ACCEPTANCE_STRING_EMPTY;
			$payment_method       = new Visa_Acceptance_Payment_Methods( $this );
			$customer_data        = $payment_method->get_order_for_add_payment_method();
			$core_tokens          = \WC_Payment_Tokens::get_customer_tokens( $customer_data['customer_id'], $this->gateway->get_id() );
			foreach ( $core_tokens as $token ) {
				$data = $token->get_data();
				if ( $data['token'] ) {
					$token_type[ $token->get_id() ] = $data['card_type'];
					$last_four[ $token->get_id() ]  = $data['last4'];
				}
			}
			$subscription_active              = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
			if ( $subscription_active ) {
				$force_tokenization = $this->gateway->is_subscriptions_activated && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
				if ( $force_tokenization ) {
					$enable_tokenization = false;
				}
			}

			$payment_method_data = array(
				'title'                           => $uc_settings['title'],
				'description'                     => $uc_settings['description'],
				'supports'                        => $this->get_supported_features(),
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'enable_tokenization'             => $enable_tokenization,
				'force_tokenization'              => $force_tokenization,
				'last_four'                       => $last_four,
				'token_type'                      => $token_type,
				'payer_auth_enabled'              => $payer_auth_enabled,
				'saved_card_cvv'                  => $saved_card_token_cvv,
				'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
				'token_key'                       => $token_key,
				'encrypt_const'                   => __( 'encrypt', 'visa-acceptance-solutions' ),
				'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
				'cvv_error'                       => __( 'Please enter valid Security Code.', 'visa-acceptance-solutions' ),
				'failure_error'                   => __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' ),
				// Bypassing condition for idle stage for version lower than 8.6.
				'isVersionSupported'              => version_compare( WC_VERSION, VISA_ACCEPTANCE_WC_VERSION_EIGHT_SIX_ZERO, '>=' ),
			);
		}
		return $payment_method_data;
	}


	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {

		$asset_path   = untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/../build/index-unified-checkout-asset.php';
		$version      = VISA_ACCEPTANCE_PLUGIN_VERSION;
		$dependencies = array();

		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] ) ? $asset['version'] : $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] ) ? $asset['dependencies'] : $dependencies;
		}
		wp_enqueue_style( 'wc-unified-checkout-cart-checkout-block', $this->gateway->get_plugin_url() . '/../public/css/visa-acceptance-payment-gateway-blocks.css', array(), $version );

		// Adding Images for Available Card Logos Logic.
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-visa', $this->gateway->get_plugin_url() . '/../public/img/card-visa.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-mastercard', $this->gateway->get_plugin_url() . '/../public/img/card-mastercard.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-amex', $this->gateway->get_plugin_url() . '/../public/img/card-amex.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-discover', $this->gateway->get_plugin_url() . '/../public/img/card-discover.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-dinerclub', $this->gateway->get_plugin_url() . '/../public/img/card-dinersclub.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-jcb', $this->gateway->get_plugin_url() . '/../public/img/card-jcb.png', array(), $version );
		wp_enqueue_style( 'wc-credit-card-cart-checkout-block-maestro', $this->gateway->get_plugin_url() . '/../public/img/card-maestro.png', array(), $version );

		wp_register_script(
			'wc-payment-method-unified-checkout',
			$this->gateway->get_plugin_url() . '/../includes/build/index-unified-checkout.js',
			array( 'jquery' ),
			$version,
			true
		);
		return array( 'wc-payment-method-unified-checkout' );
	}

	/**
	 * Initializes the payment method type.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . VISA_ACCEPTANCE_UC_ID . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
		$this->gateway  = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
	}
}
