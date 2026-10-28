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
require_once plugin_dir_path( __DIR__ ) . 'includes/api/request/payments/class-visa-acceptance-payment-adapter.php';
 

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
		$this->shared_settings = array( 'merchant_id', 'api_key', 'api_shared_secret', 'test_merchant_id', 'test_api_key', 'test_api_shared_secret', 'tokenization_profile_id', 'enable_fraud_webhook', 'organization_id' );
		// AJAX handlers for webhook management.
		add_action( 'wp_ajax_vas_delete_webhook', array( $this, 'ajax_delete_webhook' ) );
		// AJAX handler for payment status refresh.
        add_action( 'wp_ajax_vas_refresh_payment_status', array( $this, 'ajax_refresh_payment_status' ) );
		add_action( 'send_headers', array( $this, 'add_clickjacking_headers' ) );
        add_action( 'wp_head', array( $this, 'add_clickjacking_script' ), 1 );
        add_action( 'current_screen', array( $this, 'admin_clickjacking_script' ) );
	}

	/**
	 * Only hook the anti-clickjack output into `admin_head` on this plugin's own screens,
	 * so it never hides the dashboard for unrelated admin pages.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public function admin_clickjacking_script( $screen ) {
		$plugin_screens = array( 'woocommerce_page_wc-settings', 'shop_order', 'woocommerce_page_wc-orders' );
		if ( $screen && in_array( $screen->id, $plugin_screens, true ) ) {
			add_action( 'admin_head', array( $this, 'add_clickjacking_script' ), 1 );
		}
	}

	/**
     * Add clickjacking protection headers.
     *
     * @return void
     */
    public function add_clickjacking_headers() {
        if ( defined( 'VISA_ACCEPTANCE_CLICKJACK_HEADERS_SENT' ) ) {
            return;
        }
        define( 'VISA_ACCEPTANCE_CLICKJACK_HEADERS_SENT', true );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( "Content-Security-Policy: frame-ancestors 'self'" );
    }
 
    /**
     * Add JavaScript-based clickjacking protection.
     *
     * @return void
     */
    public function add_clickjacking_script() {
        if ( defined( 'VISA_ACCEPTANCE_CLICKJACK_SCRIPT_OUTPUT' ) ) {
            return;
        }
        define( 'VISA_ACCEPTANCE_CLICKJACK_SCRIPT_OUTPUT', true );

        // Compute the destination server-side (never from window.location) so the redirect.
        // target is not attacker-influenced client-side data.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $trusted_url = esc_url_raw( home_url( $request_uri ) );
 
        wp_register_style( 'vas-anti-clickjack', false, array(), $this->version );
        wp_enqueue_style( 'vas-anti-clickjack' );
        wp_add_inline_style( 'vas-anti-clickjack', '#antiClickjack, html.vas-anti-clickjack { display: none !important; }' );
 
        wp_register_script( 'vas-anti-clickjack', false, array(), $this->version, false );
        wp_enqueue_script( 'vas-anti-clickjack' );
        wp_add_inline_script(
            'vas-anti-clickjack',
            '(function() {
            \'use strict\';
 
            // Server-rendered, sanitized destination URL - not derived from window.location.
            var trustedUrl = ' . wp_json_encode( $trusted_url ) . ';
 
            function showPage() {
                document.documentElement.style.display = "block";
            }
 
            function breakOut() {
                try {
                    var target;
                    try {
                        target = new URL(trustedUrl);
                    } catch (e) {
                        document.body.innerHTML = \'\';
                        return;
                    }
                    if (target.protocol !== \'http:\' && target.protocol !== \'https:\') {
                        document.body.innerHTML = \'\';
                        return;
                    }
                    if (top.location.origin !== target.origin) {
                        // Cross-origin framing detected - clear page content.
                        document.body.innerHTML = \'\';
                        return;
                    }
                    if (top.location.href !== target.href) {
                        top.location.replace(target.href);
                    }
                } catch (e) {
                    // If we can\'t access top.location due to cross-origin,
                    // the page is being framed by an untrusted domain.
                    document.body.innerHTML = \'\';
                }
            }
 
            // Check if page is being framed.
            if (self === top) {
                // Not in a frame, safe to display.
                showPage();
            } else {
                // Page is in a frame, attempt to break out.
                breakOut();
            }
            })();'
        );
    }

	/**
	 * Displays an admin notice when the gateway is running in test/sandbox mode.
	 *
	 * @return void
	 */
	public function sandbox_admin_notice() {
		// Only show on the payment gateway configuration page.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for conditional notice display only.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'wc-settings' !== $page || 'checkout' !== $tab || $this->id !== $section ) {
			return;
		}

		$settings    = $this->gateway->get_gateway_settings();
		$enabled     = isset( $settings['enabled'] ) ? $settings['enabled'] : VISA_ACCEPTANCE_NO;
		$environment = isset( $settings[ VISA_ACCEPTANCE_ENVIRONMENT ] ) ? $settings[ VISA_ACCEPTANCE_ENVIRONMENT ] : VISA_ACCEPTANCE_STRING_EMPTY;

		if ( VISA_ACCEPTANCE_YES === $enabled && VISA_ACCEPTANCE_ENVIRONMENT_TEST === $environment ) {
			$message = sprintf(
				/* translators: %s: plugin name */
				__( '<strong>%s</strong> is currently in Test environment. No real transactions will be processed.', 'visa-acceptance-solutions' ),
				__( 'Visa Acceptance Solutions', 'visa-acceptance-solutions' )
			);
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( $message ) . '</p></div>';
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->id, plugin_dir_url( __FILE__ ) . 'css/visa-acceptance-payment-gateway-admin.css', array(), $this->version, VISA_ACCEPTANCE_STRING_ALL );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix Hook URL.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'post.php' === $hook_suffix || 'woocommerce_page_wc-orders' === $hook_suffix ) {
		// Only users who are allowed to manage WooCommerce orders may trigger asset enqueueing here.
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Sanitize and validate request data using proper WordPress functions.
		$post_id = null;
    
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for conditional notice display only.
		if ( isset( $_GET['post'] ) && ! empty( $_GET['post'] ) ) {
			$post_id = absint( sanitize_text_field( wp_unslash( $_GET['post'] ) ) );
		} elseif ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) { 
			$post_id = absint( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ); 
		}
		if ( ! $post_id || $post_id <= VISA_ACCEPTANCE_VAL_ZERO ) {
			return;
		}
        // phpcs:enable WordPress.Security.NonceVerification.Recommended -- Finished reading URL parameters for conditional notice display.
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
		$settings         = $this->gateway->get_gateway_settings();
        $transaction_type = isset( $settings['transaction_type'] ) ? $settings['transaction_type'] : VISA_ACCEPTANCE_STRING_EMPTY;
        $is_charge_settled = VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $transaction_type
            && in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED ), true );
 
        // Check if virtual order was auto-charged (same logic as refund method).
        $payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
        $is_virtual_order_auto_charged = $payment_adapter->check_virtual_order_enabled( $settings, $order );
        $order_fully_captured = $this->is_order_captured( $order )
            || $is_charge_settled
            || $is_virtual_order_auto_charged;

        $capture_context = $this->get_partial_capture_refund_context( $order );

        $captured_item_quantities = array();
        $capture_history_raw      = $order->get_meta( '_vas_partial_captures', true );
        $capture_history_raw      = is_array( $capture_history_raw ) ? $capture_history_raw : array();
        foreach ( $capture_history_raw as $capture ) {
            if ( empty( $capture['items'] ) || ! is_array( $capture['items'] ) ) {
                continue;
            }
            foreach ( $capture['items'] as $item_id => $qty ) {
                $captured_item_quantities[ $item_id ] = ( $captured_item_quantities[ $item_id ] ?? VISA_ACCEPTANCE_VAL_ZERO ) + $qty;
            }
        }

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
				'partial_capture_nonce'           => wp_create_nonce( 'visa_acceptance_partial_capture' ),
				'refresh_payment_status_nonce'    => wp_create_nonce( 'vas-refresh-payment-status-nonce' ),
				'capture_error'                   => __( 'Something went wrong, and the capture could not be completed. Please try again.', 'visa-acceptance-solutions' ),
				'refund_button_visibility'        => $this->validate_refund_amount( $order ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
				'total_amount'                    => (float) $order->get_total(),
				'order_status'                    => $order->get_status(),
				'total_refund_amount'             => $order->get_remaining_refund_amount(),
				'visa_acceptance_solutions_uc_id' => VISA_ACCEPTANCE_UC_ID,
				'error_failure'                   => __( 'Unable to process your request. Please try again later.', 'visa-acceptance-solutions' ),
				'order_fully_capture'             => $order_fully_captured ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
				'currency_symbol'                 => get_woocommerce_currency_symbol( $order->get_currency() ),
				'payment_type'                    => $order->get_meta( '_vas_payment_type', true ),
				'has_partial_capture_history'      => $capture_context['has_partial_history'] ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
				'remaining_captured_amount'        => $capture_context['remaining_captured'],
				'remaining_authorized_amount'      => $capture_context['remaining_authorized'],
				'captured_item_quantities'         => $captured_item_quantities,
                'has_untracked_partial_capture'    => $this->has_untracked_partial_capture( $order ) ? VISA_ACCEPTANCE_YES : VISA_ACCEPTANCE_NO,
			)
		);
	}

	/**
	 * Handles admin options based on conditions
	 */
	public function admin_options() {
		wp_register_style(
        'vas-admin-settings',
        false,
        array(),
        $this->version
        );
        wp_enqueue_style( 'vas-admin-settings' );
        wp_add_inline_style(
        'vas-admin-settings',
        '.nowrap { white-space: nowrap; }'
        );
		if ( ! empty( $this->shared_settings ) ) {
			// Enqueue admin settings JavaScript file.
            wp_enqueue_script(
                'vas-admin-settings',
                plugin_dir_url( __FILE__ ) . 'js/visa-acceptance-admin-settings.js',
                array( 'jquery' ),
                $this->version,
                true
            );
 
            // Localize script with settings data.
            wp_localize_script(
                'vas-admin-settings',
                'vasAdminSettings',
                array(
                    'logoUrl'             => plugins_url( '../public/img/visa-logo.png', __FILE__ ),
                    'gatewayId'           => $this->get_id(),
                    'gatewayIdFull'       => $this->gateway->get_id(),
                    'hasMultipleEnvs'     => count( $this->get_environments() ) > VISA_ACCEPTANCE_VAL_ONE,
					'webhookNonce'        => wp_create_nonce( 'vas-webhook-nonce' ),
                )
            );
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
				$js = ob_get_clean();
				wp_add_inline_script( 'vas-admin-settings', 'jQuery(function($){' . $js . '});' );
			}
			// MLE Request is automatically active for all transactions.
            if ( isset( $this->gateway->form_fields['enable_mle'] ) ) {
                // add inline javascript.
                ob_start();
                ?>
            	$( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_enable_mle' ).prop( 'checked', true ).closest( 'tr' ).hide();
                <?php
				$js = ob_get_clean();
				wp_add_inline_script( 'vas-admin-settings', 'jQuery(function($){' . $js . '});' );
            }

			// Show/hide Network Token Webhook field based on Tokenization setting.
            if ( isset( $this->gateway->form_fields['enable_network_token_webhook'] ) ) {
                ob_start();
                ?>
                $( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_tokenization' ).change( function() {
                    var tokenization_enabled = $(this).is( ':checked' );
                    var networkTokenRow = $( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_enable_network_token_webhook' ).closest( 'tr' );
                    if ( tokenization_enabled ) {
                        networkTokenRow.show();
                    } else {
                        networkTokenRow.hide();
                        $( '#woocommerce_<?php echo esc_js( $this->gateway->get_id() ); ?>_enable_network_token_webhook' ).prop( 'checked', false );
                    }
                } ).change();
                <?php
					$js = ob_get_clean();
				    wp_add_inline_script( 'vas-admin-settings', 'jQuery(function($){' . $js . '});' );
            }
		}
	}

	/**
	 * Add the Admin Fields for our payment methods
	 */
	public function init_form_fields() {
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
				'default'     => __( 'Choose from a range of secure payment options.', 'visa-acceptance-solutions' ),
			),
			'charge_virtual_orders'   => array(
				'title'    => __( 'Charge Virtual Orders', 'visa-acceptance-solutions' ),
				'description' => __( 'If the order contains exclusively virtual items, enable this to immediately charge, rather than authorize, the transaction.', 'visa-acceptance-solutions' ),
				'type'        => 'checkbox',
				'default'     => VISA_ACCEPTANCE_NO,
				'label'       => __( 'Charge Virtual-Only Orders', 'visa-acceptance-solutions' ),
			),
			'enable_paid_capture'     => array(
				'title'    => __( 'Enable Paid Capture', 'visa-acceptance-solutions' ),
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
				'title'    => __( 'REST Shared Secret', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'The REST API Shared Secret Key for your Visa Acceptance Solutions account.', 'visa-acceptance-solutions' ),
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
				'default' => VISA_ACCEPTANCE_YES,
				'label'   => __( 'Enable MLE Authentication', 'visa-acceptance-solutions' ),
			),
            'mle_certificate_path'    => array(
				'title'    => __( 'Key File Path', 'visa-acceptance-solutions' ),
				'type'     => 'text',
				'desc_tip' => __( 'Your Visa Acceptance Solutions Merchant Key File Directory.', 'visa-acceptance-solutions' ),
				'placeholder' => __( 'EX. C:/users/folderName/fileName.p12', 'visa-acceptance-solutions'),
			),

			'mle_key_password'        => array(
				'title'    => __( 'Key Password', 'visa-acceptance-solutions' ),
				'type'     => 'password',
				'desc_tip' => __( 'Your Visa Acceptance Solutions Merchant Key File Password.', 'visa-acceptance-solutions' ),
			),
            'enable_response_mle'     => array(
                'title'   => __( 'Response Message Level Encryption', 'visa-acceptance-solutions' ),
                'type'    => 'checkbox',
                'default' => VISA_ACCEPTANCE_NO,
                'label'   => __( 'Enable Response MLE', 'visa-acceptance-solutions' ),
            ),
			'enable_logs'             => array(
				'title'    => __( 'Debug Mode', 'visa-acceptance-solutions' ),
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'desc_tip' => sprintf( __( 'Select to enable request/response logs.', 'visa-acceptance-solutions' ), '<a href="' . $this->get_wc_log_file_url( $this->get_id() ) . '">', '</a>' ),
				'type'     => 'select',
				'default'  => VISA_ACCEPTANCE_NO,
				'options'  => array(
					VISA_ACCEPTANCE_NO  => __( 'Off', 'visa-acceptance-solutions' ),
					VISA_ACCEPTANCE_YES => __( 'On', 'visa-acceptance-solutions' ),
				),
			),
			'enable_express_pay'      => array(
                'title'       => __( 'Express Pay', 'visa-acceptance-solutions' ),
                'description' => __( 'If not enabled, Digital Payment Methods load in the main checkout widget.', 'visa-acceptance-solutions' ),
                'type'        => 'checkbox',
                'default'     => VISA_ACCEPTANCE_NO,
                'label'       => __( 'Enable Express Pay', 'visa-acceptance-solutions' ),
            ),
			'checkout_display_mode'   => array(
				'title'    => __( 'Checkout Display Mode', 'visa-acceptance-solutions' ),
				'desc_tip' => __( 'Choose how the Unified Checkout payment form is displayed. Embedded: the payment details form appears inline on the checkout page. Sidebar: the payment details form opens as a side panel.', 'visa-acceptance-solutions' ),
				'type'     => 'select',
				'default'  => 'sidebar',
				'options'  => array(
					'embedded' => __( 'Embedded', 'visa-acceptance-solutions' ),
					'sidebar'  => __( 'Sidebar', 'visa-acceptance-solutions' ),
                ),
            ),
			'tokenization'            => array(
				'title'   => __( 'Tokenization', 'visa-acceptance-solutions' ),
				'type'    => 'checkbox',
				'default' => VISA_ACCEPTANCE_NO,
				'label'   => __( 'Allow customers to securely save their payment details for future checkout.', 'visa-acceptance-solutions' ),
			),
			'enable_network_token_webhook' => array(
                'title'       => __( 'Network Tokens', 'visa-acceptance-solutions' ),
                'type'        => 'checkbox',
                'default'     => VISA_ACCEPTANCE_NO,
                'label'       => __( 'Enable Network Token', 'visa-acceptance-solutions' ),
            ),
			'enable_fraud_webhook' => array(
                'title'       => __( 'Fraud Management', 'visa-acceptance-solutions' ),
                'type'        => 'checkbox',
                'desc_tip' => __( 'Enable this option when using Visa Acceptance Solutions fraud management services. The plugin will automatically register and maintain the required webhook subscriptions.', 'visa-acceptance-solutions' ),
                'default'     => VISA_ACCEPTANCE_NO,
                'class'       => 'shared-settings-field',
                'label'       => __( 'Enable Fraud Webhook Subscription', 'visa-acceptance-solutions' ),
            ),
			'uc_client_version' => array(
            'title'       => __( 'Checkout Version (optional)', 'visa-acceptance-solutions' ),
            'description' => __( 'Specify the unified checkout version (e.g. 1.0). If provided, it must start with 1.x.', 'visa-acceptance-solutions' ),
            'desc_tip'    => true,
            'type'        => 'text',
            'default'     => VISA_ACCEPTANCE_STRING_EMPTY,
            'placeholder' => __( 'e.g 1.x', 'visa-acceptance-solutions' ),
            ),
            'registered_webhooks' => array(
                'title'       => __( 'Registered Webhooks', 'visa-acceptance-solutions' ),
                'type'        => 'registered_webhooks',
                'description' => __( 'View and manage your webhook subscriptions. Webhooks are automatically created when you enable the features above and save settings.', 'visa-acceptance-solutions' ),
            )
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

		return 'default' === $supports ? $default_supported_cards :
		array_merge(
			$default_supported_cards,
			array(
				'DINERSCLUB' 	=> __( 'Diners', 'visa-acceptance-solutions' ),
				'JCB'        	=> __( 'JCB', 'visa-acceptance-solutions' ),
				'CUP'        	=>__( 'China UnionPay', 'visa-acceptance-solutions' ),
                'MAESTRO'    	=>__( 'Maestro', 'visa-acceptance-solutions'),
				'JAYWAN'        =>__( 'Jaywan', 'visa-acceptance-solutions'),
			)
		);
	}

	/**
     * Check if transaction is settled by querying Visa API
     *
     * @param object $order WooCommerce order object.
     * @return bool True if transaction is settled, false otherwise.
     */
    private function is_transaction_settled_via_api( $order ) {
        try {
            require_once plugin_dir_path( __DIR__ ) . 'includes/api/request/payments/class-visa-acceptance-payment-adapter.php';
           
            $transaction_id = $this->get_order_meta( $order, VISA_ACCEPTANCE_TRANSACTION_ID );
            if ( empty( $transaction_id ) ) {
                return false;
            }
           
            $payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $transaction_details = $payment_adapter->get_transaction_details_sdk( $transaction_id );
           
            if ( empty( $transaction_details['http_code'] ) || VISA_ACCEPTANCE_TWO_ZERO_ZERO !== (int) $transaction_details['http_code'] ) {
                // API call failed, return false to use fallback logic.
                return false;
            }
           
            $transaction_data = json_decode( $transaction_details['body'], true );
           
            // Check if transaction has settlement information in applicationInformation.
            if ( ! empty( $transaction_data['applicationInformation']['applications'] ) ) {
                foreach ( $transaction_data['applicationInformation']['applications'] as $application ) {
                    $app_name = isset( $application['name'] ) ? strtolower( $application['name'] ) : VISA_ACCEPTANCE_STRING_EMPTY;
                    $app_reason = isset( $application['reasonCode'] ) ? $application['reasonCode'] : VISA_ACCEPTANCE_STRING_EMPTY;
                   
                    // Check for settlement or capture application.
                    if ( strpos( $app_name, 'settlement' ) !== false || strpos( $app_name, 'capture' ) !== false ) {
                        // If reason code is 100 (success), transaction is settled.
                        if ( '100' === $app_reason ) {
                            return true;
                        }
                    }
                }
            }
           
            return false;
           
        } catch ( \Exception $e ) {
            $this->gateway->add_logs_data( array( $e->getMessage() ), false, 'Unable to check transaction settlement status via API', true );
            return false;
        }
    }
 

	/**
     * Handles Refund from Admin Page
     *
     * @param int    $order_id order id.
     * @param string $amount Amount of Refund.
     * @param string $reason Reason for Refund.
     */
    public function process_refund( $order_id, $amount = null, $reason = VISA_ACCEPTANCE_STRING_EMPTY ) {
        $order = wc_get_order( $order_id );
       
        if ( is_wp_error( $order ) ) {
            $response = new \WP_Error( 'vas_refund_failed', __( 'Refund failed.', 'visa-acceptance-solutions' ) );
        } else {
            $auth_reversal = new Visa_Acceptance_Auth_Reversal( $this->gateway );
            $refund        = new Visa_Acceptance_Refund( $this->gateway );
            $payment_type = $order->get_meta('_vas_payment_type', true);
            $is_echeck    = ( strtoupper( $payment_type ) === VISA_ACCEPTANCE_CHECK );
			$is_apm       = ( VISA_ACCEPTANCE_APM_STRING === strtolower( $payment_type ) );
            $settings         = $this->gateway->get_gateway_settings();
            $transaction_type = isset( $settings['transaction_type'] ) ? $settings['transaction_type'] : VISA_ACCEPTANCE_STRING_EMPTY;
            $payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $is_virtual_order_auto_charged = $payment_adapter->check_virtual_order_enabled( $settings, $order );
            $is_transaction_charged_and_settled = ( VISA_ACCEPTANCE_TRANSACTION_TYPE_CHARGE === $transaction_type || $is_virtual_order_auto_charged )
                && in_array( $order->get_status(), array( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_COMPLETED ), true );
            $is_settled_externally = false;
            if ( VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION === $transaction_type
                && ! $this->is_order_captured( $order )
                && ! $is_transaction_charged_and_settled
                && ! $is_echeck ) {
                // Query Visa API to check if authorization was settled outside of WooCommerce.
                $is_settled_externally = $this->is_transaction_settled_via_api( $order );
            }

            $order_payment_action = $this->get_order_meta( $order, VISA_ACCEPTANCE_PAYMENT_ACCEPTANCE_SERVICE );
            $apm_payment_action   = $is_apm ? $order_payment_action : null;
            // Partial capture history takes precedence over order status (an order stays
            // On Hold whether it is uncaptured or partially captured), so it must be
            // checked before falling back to the plain "not captured" auth-reversal path.
            $capture_context = $this->get_partial_capture_refund_context( $order, $amount );
            $requested_amount = round( (float) $amount, VISA_ACCEPTANCE_VAL_TWO );
            $amount_epsilon = 0.01;

            $is_apm_auth_only     = $is_apm
                && ! $this->is_order_captured( $order )
                && abs( $capture_context['total_captured'] - VISA_ACCEPTANCE_VAL_ZERO ) < $amount_epsilon
                && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION === $apm_payment_action || empty( $apm_payment_action ));

            if ( $is_echeck ) {
                $response = $refund->do_refund( $order, $amount, $reason );
            } elseif ( $is_settled_externally ) {
                //Transaction settled externally (detected via API check).
                $response = $refund->do_refund( $order, $amount, $reason );
            } elseif ( $is_apm_auth_only ) {
                //APM auth-only — must use auth reversal, not refund (refund endpoint returns HTTP 307).
                $response = $auth_reversal->process_void( $order, $amount, $reason );
            } elseif ( $capture_context['has_partial_history'] ) {
                if ( $requested_amount > VISA_ACCEPTANCE_VAL_ZERO && $requested_amount <= $capture_context['remaining_captured'] + $amount_epsilon ) {
                    // Refund only the amount that was actually captured.
                    $response = $refund->do_refund( $order, $amount, $reason );
                } else {
                    $message = sprintf(
                        /* translators: 1: amount available for refund, 2: amount that remains authorized but uncaptured */
                        esc_html__( 'Refund amount exceeds captured amount. Only %1$s is available to refund. %2$s remains authorized (uncaptured) and cannot be reversed separately once a capture has occurred; it will be released automatically if "release remaining balance" was selected during capture, or will expire per the issuing bank\'s authorization hold period.', 'visa-acceptance-solutions' ),
                        wc_price( $capture_context['remaining_captured'], array( 'currency' => $order->get_currency() ) ),
                        wc_price( $capture_context['remaining_authorized'], array( 'currency' => $order->get_currency() ) )
                    );
                    $response = new \WP_Error( 'vas_refund_failed', $message );
                }
            } elseif ( ! $this->is_order_captured( $order )
                    && ! $is_transaction_charged_and_settled
                    && ! $is_virtual_order_auto_charged
                    && ( VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION === $transaction_type
                         || VISA_ACCEPTANCE_TRANSACTION_TYPE_AUTHORIZATION === $order_payment_action ) ) {
                $response = $auth_reversal->process_void( $order, $amount, $reason );
            }
            else {
                $response = $refund->do_refund( $order, $amount, $reason );
            }
        }  
        return $response;
    }

	/**
     * AJAX handler for deleting a webhook subscription.
     */
     public function ajax_delete_webhook() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'vas-webhook-nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'visa-acceptance-solutions' ) ) );
        }
 
        // Check user capabilities.
        // phpcs:ignore WordPress.WP.Capabilities.Unknown
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'visa-acceptance-solutions' ) ) );
        }
 
        // Validate and sanitize input.
        $webhook_id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : VISA_ACCEPTANCE_STRING_EMPTY;
 
        if ( empty( $webhook_id ) || ! preg_match( '/^[a-zA-Z0-9\-]+$/', $webhook_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid webhook ID.', 'visa-acceptance-solutions' ) ) );
        }
 
        try {
            $result = $this->delete_webhook_from_api( $webhook_id );
 
            if ( $result['success'] ) {
                $this->clear_webhook_options( $webhook_id );
                delete_transient( 'vas_webhooks_list_' . $this->gateway->get_merchant_id() );
 
                $this->gateway->add_logs_data( array( 'Webhook deleted successfully: ' . $webhook_id ), true, 'Delete Webhook' );
                wp_send_json_success( array( 'message' => __( 'Webhook deleted successfully.', 'visa-acceptance-solutions' ) ) );
            } else {
                wp_send_json_error( array( 'message' => $result['message'] ) );
            }
        } catch ( Exception $e ) {
            $this->gateway->add_logs_data( array( 'Webhook delete exception: ' . $e->getMessage() ), false, 'Delete Webhook', true );
            /* translators: %s: error message */
            wp_send_json_error( array( 'message' => sprintf( __( 'Error: %s', 'visa-acceptance-solutions' ), $e->getMessage() ) ) );
        }
     }

    /**
     * Delete webhook via API.
     *
     * @param string $webhook_id Webhook ID to delete.
     * @return array Array with 'success' and 'message' keys.
     */
    private function delete_webhook_from_api( $webhook_id ) {
        $settings    = $this->gateway->get_gateway_settings();
        $environment = isset( $settings['environment'] ) ? $settings['environment'] : VISA_ACCEPTANCE_ENVIRONMENT_TEST;
        $is_test     = VISA_ACCEPTANCE_ENVIRONMENT_TEST === $environment;
 
        $merchant_id = $this->gateway->get_merchant_id();
        $api_key     = $is_test ? ( $settings['test_api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_key'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
        $secret_key  = $is_test ? ( $settings['test_api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY ) : ( $settings['api_shared_secret'] ?? VISA_ACCEPTANCE_STRING_EMPTY );
 
        if ( empty( $merchant_id ) || empty( $api_key ) || empty( $secret_key ) ) {
            return array(
                'success' => false,
                'message' => __( 'API credentials not configured.', 'visa-acceptance-solutions' ),
            );
        }
 
        $host     = $is_test ? VISA_ACCEPTANCE_REQUEST_HOST_APITEST : VISA_ACCEPTANCE_REQUEST_HOST_APIPRODUCTION;
        $resource = VISA_ACCEPTANCE_NOTIFICATION_SUBSCRIPTIONS_PATH . VISA_ACCEPTANCE_SLASH . rawurlencode( $webhook_id );
        $url      = 'https://' . $host . $resource;
        $headers  = $this->gateway->build_webhook_http_signature_headers( $host, $resource, 'DELETE', VISA_ACCEPTANCE_STRING_EMPTY, $merchant_id, $api_key, $secret_key );
 
        $this->gateway->add_logs_data( array( 'Attempting to delete webhook', 'URL: ' . $url, 'Webhook ID: ' . $webhook_id ), true, 'Delete Webhook' );
 
        $response = wp_remote_request(
            $url,
            array(
                'method'    => 'DELETE',
                'headers'   => $headers,
                'timeout'   => 20,
                'sslverify' => ! $is_test,
            )
        );
 
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $this->gateway->add_logs_data( array( 'Webhook delete WP_Error: ' . $error_msg ), false, 'Delete Webhook', true );
            return array(
                'success' => false,
                /* translators: %s: error message */
                'message' => sprintf( __( 'Error: %s', 'visa-acceptance-solutions' ), $error_msg ),
            );
        }
 
        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
 
        $this->gateway->add_logs_data( array( 'Webhook delete response', 'HTTP: ' . $http_code, 'Body: ' . $body ), true, 'Delete Webhook' );
 
        // Accept 2xx success codes and 404 (already deleted).
        if ( ( $http_code >= VISA_ACCEPTANCE_TWO_ZERO_ZERO && $http_code < VISA_ACCEPTANCE_THREE_ZERO_ZERO ) || VISA_ACCEPTANCE_FOUR_ZERO_FOUR === (int) $http_code ) {
            return array( 'success' => true );
        }
 
        $this->gateway->add_logs_data( array( 'Failed to delete webhook ID: ' . $webhook_id . ' HTTP ' . $http_code, 'Response: ' . $body ), false, 'Delete Webhook', true );
        return array(
            'success' => false,
            /* translators: %d: HTTP response code */
            'message' => sprintf( __( 'Failed to delete webhook. HTTP code: %d', 'visa-acceptance-solutions' ), $http_code ),
        );
    }

    /**
     * Clear webhook options from WordPress database.
     *
     * @param string $webhook_id Webhook ID to clear.
     */
    private function clear_webhook_options( $webhook_id ) {
        $webhook_options = array(
            VISA_ACCEPTANCE_FRAUD_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_FRAUD_WEBHOOK_FME_ID_OPTION,
            VISA_ACCEPTANCE_UC_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_APM_WEBHOOK_ID_OPTION,
            VISA_ACCEPTANCE_NETWORK_TOKENS_WEBHOOK_ID_OPTION,
        );
 
        foreach ( $webhook_options as $option ) {
            if ( get_option( $option, VISA_ACCEPTANCE_STRING_EMPTY ) === $webhook_id ) {
                delete_option( $option );
            }
        }
    }
 
    /**
     * AJAX handler to refresh payment status.
     *
     * @return void
     */
     public function ajax_refresh_payment_status() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'vas-refresh-payment-status-nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'visa-acceptance-solutions' ) ) );
        }
 
        // Check user capabilities.
        // phpcs:ignore WordPress.WP.Capabilities.Unknown
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'visa-acceptance-solutions' ) ) );
        }
 
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : VISA_ACCEPTANCE_VAL_ZERO;
 
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'visa-acceptance-solutions' ) ) );
        }
 
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'visa-acceptance-solutions' ) ) );
        }
 
        try {
            // Get transaction ID with proper prefix (same as webhook uses for storage).
            $transaction_id = $order->get_meta( VISA_ACCEPTANCE_WC_UC_ID . VISA_ACCEPTANCE_TRANSACTION_ID, true );
            if ( empty( $transaction_id ) ) {
                wp_send_json_error( array( 'message' => __( 'Transaction ID not found.', 'visa-acceptance-solutions' ) ) );
            }
 
            $payment_adapter = new Visa_Acceptance_Payment_Adapter( $this->gateway );
            $result = $payment_adapter->refresh_payment_status( $transaction_id, $order_id );
 
            if ( VISA_ACCEPTANCE_TWO_ZERO_ONE === (int) $result['http_code'] ) {
                $response_body = json_decode( $result['body'], true );
                $status = isset( $response_body['status'] ) ? strtoupper( $response_body['status'] ) : 'UNKNOWN';
               
                // Update order status based on payment status.
                if ( 'COMPLETED' === $status ) {
                    $order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_PROCESSING, __( 'Payment accepted via refresh status check.', 'visa-acceptance-solutions' ) );
                } elseif ( in_array( $status, array( 'FAILED', 'DECLINED', 'INVALID_REQUEST' ), true ) ) {
                    $order->update_status( VISA_ACCEPTANCE_WOOCOMMERCE_ORDER_STATUS_FAILED, __( 'Payment failed - detected via refresh status.', 'visa-acceptance-solutions' ) );
                    // Restock inventory if failed.
                    wc_maybe_increase_stock_levels( $order );
                }
               
                wp_send_json_success( array(
                    /* translators: %s: payment status */
                    'message' => sprintf( __( 'Payment status refreshed successfully. Status: %s', 'visa-acceptance-solutions' ), $status ),
                ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'Failed to refresh payment status.', 'visa-acceptance-solutions' ) ) );
            }
        } catch ( Exception $e ) {
            $this->gateway->add_logs_data( array( 'Refresh payment status exception: ' . $e->getMessage() ), false, 'Refresh Payment Status', true );
            wp_send_json_error( array( 'message' => __( 'An error occurred while refreshing payment status.', 'visa-acceptance-solutions' ) ) );
        }
    }
}
