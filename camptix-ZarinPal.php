<?php
/**
 * Plugin Name: CampTix ZarinPal Payment Gateway
 * Plugin URI: https://ir.Zarinpal.com/Labs/Details/IPTest/camptix-ZarinPal/
 * Description: ZarinPal Payment Gateway for CampTix
 * Author: Masoud Amini
 * Author URI: http://MasoudAmini.ir
 * Version: 1.0.0
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Add IRR currency
add_filter( 'camptix_currencies', 'camptix_add_irr_currency' );
function camptix_add_irr_currency( $currencies ) {
	$currencies['IRR'] = array(
		'label' => __( 'ریال ایران', 'camptix' ),
		'format' => 'ریال %s',
	);
	return $currencies;
}

// Load the ZarinPal Payment Method
add_action( 'camptix_load_addons', 'camptix_ZarinPal_load_payment_method' );
function camptix_ZarinPal_load_payment_method() {
	if ( ! class_exists( 'CampTix_Payment_Method_ZarinPal' ) )
		require_once plugin_dir_path( __FILE__ ) . 'classes/class-camptix-payment-method-ZarinPal.php';
	camptix_register_addon( 'CampTix_Payment_Method_ZarinPal' );
}

?>