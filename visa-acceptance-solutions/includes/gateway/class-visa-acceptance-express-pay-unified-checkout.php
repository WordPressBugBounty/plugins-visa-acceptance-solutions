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
 * Visa Acceptance Express Pay Unified Checkout Class
 * Express Pay Unified Checkout requests
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Express_Pay_Unified_Checkout extends AbstractPaymentMethodType {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;
	use Visa_Acceptance_Payment_Gateway_Public_Trait;
	use Visa_Acceptance_Payment_Gateway_Includes_Trait;

	/**
	 * This property is a string used to reference your payment method. It is important to use the same name as in your
	 * client-side JavaScript payment method registration.
	 *
	 * @var string
	 */
	protected $name = VISA_ACCEPTANCE_EXPRESS_PAY_UC_ID;

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
	 * Returns an array of key=>value pairs of data made available to the payment methods script client side.
	 * 
	 * This data will be available client side via `wc.wcSettings.getSetting`. So for instance if you assigned `stripe` as the 
	 * value of the `name` property for this class, client side you can access any data via: 
	 * `wc.wcSettings.getSetting( 'stripe_data' )`. That would return an object matching the shape of the associative array 
	 * you returned from this function.
	 *
	 * @return array
	 */
	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$general_settings    = $this->gateway->get_config_settings();
		$express_pay_payment_method_data = array();
		$enable_tokenization = false;
		$force_tokenization  = false;
		$subscription_order = false;
		$uc_settings         = get_option( VISA_ACCEPTANCE_WOOCOMMERCE_UNDERSCORE . $this->gateway->get_id() . VISA_ACCEPTANCE_UNDERSCORE_SETTINGS, array() );
		$payment_gateway_unified_checkout = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$enable_gpay = ( isset( $uc_settings['enabled_payment_methods'] ) && is_array( $uc_settings['enabled_payment_methods'] ) && in_array( 'enable_gpay', $uc_settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_apay = ( isset( $uc_settings['enabled_payment_methods'] ) && is_array( $uc_settings['enabled_payment_methods'] ) && in_array( 'enable_apay', $uc_settings['enabled_payment_methods'], true ) ) ? true : false;
		$enable_paze = ( isset( $uc_settings['enabled_payment_methods'] ) && is_array( $uc_settings['enabled_payment_methods'] ) && in_array( 'enable_paze', $uc_settings['enabled_payment_methods'], true ) ) ? true : false;
		if ( isset( $uc_settings['enabled'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enabled'] && ( is_checkout() || is_admin() ) ) {
			$enable_tokenization  = ( isset( $uc_settings['tokenization'] ) && VISA_ACCEPTANCE_YES === $general_settings['tokenization'] && is_user_logged_in() ) ? true : false;
			$payer_auth_enabled   = ( isset( $uc_settings['enable_threed_secure'] ) && VISA_ACCEPTANCE_YES === $uc_settings['enable_threed_secure'] ) ? $uc_settings['enable_threed_secure'] : VISA_ACCEPTANCE_STRING_EMPTY;

			$subscription_active              = $payment_gateway_unified_checkout->is_wc_subscriptions_activated();
			if ( $subscription_active ) {
				$subscription_order  = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
				$force_tokenization = $this->gateway->is_subscriptions_activated && ( WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal() || WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
				if ( $force_tokenization ) {
					$enable_tokenization = false;
				}
		}

		$express_pay_payment_method_data = array(
			'title'                           => __( 'Express Pay', 'visa-acceptance-solutions' ),
			'supports'                        => $this->get_supported_features(),
			'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'enable_tokenization'             => $enable_tokenization,
				'force_tokenization'              => $force_tokenization,
				'payer_auth_enabled'              => $payer_auth_enabled,
				'enable_gpay' 					  => $enable_gpay,
				'enable_apay'			 		  => $enable_apay,
				'enable_paze'			 		  => $enable_paze,
				'subscription_order'			  => $subscription_order,
				'is_user_logged_in'				  => is_user_logged_in(),
				'enabled_payment_methods'         => ! empty( $uc_settings['enabled_payment_methods'] ) ? $uc_settings['enabled_payment_methods'] : array(),
				'express_pay_uc_id'               => VISA_ACCEPTANCE_EXPRESS_PAY_UC_ID,
				'form_load_error'                 => __( 'Unable to load the payment form. Please contact customer care for any assistance.', 'visa-acceptance-solutions' ),
				'cvv_error'                       => __( 'Please enter valid Security Code.', 'visa-acceptance-solutions' ),
				'failure_error'                   => __( 'Unable to complete your order. Please check your details and try again.', 'visa-acceptance-solutions' ),
				// Bypassing condition for idle stage for version lower than 8.6.
				'isVersionSupported'              => version_compare( WC_VERSION, VISA_ACCEPTANCE_WC_VERSION_EIGHT_SIX_ZERO, '>=' ),
				'is_subscriptions_tokenization_enabled' => $this->gateway->is_subscriptions_activated,
			);
		}
		return $express_pay_payment_method_data;
	}


	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 * 
	 * In this function you should register your payment method scripts (using `wp_register_script`) and then return the 
	 * script handles you registered with. This will be used to add your payment method as a dependency of the checkout script 
	 * and thus take sure of loading it correctly. 
	 * 
	 * Note that you should still make sure any other asset dependencies your script has are registered properly here, if 
	 * you're using Webpack to build your assets, you may want to use the WooCommerce Webpack Dependency Extraction Plugin
	 * (https://www.npmjs.com/package/@woocommerce/dependency-extraction-webpack-plugin) to make this easier for you.
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

		wp_register_script(
			'wc-express-pay-payment-method-unified-checkout',
			$this->gateway->get_plugin_url() . '/../includes/build/index-unified-checkout.js',
			array( 'jquery' ),
			$version,
			true
		);
		return array( 'wc-express-pay-payment-method-unified-checkout' );
	}

    /**
	 * Initializes the payment method.
	 * 
	 * This function will get called during the server side initialization process and is a good place to put any settings
	 * population etc. Basically anything you need to do to initialize your gateway. 
	 * 
	 * Note, this will be called on every request so don't put anything expensive here.
	 */
	public function initialize() {
		$this->settings = get_option( VISA_ACCEPTANCE_EXPRESS_PAY_UC_ID, array() );
		$this->gateway  = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
	}
}