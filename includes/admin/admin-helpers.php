<?php
/**
 * Easymerchant For Give Core Admin Helper Functions.
 *
 * @since 2.5.4
 *
 * @package    Give
 * @subpackage Easymerchant Core
 * @copyright  Copyright (c) 2019, GiveWP
 * @license    https://opensource.org/licenses/gpl-license GNU Public License
 */

// Exit, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This function is used to get a list of slug which are supported by payment gateways.
 *
 * @since 2.5.5
 *
 * @return array
 */
function easymerchant_for_give_supported_payment_methods() {
	return [
		'easymerchant',
        'easymerchant_ach',
        'easymerchant_crypto',
        'easymerchant_google_pay',
        'easymerchant_apple_pay',
        'easymerchant_checkout'
	];
}

/**
 * This function is used to check whether a payment method supported by Easymerchant with Give is active or not.
 *
 * @since 2.5.5
 *
 * @return bool
 */
function easymerchant_for_give_is_any_payment_method_active() {

	// Get settings.
	$settings             = give_get_settings();
	$gateways             = isset( $settings['gateways'] ) ? $settings['gateways'] : [];
	$easymerchantPaymentMethods = easymerchant_for_give_supported_payment_methods();

	// Loop through gateways list.
	foreach ( array_keys( $gateways ) as $gateway ) {

		// Return true, if even single payment method is active.
		if ( in_array( $gateway, $easymerchantPaymentMethods, true ) ) {
			return true;
		}
	}

	return false;
}
