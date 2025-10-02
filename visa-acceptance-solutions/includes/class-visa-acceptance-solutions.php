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

require_once plugin_dir_path( __FILE__ ) . 'class-visa-acceptance-payment-gateway-subscriptions.php';

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Solutions {
	/**
	 * The single instance of the class.
	 *
	 * @var $instance.
	 */
	protected static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {

		// add classes to WC Payment Methods.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'load_gateways' ) );

		require_once plugin_dir_path( __FILE__ ) . 'trait-visa-acceptance-payment-gateway-admin.php';
		require_once plugin_dir_path( __FILE__ ) . 'trait-visa-acceptance-payment-gateway-includes.php';
		require_once plugin_dir_path( __FILE__ ) . 'trait-visa-acceptance-payment-gateway-public.php';

		/**
		 * The payment method class that is used to define internationalization,
		 * admin-specific hooks, and public-facing site hooks.
		*/

		$this->run_all_payment_gateways();
	}

	/**
	 * Main Extension Instance.
	 * Ensures only one instance of the extension is loaded or can be loaded.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot clone instances of %s.', get_class( $this ) ), '3.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot unserialize instances of %s.', get_class( $this ) ), '3.0.0' );
	}


	/**
	 * Responsible for calling initalize method of Payment Gateways.
	 */
	public function run_all_payment_gateways() {
		$this->run_payment_gateways( 'class-visa-acceptance-payment-gateway-unified-checkout.php' );
	}

	/**
	 * Responsible for Loading all the Payment Gateways.
	 *
	 * @param string $path Gateway file path.
	 */
	public function run_payment_gateways( $path ) {
		require_once plugin_dir_path( __FILE__ ) . $path; // nosemgrep .
		$plugin_instance = new Visa_Acceptance_Payment_Gateway_Unified_Checkout();
		$subscriptions   = new Visa_Acceptance_Payment_Gateway_Subscriptions();
		if ( 'class-visa-acceptance-payment-gateway-unified-checkout.php' === $path ) {
			$plugin_instance->run();
			if ( $plugin_instance instanceof Visa_Acceptance_Payment_Gateway_Unified_Checkout && $plugin_instance->is_wc_subscriptions_activated() ) {
				$subscriptions->add_subscription_actions();

			}
		}
	}

	/**
	 * Adds any gateways supported by this plugin to the list of available payment gateways.
	 *
	 * @param array $gateways Array of Gateways.
	 * @return array
	 */
	public function load_gateways( $gateways ) {
		return array_merge( $gateways, $this->get_gateways() );
	}

	/**
	 * Gives the list of payment gateways.
	 *
	 * @since 24.1.0
	 * @return array
	 */
	public function get_gateways() {
		// adds all Payment methods classes name.
		return array( 'Visa_Acceptance_Payment_Gateway_Unified_Checkout' );
	}
}
