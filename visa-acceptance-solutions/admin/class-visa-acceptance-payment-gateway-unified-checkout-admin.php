<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include all the necessary dependencies.
 */
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-auth-reversal.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/api/payments/class-visa-acceptance-refund.php';

/**
 * Visa Acceptance admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/admin
 */
class Visa_Acceptance_Payment_Gateway_Unified_Checkout_Admin {

	use Visa_Acceptance_Payment_Gateway_Admin_Trait;

	/**
	 * The ID of this plugin.
	 *
	 * @var      string    $id    The ID of this plugin.
	 */
	private $id;

	/**
	 * The version of this plugin.
	 *
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The gateway object of this plugin.
	 *
	 * @var      object    $gateway    The current payment gateways object.
	 */
	private $gateway;

	/**
	 * The shared settings object of this plugin.
	 *
	 * @var      object    $shared_settings    The shared settings object.
	 */
	private $shared_settings;

	/**
	 * The environment  object of this plugin.
	 *
	 * @var array   $environment   environment  object.
	 */
	private $environments;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $wc_payment_gateway_id       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 * @param      object $gateway The current payment gateways object.
	 */
	public function __construct( $wc_payment_gateway_id, $version, $gateway ) {

		$this->id              = $wc_payment_gateway_id;
		$this->version         = $version;
		$this->gateway         = $gateway;
		$this->shared_settings = array( 'merchant_id', 'api_key', 'api_shared_secret', 'test_merchant_id', 'test_api_key', 'test_api_shared_secret', 'tokenization_profile_id', 'enable_decision_manager', 'organization_id' );
	}


	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix Hook URL.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'post.php' === $hook_suffix || 'woocommerce_page_wc-orders' === $hook_suffix ) {
		// Sanitize and validate request data using proper WordPress functions.
		$post_id = null;
		$nonce   = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		// Verify nonce before using GET parameters.
		if ( ( isset( $_GET['post'] ) && ! empty( $_GET['post'] ) ) || ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) ) {
			if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'visa_acceptance_admin_action' ) ) {
				return; 
			}
		}

		if ( isset( $_GET['post'] ) && ! empty( $_GET['post'] ) ) {
			$post_id = absint( sanitize_text_field( wp_unslash( $_GET['post'] ) ) );
		} elseif ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) { 
			$post_id = absint( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ); 
		}

		if ( ! $post_id || $post_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_payment_method( VISA_ACCEPTANCE_EDIT ) === $this->gateway->get_id() || VISA_ACCEPTANCE_SV_GATEWAY_ID === $order->get_payment_method( 'edit' ) ) {
			$this->enqueue_edit_order_assets( $order );
		}
		}
	}
	/**
	 * Enqueues the assets for the Edit Order screen.
	 *
	 * @param \WC_Order $order order object.
	 */
	public function enqueue_edit_order_assets( \WC_Order $order ) {

		wp_enqueue_script( 'wc-payment-gateway-unified-checkout-admin-order', plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-payment-gateway-unified-checkout-admin.js', array( 'jquery' ), VISA_ACCEPTANCE_PLUGIN_VERSION, true );

		wp_localize_script(
			'wc-payment-gateway-unified-checkout-admin-order',
			'wc_payment_gateway_admin_order',
			array(
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'gateway_id'                      => $order->get_payment_method( VISA_ACCEPTANCE_EDIT ),
				'order_id'                        => $order->get_id(),
				'capture_message'                 => __( 'Are you sure you wish to process this capture? The action cannot be reversed.', 'visa-acceptance-solutions' ),
				'capture_action'                  => VISA_ACCEPTANCE_WC_CAPTURE_ACTION,
				'capture_nonce'                   => wp_create_nonce( VISA_ACCEPTANCE_WC_CAPTURE_ACTION ),
				'capture_error'                   => __( 'Something went wrong, and the capture could no be completed. Please try again.', 'visa-acceptance-solutions' ),
				'refund_button_visibility'        => $this->validate_refund_amount( $order ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
				'total_amount'                    => (float) $order->get_total(),
				'order_status'                    => $order->get_status(),
				'total_refund_amount'             => $order->get_remaining_refund_amount(),
				'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
				'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
				'order_fully_capture'             => $this->is_order_fully_captured( $order ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
			)
		);
	}

	/**
	 * Handles admin options based on conditions
	 */
	public function admin_options() {
		?>
		<style type="text/css">.nowrap { white-space: nowrap; }</style>
		<?php
		ob_start();
		if ( count( $this->get_environments() ) > 1 ) {
			?>
			function titleHeaderLogo() {
                $( '.woocommerce h2' ).addClass('wc-title-header');
				$(".wc-title-header").each(function() {
				if ($(this).text().trim()) {
					var logo = $("<img>", {
					src: "<?php echo esc_url( plugins_url( '../public/img/visa-logo.png', __FILE__ ) ); ?>",
					css: {
						height: "25px",
						marginLeft: "8px",
						verticalAlign: "middle"
					}
					});
					$(this).append(logo);
				}
				});
            }
            titleHeaderLogo();
				$( '#woocommerce_<?php echo esc_js( $this->get_id() ); ?>_environment' ).change( function() {
					var environment = $( this ).val();
					// hide all environment-dependant fields
					$( '.environment-field' ).closest( 'tr' ).hide();
					// show the currently configured environment fields that are not also being hidden as any shared settings
					var environmentFields = $( '.' + environment + '-field' );
					environmentFields.not( '.hidden' ).closest( 'tr' ).show();
					if ($('#woocommerce_visa_acceptance_solutions_gpay_enable_decision_manager').is(':checked')) {
						environmentFields( '.hidden' ).closest( 'tr' ).show();
					}
				} ).change();
				<?php
		}
		
		if ( ! empty( $this->shared_settings ) ) {
			wc_enqueue_js( ob_get_clean() );

			// Adding Configuration.
			if ( isset( $this->gateway->form_fields['transaction_type'] ) ) {
				// add inline javascript.
				ob_start();
				?>
			$( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_transaction_type' ).change( function() {
				var transaction_type = $( this ).val();
				var virtualOrderConfiguration = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_charge_virtual_orders' ).closest( 'tr' );
				var capturePaidOrders = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_enable_paid_capture' ).closest( 'tr' );
				if(transaction_type == 'charge'){
					virtualOrderConfiguration.hide();
					capturePaidOrders.hide();
				} 
				else{
					virtualOrderConfiguration.show();
					capturePaidOrders.show();
				}
			} ).change();
				<?php
				wc_enqueue_js( ob_get_clean() );
			}

			//validate at card type default for accepted card type.
			if ( isset( $this->gateway->form_fields['card_types'] ) ) {
				// add inline javascript.
				ob_start();
				?>
			var $cardTypes = $('#woocommerce_<?php echo esc_js($this->get_id()); ?>_card_types');
				if ($cardTypes.length) {
					// Store last valid selection
					var lastValid = $cardTypes.val();
					$cardTypes.data('last-valid', lastValid);

					$cardTypes.on('change', function() {
						var selected = $(this).val();
						if (!selected || selected.length === 0) {
							// Restore last valid selection if all are deselected
							var last = $(this).data('last-valid');
							if (last && last.length) {
								$(this).val(last).trigger('change.select2');
							} else {
								// fallback: select first option
								var first = $(this).find('option:first').val();
								$(this).val([first]).trigger('change.select2');
							}
						} else {
							// Update last valid selection
							$(this).data('last-valid', selected);
						}
					});
				}
				<?php
				wc_enqueue_js( ob_get_clean() );
			}
			// ['enable_saved_sca'] will be visible only if 3Ds is enable.
			if ( isset( $this->gateway->form_fields['enable_threed_secure'] ) ) {
				// add inline javascript.
				ob_start();
				?>
			$( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_enable_threed_secure' ).change( function() {
				var enable_threed_secure = $( this ).is( ':checked' );
				var tokenization =  $('#woocommerce_visa_acceptance_solutions_unified_checkout_tokenization').is( ':checked' );
				var enableSavedSca = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_enable_saved_sca' ).closest( 'tr' );
				if(enable_threed_secure && tokenization){
					enableSavedSca.show();
				}
				else{
					enableSavedSca.hide();
				}
			} ).change();
				<?php
				wc_enqueue_js( ob_get_clean() );
			}

			// ['enable_saved_sca'] will be visible only if tokenization is enable.
			if ( isset( $this->gateway->form_fields['tokenization'] ) ) {
				// add inline javascript.
				ob_start();
				?>
			$( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_tokenization' ).change( function() {
				var tokenization = $( this ).is( ':checked' );
				var enable_threed_secure = $('#woocommerce_visa_acceptance_solutions_unified_checkout_enable_threed_secure').is( ':checked' );
				var enableSavedSca = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_enable_saved_sca' ).closest( 'tr' );
				var enableTokenCsc = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_enable_token_csc' ).closest( 'tr' );
				if( tokenization ){
					enableTokenCsc.show();
				}
				else{
					enableTokenCsc.hide();
				}
				if(enable_threed_secure && tokenization){
					enableSavedSca.show();
				}
				else{
					enableSavedSca.hide();
				}
			} ).change();
				<?php
				wc_enqueue_js( ob_get_clean() );
			}
			// if MLE will be enabled then show Certificate path, file name & key password fields.

			if ( isset( $this->gateway->form_fields['enable_mle'] ) ) {
				// add inline javascript.
				ob_start();
				?>
			$( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_enable_mle' ).change( function() {
				var enable_mle = $(this).is( ':checked' );
				var mleCertificatePath = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_mle_certificate_path' ).closest( 'tr' );
				var mleFilename = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_mle_filename').closest( 'tr' );
				var mleKeyPassword = $( '#woocommerce_visa_acceptance_solutions_unified_checkout_mle_key_password').closest( 'tr' );
				if(enable_mle){
					mleCertificatePath.show().find('input');
					mleFilename.show().find('input');
					mleKeyPassword.show().find('input');
				}
				else{
					mleCertificatePath.hide().find('input');
					mleFilename.hide().find('input');
					mleKeyPassword.hide().find('input');
				}
			} ).change();
				<?php
				wc_enqueue_js( ob_get_clean() );
			}
		}
	}

	/**
	 * Add the Admin Fields for our payment methods
	 */
	public function init_form_fields() {

		/**
		 * Followed this pattern, available on WOO
		 * 'setting_name' => array(
		 *      'title'       => 'Title for your setting shown on the settings page',
		 *      'description' => 'Description for your setting shown on the settings page',
		 *      'type'        => 'text|password|textarea|checkbox|select|multiselect',
		 *      'default'     => 'Default value for the setting',
		 *      'class'       => 'Class for the input element',
		 *      'css'         => 'CSS rules added inline on the input element',
		 *      'label'       => 'Label', // For checkbox inputs only.
		 *      'options'     => array( // Array of options for select/multiselect inputs only.
		 *      'key' => 'value'
		 *      ),
		 *  )
		 */

		$form_fields = array(
			'enabled'                 => array(
				'title'       => __( 'Enable/Disable', 'visa-acceptance-solutions' ),
				'description' => __( 'By enabling the gateway credit card will be enabled by default.', 'visa-acceptance-solutions' ),
				'desc_tip'    => true,
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Enable the gateway', 'visa-acceptance-solutions' ),
				
			),
			'title' => array(
                'title'       => __( 'Title', 'visa-acceptance-solutions' ),
                'description' => __( 'Payment method title that the customer will see in the checkout page.', 'visa-acceptance-solutions' ),
                'desc_tip'    => true,
                'type'        => 'safe_text',
                'default'     => __( 'Visa Acceptance Solutions', 'visa-acceptance-solutions' ),
            ),

			'description'             => array(
				'title'       => __( 'Description', 'visa-acceptance-solutions' ),
				'description' => __( 'Payment method description that the customer will see on your website.', 'visa-acceptance-solutions' ),
				'desc_tip'    => true,
				'type'        => 'textarea',
				'default'     => __( 'Choose from a range of secure Payment Options.', 'visa-acceptance-solutions' ),
			),
			'transaction_type'        => array(
				'title'    => __( 'Transaction Type', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'Charge will automatically capture/settle a transaction if the authorization is approved. Authorization simply authorizes the order total for capture later.', 'visa-acceptance-solutions' ),
				'type'     => 'select',
				'default'  => VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE,
				'options'  => array(
					VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE        => __( 'Charge', 'visa-acceptance-solutions' ),
					VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION => __( 'Authorization', 'visa-acceptance-solutions' ),
				),
			),
			'charge_virtual_orders'   => array(
				'description' => __( 'If the order contains exclusively virtual items, enable this to immediately charge, rather than authorize, the transaction.', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Charge Virtual-Only Orders', 'visa-acceptance-solutions' ),
			),
			'enable_paid_capture'     => array(
				'description' => __( 'Automatically capture orders when they are changed to Processing or Completed.', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Capture Paid Orders', 'visa-acceptance-solutions' ),
			),
			'environment'             => array(
				/* translators: environment as in a software environment (test/production) */
				'title'    => __( 'Environment', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'Select the gateway environment to use for transactions.', 'visa-acceptance-solutions' ),
				'type'     => 'select',
				'default'  => VISA_ACCEPTANCE_ENVIRONMENT_TEST,
				'options'  => array(
					VISA_ACCEPTANCE_ENVIRONMENT_PRODUCTION => __( 'Production', 'visa-acceptance-solutions' ),
					VISA_ACCEPTANCE_ENVIRONMENT_TEST       => __( 'Test', 'visa-acceptance-solutions' ),
				),
			),
			// production.
			'merchant_id'             => array(
				'title'    => __( 'Merchant ID', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The Merchant ID for your Visa Acceptance Solutions account.', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'class'    => 'environment-field production-field shared-settings-field',
			),
			'api_key'                 => array(
				'title'    => __( 'API Key Detail', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The API Key ID for your Visa Acceptance Solutions account.', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'class'    => 'environment-field production-field shared-settings-field',
			),
			'api_shared_secret'       => array(
				'title'    => __( 'API Shared Secret Key', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The API Shared Secret Key for your Visa Acceptance Solutions account.', 'visa-acceptance-solutions' ),
				'type'     => 'password',
				'class'    => 'environment-field production-field shared-settings-field',
			),
			// test.
			'test_merchant_id'        => array(
				'title'    => __( 'Test Merchant ID', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The Merchant ID for your Visa Acceptance Solutions sandbox account.', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'class'    => 'environment-field test-field shared-settings-field',
			),

			'test_api_key'            => array(
				'title'    => __( 'Test API Key Detail', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The API Key ID for your Visa Acceptance Solutions sandbox account.', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'class'    => 'environment-field test-field shared-settings-field',
			),

			'test_api_shared_secret'  => array(
				'title'    => __( 'Test API Shared Secret Key', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The API Shared Secret Key for your Visa Acceptance Solutions sandbox account.', 'visa-acceptance-solutions' ),
				'type'     => 'password',
				'class'    => 'environment-field test-field shared-settings-field',
			),
			'enable_mle'              => array(
				'title'   => __( 'Message Level Encryption', 'visa-acceptance-solutions' ),
				'type'    => 'checkbox',
				'default' => VISA_ACCEPTANCE_NO,
				'label'   => __( 'Enable MLE Authentication', 'visa-acceptance-solutions' ),
			),
			'mle_certificate_path'    => array(
				'title'    => __( 'Key Directory Path', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'desc_tip' => __( 'Your Visa Acceptance Solutions Merchant Key File Directory.', 'visa-acceptance-solutions' ),
				'placeholder' => __( 'EX. C:/users/folderName/ Or /var/folderName/', 'visa-acceptance-solutions'),
			),
			'mle_filename'            => array(
				'title'    => __( 'Key File Name', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'Your Visa Acceptance Solutions Merchant Key File Name.', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'placeholder' => __( 'EX. MessageLevelEncryptionCertificate', 'visa-acceptance-solutions' ),
			),
			'mle_key_password'        => array(
				'title'    => __( 'Key Password', 'visa-acceptance-solutions' ),
				'type'     => 'password',
				'desc_tip' => __( 'Your Visa Acceptance Solutions Merchant Key File Password.', 'visa-acceptance-solutions' ),
			),
			// debug mode.
			'enable_logs'             => array(
				'title'    => __( 'Debug Mode', 'visa-acceptance-solutions' ),
				'desc_tip' => sprintf( __( 'Select to enable request/response logs.', 'visa-acceptance-solutions' ), '<a href="' . $this->get_wc_log_file_url( $this->get_id() ) . '">', '</a>' ),
				'type'     => 'select',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'default'  => VISA_ACCEPTANCE_NO,
				'options'  => array(
					VISA_ACCEPTANCE_NO  => __( 'Off', 'visa-acceptance-solutions' ),
					VISA_ACCEPTANCE_YES => __( 'On', 'visa-acceptance-solutions' ),
				),
			),
			
			'card_types'              => array(
				'title'   => __( 'Accepted Card Types', 'visa-acceptance-solutions' ),
				'type'    => 'multiselect',
				'default' => array_keys( $this->get_supported_card_types( 'default' ) ),
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 350px;',
				'options' => $this->get_supported_card_types(),
			),
			'enabled_payment_methods' => array(
                'title'       => __( 'Digital Payment Methods', 'visa-acceptance-solutions' ),
                'class'   => 'wc-enhanced-select',
                'css'     => 'width: 350px;',
                'type'        => 'multiselect',
                'default'     => array(),
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select one or more Digital Payment Methods', 'visa-acceptance-solutions' ),
                ),
                'options'     => array(
                    'enable_apay' => __( 'Apple Pay', 'visa-acceptance-solutions' ),
                    'enable_gpay' => __( 'Google Pay', 'visa-acceptance-solutions' ),
                    'enable_vco'  => __( 'Click to Pay', 'visa-acceptance-solutions' ),
                ),
            ),
 
			'enable_threed_secure'    => array(
				'title'       => __( 'Payer Authentication / 3D Secure', 'visa-acceptance-solutions' ),
				'description' => __( 'Your merchant account must have this optional service enabled.', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Enable 3D Secure', 'visa-acceptance-solutions' ),
			),
			'tokenization'            => array(
				'title'   => __( 'Tokenization', 'visa-acceptance-solutions' ),
				'type'    => 'checkbox',
				'default' => VISA_ACCEPTANCE_NO,
				'label'   => __( 'Allow customers to securely save their payment details for future checkout.', 'visa-acceptance-solutions' ),
			),
			'enable_saved_sca'        => array(
				'title'   => __( 'Strong Customer Authentication', 'visa-acceptance-solutions' ),
				'description' => __( 'If enabled, card holder will be 3DS challenged when saving a card.', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Enable Strong Customer Authentication while saving card', 'visa-acceptance-solutions' ),
			),
			'enable_token_csc'        => array(
				'title'   => __( 'Saved Card Verification', 'visa-acceptance-solutions' ),
				'type'    => 'checkbox',
				'default' => VISA_ACCEPTANCE_YES,
				'label'   => __( 'Display the Card Security Code field when paying with a saved card', 'visa-acceptance-solutions' ),
			),
			'enable_decision_manager' => array(
				'title'       => __( 'Fraud Screening', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'class'       => 'shared-settings-field',
				'label'       => __( 'Enable Cybersource Fraud Screening.', 'visa-acceptance-solutions' ),
			),
		);

			return $form_fields;
	}

	/**
	 * Gets default supported card types as an array
	 *
	 * @param string $supports default|null.
	 */
	public static function get_supported_card_types( $supports = VISA_ACCEPTANCE_STRING_EMPTY ) {
		$default_supported_cards = array(
			'VISA'       => __( 'Visa', 'visa-acceptance-solutions' ),
			'MASTERCARD' => __( 'MasterCard', 'visa-acceptance-solutions' ),
			'AMEX'       => __( 'American Express', 'visa-acceptance-solutions' ),
			'DISCOVER'   => __( 'Discover', 'visa-acceptance-solutions' ),
		);

		return 'default' === $supports
		?
		$default_supported_cards
		:
		array_merge(
			$default_supported_cards,
			array(
				'DINERSCLUB' => __( 'Diners', 'visa-acceptance-solutions' ),
				'JCB'        => __( 'JCB', 'visa-acceptance-solutions' ),
			)
		);
	}

	/**
	 * Handles Refund from Admin Page
	 *
	 * @param int    $order_id order id.
	 * @param string $amount Amount of Refund.
	 * @param string $reason Reason for Refund.
	 */
	public function process_refund( $order_id, $amount = null, $reason = VISA_ACCEPTANCE_STRING_EMPTY ) {
		$order         = wc_get_order( $order_id );
		$auth_reversal = new Visa_Acceptance_Auth_Reversal( $this->gateway );
		$refund   	   = new Visa_Acceptance_Refund( $this->gateway );
		$response      = $order;

		if ( ! is_wp_error( $order ) ) {
			// Trigger auth reversal if amount is not captured.
			if ( ! $this->is_order_captured( $order ) ) {
				$response      = $auth_reversal->process_void( $order, $amount, $reason );
			} else {
				$response = $refund->do_refund( $order, $amount, $reason );
			}
		}
		return $response;
	}
}
