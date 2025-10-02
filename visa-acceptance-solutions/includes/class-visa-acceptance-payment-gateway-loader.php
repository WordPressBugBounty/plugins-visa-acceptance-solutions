<?php
/**
 * Register all actions and filters for the plugin
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *
 * Visa Acceptance Gateway Loader Class
 *
 * Handles action, filter and other functionality
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Loader {

	/**
	 * The array of actions registered with WordPress.
	 *
	 * @since    24.1.0
	 * @var      array    $actions    The actions registered with WordPress to fire when the plugin loads.
	 */
	protected $actions;

	/**
	 * The array of filters registered with WordPress.
	 *
	 * @since    24.1.0
	 * @var      array    $filters    The filters registered with WordPress to fire when the plugin loads.
	 */
	protected $filters;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @var      string    $wc_payment_gateway_id    The string used to uniquely identify this plugin.
	 */
	protected $wc_payment_gateway_id;

	/**
	 * Initialize the collections used to maintain the actions and filters.
	 */
	public function __construct() {

		$this->wc_payment_gateway_id = VISA_ACCEPTANCE_PLUGIN_DOMAIN;
		$this->actions               = array();
		$this->filters               = array(
			array(
				'hook'          => 'plugin_action_links_' . $this->get_plugin_filename(),
				'component'     => $this,
				'callback'      => 'plugin_action_links',
				'priority'      => 10,
				'accepted_args' => 1,
			),
		);
	}

	/**
	 * Add a new action to the collection to be registered with WordPress.
	 *
	 * @param    string $hook             The name of the WordPress action that is being registered.
	 * @param    object $component        A reference to the instance of the object on which the action is defined.
	 * @param    string $callback         The name of the function definition on the $component.
	 * @param    int    $priority         Optional. The priority at which the function should be fired. Default is 10.
	 * @param    int    $accepted_args    Optional. The number of arguments that should be passed to the $callback. Default is 1.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Add a new filter to the collection to be registered with WordPress.
	 *
	 * @param    string $hook             The name of the WordPress filter that is being registered.
	 * @param    object $component        A reference to the instance of the object on which the filter is defined.
	 * @param    string $callback         The name of the function definition on the $component.
	 * @param    int    $priority         Optional. The priority at which the function should be fired. Default is 10.
	 * @param    int    $accepted_args    Optional. The number of arguments that should be passed to the $callback. Default is 1.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * A utility function that is used to register the actions and hooks into a single
	 * collection.
	 *
	 * @param    array  $hooks            The collection of hooks that is being registered (that is, actions or filters).
	 * @param    string $hook             The name of the WordPress filter that is being registered.
	 * @param    object $component        A reference to the instance of the object on which the filter is defined.
	 * @param    string $callback         The name of the function definition on the $component.
	 * @param    int    $priority         The priority at which the function should be fired.
	 * @param    int    $accepted_args    The number of arguments that should be passed to the $callback.
	 * @return   array                                  The collection of actions and filters registered with WordPress.
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {

		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return $hooks;
	}

	/**
	 * Register the filters and actions with WordPress.
	 */
	public function run() {

		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}

		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}

	/**
	 * Gets the full plugin filename.
	 *
	 * @return string
	 */
	private function get_plugin_filename() {

		$slug = dirname( dirname( plugin_basename( __FILE__ ) ) );

		return "{$slug}/{$slug}.php";
	}

	/**
	 * Override the parent's action links to remove a few options in the plugin's page.
	 *
	 * @param array $actions actions.
	 * @return array
	 */
	public function plugin_action_links( $actions ) {
		$gateways       = array( VISA_ACCEPTANCE_UC_ID );
		$custom_actions = array();

			// settings url(s).
		foreach ( $gateways as $gateway_id ) {
			$custom_actions[ 'configure_' . $gateway_id ] = $this->get_settings_link( $gateway_id );
		}

			// documentation url if any.
		if ( $this->get_documentation_url() ) {
			/* translators: Docs as in Documentation */
			$custom_actions['docs'] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $this->get_documentation_url(), esc_html__( 'Docs', 'visa-acceptance-solutions' ) );
		}

			// support url if any.
		if ( $this->get_support_url() ) {
			$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', $this->get_support_url(), esc_html_x( 'Support', 'noun', 'visa-acceptance-solutions' ) );
		}

			// review url if any.
		if ( $this->get_reviews_url() ) {
			$custom_actions['review'] = sprintf( '<a href="%s">%s</a>', $this->get_reviews_url(), esc_html_x( 'Review', 'verb', 'visa-acceptance-solutions' ) );
		}

			// add the links to the front of the actions list.
			return array_merge( $custom_actions, $actions );
	}

	/**
	 * Gets the link for gateway settings page to configure.
	 *
	 * @param object $gateway_id gateway id.
	 *
	 * @return array
	 */
	public function get_settings_link( $gateway_id = null ) {
		$label = __( 'Configure Payment Settings', 'visa-acceptance-solutions' );
		return sprintf(
			'<a href="%s">%s</a>',
			$this->get_payment_gateway_configuration_url( $gateway_id ),
			esc_html( $label )
		);
	}

	/**
	 * Returns the admin configuration url for a gateway
	 *
	 * @param string $gateway_id the gateway ID.
	 * @return string
	 */
	public function get_payment_gateway_configuration_url( $gateway_id ) {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gateway_id );
	}



	/**
	 * Gets the plugin support URL.
	 *
	 * @return string
	 */
	public function get_support_url() {

		return 'https://woocommerce.com/my-account/contact-support/?form=ticket';
	}

	/**
	 * Gets the plugin reviews page URL.
	 *
	 * Used for the 'Reviews' plugin action and review prompts.
	 *
	 * @return string
	 */
	public function get_reviews_url() {
		// SNIFF check.
		return 'https://woocommerce.com/products/visa-acceptance-solutions-payment-gateway/#comments';
	}

	/**
	 * Gets the plugin documentation url, which for Visa Acceptance Solutions is non-standard.
	 *
	 * @return string documentation URL
	 */
	public function get_documentation_url() {
		return 'https://docs.woocommerce.com/document/visa-acceptance-solutions-payment-gateway/';
	}
}
