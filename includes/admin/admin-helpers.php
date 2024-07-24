<?php

/**
 * LyfePay Core Admin Helper Functions.
 *
 * @since 2.5.4
 *
 * @package    Give
 * @subpackage Lyfepay Core
 * @copyright  Copyright (c) 2019, GiveWP
 * @license    https://opensource.org/licenses/gpl-license GNU Public License
 */

// Exit, if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * This function is used to get a list of slug which are supported by payment gateways.
 *
 * @since 2.5.5
 *
 * @return array
 */
function lyfepay_givewp_supported_payment_methods()
{
    return [
        'lyfepay',
    ];
}

/**
 * This function is used to check whether a payment method supported by LyfePAY is active or not.
 *
 * @since 2.5.5
 *
 * @return bool
 */
function lyfepay_givewp_is_any_payment_method_active()
{
    // Get settings.
    $settings       = give_get_settings();
    $gateways       = isset($settings['gateways']) ? $settings['gateways'] : [];
    $PaymentMethods = lyfepay_givewp_supported_payment_methods();

    // Loop through gateways list.
    foreach (array_keys($gateways) as $gateway) {

        // Return true, if even single payment method is active.
        if (in_array($gateway, $PaymentMethods, true)) {
            return true;
        }
    }

    return false;
}
