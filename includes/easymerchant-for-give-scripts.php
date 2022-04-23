<?php
/**
 * Easymerchant For Give Scripts
 *
 * @package    Give
 * @subpackage Easymerchant
 * @copyright  Copyright (c) 2019, GiveWP
 * @license    https://opensource.org/licenses/gpl-license GNU Public License
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load Frontend javascript
 *
 * @since 2.5.0
 *
 * @return void
 */
function easymerchant_for_give_frontend_scripts() {

	/**
	 * Bailout, if Stripe account is not configured.
	 *
	 * We are not loading any scripts if Stripe account is not configured to avoid an intentional console error
	 * for Stripe integration.
	 */

	// Get publishable key.
	$publishable_key = easymerchant_for_give_get_publishable_key();

	if ( ! $publishable_key ) {
		return;
	}

	// Set vars for AJAX.
	$stripe_vars = apply_filters(
		'easymerchant_for_give_global_parameters',
		[
			'zero_based_currency'          => give_is_zero_based_currency(),
			'zero_based_currencies_list'   => give_get_zero_based_currencies(),
			'sitename'                     => give_get_option( 'easymerchant_checkout_name' ),
			'checkoutBtnTitle'             => esc_html__( 'Donate', 'easymerchant-for-give' ),
			'publishable_key'              => $publishable_key,
			// 'checkout_image'               => give_get_option( 'stripe_checkout_image' ),
			// 'checkout_address'             => give_get_option( 'stripe_collect_billing' ),
			// 'checkout_processing_text'     => give_get_option( 'stripe_checkout_processing_text', __( 'Donation Processing...', 'give' ) ),
			'give_version'                 => get_option( 'give_version' ),
			'donate_button_text'           => esc_html__( 'Donate Now', 'easymerchant-for-give' ),
			'float_labels'                 => give_is_float_labels_enabled(
				[
					'form_id' => get_the_ID(),
				]
			),
			'base_country'                 => give_get_option( 'base_country' ),
			'preferred_locale'             => easymerchant_for_give_get_preferred_locale(),
		]
	);

	// Load third-party stripe js when required gateways are active.
	if ( apply_filters( 'easymerchant_for_give_js_loading_conditions', easymerchant_for_give_is_any_payment_method_active() ) ) {
		$scripts_footer = give_is_setting_enabled( give_get_option( 'scripts_footer' ) ) ? true : false;
		$scripts_footer = true;
		wp_register_script( 'easymerchant-js', 'https://api.easymerchant.io/assets/checkout/easyMerchant.js', [], GIVE_VERSION, $scripts_footer );
		wp_enqueue_script( 'easymerchant-js' );
		wp_localize_script( 'easymerchant-js', 'easymerchant_for_give_vars', $stripe_vars );
	}

	wp_register_script( 'give-easymerchant-onpage-js', plugin_dir_url(__DIR__) . 'assets/js/easymerchant-for-give.js', [ 'easymerchant-js' ], GIVE_VERSION );
	wp_enqueue_script( 'give-easymerchant-onpage-js' );
}

add_action( 'wp_enqueue_scripts', 'easymerchant_for_give_frontend_scripts' );

/**
 * WooCommerce checkout compatibility.
 *
 * This prevents Give from outputting scripts on Woo's checkout page.
 *
 * @since 1.4.3
 *
 * @param bool $ret JS compatibility status.
 *
 * @return bool
 */
function easymerchant_for_give_woo_script_compatibility( $ret ) {

	if (
		function_exists( 'is_checkout' )
		&& is_checkout()
	) {
		return false;
	}

	return $ret;

}

add_filter( 'easymerchant_for_give_js_loading_conditions', 'easymerchant_for_give_woo_script_compatibility', 10, 1 );


/**
 * EDD checkout compatibility.
 *
 * This prevents Give from outputting scripts on EDD's checkout page.
 *
 * @since 1.4.6
 *
 * @param bool $ret JS compatibility status.
 *
 * @return bool
 */
function easymerchant_for_give_edd_script_compatibility( $ret ) {

	if (
		function_exists( 'edd_is_checkout' )
		&& edd_is_checkout()
	) {
		return false;
	}

	return $ret;

}

add_filter( 'easymerchant_for_give_js_loading_conditions', 'easymerchant_for_give_edd_script_compatibility', 10, 1 );
