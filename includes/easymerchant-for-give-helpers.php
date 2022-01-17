<?php

/**
 * This function is used to return the checkout type.
 *
 * Note: This function is for internal purposes only and will get deprecated with legacy Stripe Checkout.
 *
 * @since 2.5.5
 *
 * @return string
 */
function easymerchant_for_give_get_checkout_type() {
	return 'modal';
}

/**
 * Get Publishable Key.
 *
 * @param int $form_id Form ID.
 *
 * @since 2.5.0
 *
 * @return string
 */
function easymerchant_for_give_get_publishable_key( $form_id = 0 ) {

    return give_get_option( 'easymerchant_publishable_key', '' );
}

/**
 * Get Preferred Locale based on the selection of language.
 *
 * @since 2.5.0
 *
 * @return string
 */
function easymerchant_for_give_get_preferred_locale() {

    $language_code = substr( get_locale(), 0, 2 ); // Get the lowercase language code. For Example, en, es, de.

    return apply_filters( 'easymerchant_for_give_elements_preferred_locale', $language_code );
}


/**
 * This function is used to display payment request donate button.
 *
 * @param int   $form_id    Donation Form ID.
 * @param array $args       List of essential arguments.
 * @param bool  $showFields Whether to show fields or not.
 *
 * @return mixed
 * @since 2.2.0
 *
 */
function easymerchant_for_give_payment_request_donate_button( $form_id, $args, $showFields ) {

    // Disable showing default donate button.
    remove_action( 'give_donation_form_after_cc_form', 'give_checkout_submit', 9999 );

    $id_prefix       = ! empty( $args['id_prefix'] ) ? $args['id_prefix'] : 0;
    $user_agent      = give_get_user_agent();
    $selectedGateway = give_get_chosen_gateway( $form_id );
    ob_start();
    ?>
    <fieldset id="give_purchase_submit" class="give-donation-submit">
        <?php
        /**
         * Fire before donation form submit.
         *
         * @since 2.2.0
         */
        do_action( 'give_donation_form_before_submit', $form_id, $args );

        give_checkout_hidden_fields( $form_id );

        if (
            'stripe_google_pay' === $selectedGateway ||
            'stripe_apple_pay' === $selectedGateway
        ) {
            if ( $showFields ) {
                echo easymerchant_for_give_payment_request_button_markup( $form_id, $args );
            }
        } else {
            // Default to Give Core method.
            echo give_get_donation_form_submit_button( $form_id, $args );
        }

        /**
         * Fire after donation form submit.
         *
         * @since 2.2.0
         */
        do_action( 'give_donation_form_after_submit', $form_id, $args );
        ?>
    </fieldset>
    <?php

    return ob_get_clean();
}

/**
 * Load Payment Request Button Markup.
 *
 * @param int   $formId Donation Form ID.
 * @param array $args   List of additional arguments.
 *
 * @since 2.2.12
 *
 * @return void|mixed
 */
function easymerchant_for_give_payment_request_button_markup( $formId, $args ) {
    ob_start();
    $id_prefix  = ! empty( $args['id_prefix'] ) ? $args['id_prefix'] : 0;
    $user_agent = give_get_user_agent();
    ?>
    <div id="give-stripe-payment-request-button-<?php echo esc_html( $id_prefix ); ?>" class="give-stripe-payment-request-button give-hidden">
        <div class="give_error">
            <p>
                <strong><?php esc_attr_e( 'ERROR:', 'give-stripe' ); ?></strong>
                <?php
                if ( ! is_ssl() ) {
                    esc_attr_e( 'In order to donate using Apple or Google Pay the connection needs to be secure. Please visit the secure donation URL (https) to give using this payment method.', 'give-stripe' );
                } elseif ( preg_match( '/Chrome[\/\s](\d+\.\d+)/', $user_agent ) ) {
                    esc_attr_e( 'Either you do not have a saved card to donate with Google Pay or you\'re using an older version of Chrome without Google Pay support.', 'give-stripe' );
                } elseif ( preg_match( '/Safari[\/\s](\d+\.\d+)/', $user_agent ) ) {
                    esc_attr_e( 'Either your browser does not support Apple Pay or you do not have a saved payment method.', 'give-stripe' );
                }
                ?>
            </p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
