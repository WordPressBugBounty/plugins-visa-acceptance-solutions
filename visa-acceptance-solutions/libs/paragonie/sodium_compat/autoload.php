<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delegate to the full vendor copy of paragonie/sodium_compat.
$pymt_vas_sodium_vendor = dirname( __FILE__ ) . '/../../../vendor/paragonie/sodium_compat/autoload.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ( file_exists( $pymt_vas_sodium_vendor ) ) {
	require_once $pymt_vas_sodium_vendor;
}
unset( $pymt_vas_sodium_vendor );