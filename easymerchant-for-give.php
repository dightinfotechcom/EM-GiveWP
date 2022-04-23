<?php
/**
 * Plugin Name:  		EasyMerchant for Give
 * Plugin URI:        	https://easymerchant.io/
 * Description:        	Adds the Easymerchant.io payment gateway to the available GiveWP payment methods.
 * Version:            	1.0.2
 * Requires at least:   4.9
 * Requires PHP:        5.6
 * Author:            	EasyMerchant
 * Author URI:        	https://easymerchant.io/
 * Text Domain:        	easymerchant-for-give
 * Domain Path:        	/languages
 *
 * @package             Give
 * 
 */

/**
 * Register payment method.
 *
 * @since 1.0.0
 *
 * @param array $gateways List of registered gateways.
 *
 * @return array
 */
function easymerchant_for_give_register_payment_method( $gateways ) {  
  $gateways['easymerchant'] = array(
    'admin_label'    => __( 'EasyMerchant', 'easymerchant-for-give' ), // This label will be displayed under Give settings in admin.
    'checkout_label' => __( 'EasyMerchant', 'easymerchant-for-give' ), // This label will be displayed on donation form in frontend.
  ); 
  return $gateways;
}

add_filter( 'give_payment_gateways', 'easymerchant_for_give_register_payment_method' );

easymerchant_includes();

/**
 * Register Section for Payment Gateway Settings.
 *
 * @param array $sections List of payment gateway sections.
 *
 * @since 1.0.0
 *
 * @return array
 */
function easymerchant_for_give_register_payment_gateway_sections( $sections ) {
	
	// `easymerchant-settings` is the name/slug of the payment gateway section.
	$sections['easymerchant-settings'] = __( 'EasyMerchant', 'easymerchant-for-give' );

	return $sections;
}

add_filter( 'give_get_sections_gateways', 'easymerchant_for_give_register_payment_gateway_sections' );

/**
 * Register Admin Settings.
 *
 * @param array $settings List of admin settings.
 *
 * @since 1.0.0
 *
 * @return array
 */
function easymerchant_for_give_register_payment_gateway_setting_fields( $settings ) {

	switch ( give_get_current_setting_section() ) {

		case 'easymerchant-settings':
			$settings = array(
				array(
					'id'   => 'give_title_easymerchant',
					'type' => 'title',
				),
			);

      $settings[] = array(
        'name' => __( 'Publishable Key', 'easymerchant-for-give' ),
        'desc' => __( 'Enter your Publishable Key, found in your easymerchant Dashboard.', 'easymerchant-for-give' ),
        'id'   => 'easymerchant_publishable_key',
        'type' => 'text',
      );

      $settings[] = [
        'name'          => esc_html__( 'Checkout Heading', 'easymerchant-for-give' ),
        'desc'          => esc_html__( 'This is the main heading within the modal checkout. Typically, this is the name of your organization, cause, or website.', 'easymerchant-for-give' ),
        'id'            => 'easymerchant_checkout_name',
        'wrapper_class' => 'easymerchant-checkout-field ',
        'default'       => get_bloginfo( 'name' ),
        'type'          => 'text',
      ];

			$settings[] = array(
				'id'   => 'give_title_easymerchant',
				'type' => 'sectionend',
			);

			break;

	} // End switch().

	return $settings;
}

// change the easymerchant_for_give prefix to avoid collisions with other functions.
add_filter( 'give_get_settings_gateways', 'easymerchant_for_give_register_payment_gateway_setting_fields' );

function easymerchant_includes() {
  easymerchant_include_admin_files();
  
  // Load files which are necessary for front as well as admin end.
  require_once plugin_dir_path(__FILE__) . 'includes/easymerchant-for-give-helpers.php';
  
  // Bailout, if any of the Easymerchant gateways are not active.
  if ( ! easymerchant_for_give_supported_payment_methods() ) {
    return;
  }

  easymerchant_include_frontend_files();
}

function easymerchant_include_admin_files() {
  require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-helpers.php';
}

function easymerchant_include_frontend_files() {
  // Load files which are necessary for front as well as admin end.

  require_once plugin_dir_path(__FILE__) . 'includes/payment-methods/class-easymerchant-for-give-checkout.php';

  require_once plugin_dir_path(__FILE__) . 'includes/easymerchant-for-give-scripts.php';
}

/**
 * Process Square checkout submission.
 *
 * @param array $posted_data List of posted data.
 *
 * @since  1.0.0
 * @access public
 *
 * @return void
 */

// change the easymerchant_for_give prefix to avoid collisions with other functions.
function easymerchant_for_give_process_easymerchant_donation( $posted_data ) {

  // Make sure we don't have any left over errors present.
  give_clear_errors();

  // Any errors?
  $errors = give_get_errors();

  // No errors, proceed.
  if ( ! $errors ) {

    $form_id         = intval( $posted_data['post_data']['give-form-id'] );
    $price_id        = ! empty( $posted_data['post_data']['give-price-id'] ) ? $posted_data['post_data']['give-price-id'] : 0;
    $donation_amount = ! empty( $posted_data['price'] ) ? $posted_data['price'] : 0;

    // Setup the payment details.
    $donation_data = array(
      'price'           => $donation_amount,
      'give_form_title' => $posted_data['post_data']['give-form-title'],
      'give_form_id'    => $form_id,
      'give_price_id'   => $price_id,
      'date'            => $posted_data['date'],
      'user_email'      => $posted_data['user_email'],
      'purchase_key'    => $posted_data['purchase_key'],
      'currency'        => give_get_currency( $form_id ),
      'user_info'       => $posted_data['user_info'],
      'status'          => 'complete',
      'gateway'         => 'easymerchant',
    );

    // Record the pending donation.
    $donation_id = give_insert_payment( $donation_data );

    if ( ! $donation_id ) {

      // Record Gateway Error as Pending Donation in Give is not created.
      give_record_gateway_error(
        __( 'Easymerchant Error', 'easymerchant-for-give' ),
        sprintf(
        /* translators: %s Exception error message. */
          __( 'Unable to create a pending donation with Give.', 'easymerchant-for-give' )
        )
      );

      // Send user back to checkout.
      give_send_back_to_checkout( '?payment-mode=easymerchant' );
      return;
    }

    // Do the actual payment processing using the custom payment gateway API. To access the GiveWP settings, use give_get_option() 
                // as a reference, this pulls the API key entered above: give_get_option('easymerchant_for_give_easymerchant_api_key')
    give_send_to_success_page();

  } else {

    // Send user back to checkout.
    give_send_back_to_checkout( '?payment-mode=easymerchant' );
  } // End if().
}

// change the easymerchant_for_give prefix to avoid collisions with other functions.
add_action( 'give_gateway_easymerchant', 'easymerchant_for_give_process_easymerchant_donation' );