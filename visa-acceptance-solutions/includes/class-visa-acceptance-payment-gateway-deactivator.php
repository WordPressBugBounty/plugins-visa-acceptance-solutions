<?php
/**
 * Fired during plugin deactivation
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __DIR__ ) . 'includes/class-visa-acceptance-payment-gateway-activator.php';

/**
 *
 * Visa Acceptance Deactivator Class
 *
 * Fired during plugin deactivation.
 *
 * @package    Visa_Acceptance_Solutions
 * @subpackage Visa_Acceptance_Solutions/includes
 */
class Visa_Acceptance_Payment_Gateway_Deactivator {

	/**
	 * Plugin deactivation method. Perform any deactivation tasks here.
	 */
	public static function deactivate() {
		global $wpdb;
		$payment_method = 'visa_acceptance_solutions_unified_checkout';
		$type           = 'shop_subscription';
		if(is_plugin_active('woocommerce-gateway-cybersource/woocommerce-gateway-cybersource.php')) {
			if ( handle_hpos_compatibility() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sv_subscriptions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}wc_orders WHERE payment_method = %s AND type = %s",
						$payment_method,
						$type
					),
					ARRAY_N
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sv_subscriptions = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID as id FROM {$wpdb->prefix}posts p INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id WHERE pm.meta_value = %s AND p.post_type = %s",
						$payment_method,
						$type
					),
					ARRAY_N
				);
			}
			foreach ( $sv_subscriptions as $sv_subsription_id ) {
				$sv_subsription_id = (array) $sv_subsription_id;
				if(is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php')) {
					$sv_subscription   = wcs_get_subscription( $sv_subsription_id[0] );
					if ( VISA_ACCEPTANCE_UC_ID === $sv_subscription->get_payment_method( VISA_ACCEPTANCE_EDIT ) ) {
							$sv_subscription->set_payment_method( VISA_ACCEPTANCE_SV_GATEWAY_ID );
							$sv_subscription->save();
					}
				}
			}
		}
	}
}
