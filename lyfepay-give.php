<?php



/**

 * Plugin Name:  		lyfePAY

 * Plugin URI:        	https://easymerchant.io/

 * Description:        	Adds the lyfePAY payment gateway to the available Give payment methods.

 * Version:            	2.0.5

 * Requires at least:   4.9

 * Requires PHP:        5.6

 * Author:            	lyfePAY

 * Author URI:        	https://easymerchant.io/

 * Text Domain:        	lyfepay-give

 *

 * @package             Give

 * 

 */



// Exit if accessed directly.

if (!defined('ABSPATH')) {

    exit;
}



// Plugin constants.

if (!defined('LYFEPAY_FOR_GIVE_VERSION')) {

    define('LYFEPAY_FOR_GIVE_VERSION', '2.0.5');
}



lyfepay_givewp_includes();



// Webhook Initialize

add_action('init', 'webhook_file_callback');

function webhook_file_callback()

{

    if (isset($_GET['give-listener']) && $_GET['give-listener'] == 'lyfepay') {

        $rawRequestBody = file_get_contents("php://input");



        require_once plugin_dir_path(__FILE__) . 'webhook/lyfepay-webhook-handler.php';

        $webhook_handler = new LyfePayWebhookHandler(site_url() . '?give-listener=lyfepay');

        $webhook_handler->handle_webhook_request($rawRequestBody);
    }
}



/**

 * Register Section for Payment Gateway Settings.

 * @param array $sections List of payment gateway sections.

 * @since 1.0.0

 * @return array

 */



function lyfepay_givewp_register_payment_gateway_sections($sections)

{

    // `lyfepay-settings` is the name/slug of the payment gateway section.

    $sections['lyfepay-settings'] = __('LyfePay', 'lyfepay-give');

    return $sections;
}

add_filter('give_get_sections_gateways', 'lyfepay_givewp_register_payment_gateway_sections');



/**

 * Register Admin Settings.

 * @param array $settings List of admin settings.

 * @since 1.0.0

 * @return array

 */



function lyfepay_givewp_register_payment_gateway_setting_fields($settings)

{

    switch (give_get_current_setting_section()) {

        case 'lyfepay-settings':

            $settings = array(

                array(

                    'id'   => 'give_title_lyfepay',

                    'type' => 'title',

                ),

            );

            $settings[] = array(

                'name' => __('Publishable Key', 'lyfepay-give'),

                'desc' => __('Enter your Publishable Key, found in your lyfePAY Dashboard.', 'lyfepay-give'),

                'id'   => 'lyfepay_publishable_key',

                'type' => 'text',

            );

            $settings[] = array(

                'name' => __('API Key', 'lyfepay-give'),

                'desc' => __('Enter your API Key, found in your lyfePAY Dashboard.', 'lyfepay-give'),

                'id'   => 'lyfepay_api_key',

                'type' => 'text',

            );

            $settings[] = array(

                'name' => __('API Secret Key', 'lyfepay-give'),

                'desc' => __('Enter your API Secret Key, found in your lyfePAY Dashboard.', 'lyfepay-give'),

                'id'   => 'lyfepay_api_secret_key',

                'type' => 'text',

            );

            $settings[] = array(

                'name' => __('Webhook Url', 'lyfepay-give'),

                'desc' => __('Copy this webhook Url and paste in your lyfePAY Dashboard', 'lyfepay-give'),

                'id'   => 'lyfepay_webhook_url',

                'type' => 'text',

                'default' => site_url('?give-listener=lyfepay', 'lyfepay-give'),

            );

            $settings[] = [

                'name'          => esc_html__('Checkout Heading', 'lyfepay-give'),

                'desc'          => esc_html__('This is the main heading within the modal checkout. Typically, this is the name of your organization, cause, or website.', 'lyfepay-give'),

                'id'            => 'lyfepay_checkout_name',

                'wrapper_class' => 'lyfepay-checkout-field ',

                'default'       => get_bloginfo('name'),

                'type'          => 'text',

            ];

            $settings[] = [

                'name'  => esc_html__('Heading', 'lyfepay-give'),

                'desc'  => esc_html__('This is the heading above credit card field.', 'lyfepay-give'),

                'id'    => 'lyfepay_gateway_heading',

                'type'  => 'text',

                'default' => esc_html__('Make your donations quickly and securely with lyfePAY', 'lyfepay-give'),

            ];

            $settings[] = array(

                'name' => __('Description', 'lyfepay-give'),

                'desc' => __('You can change the text above credit card field', 'lyfepay-give'),

                'id'   => 'lyfepay_gateway_description',

                'type' => 'textarea',

            );

            $settings[] = array(

                'name' => __('Billing Address', 'lyfepay-give'),

                'desc' => __('You can enable or disable billing address.', 'lyfepay-give'),

                'id'   => 'gateway_collect_billing',

                'type' => 'checkbox',

            );

            $settings[] = array(

                'id'   => 'give_title_lyfepay',

                'type' => 'sectionend',

            );



            break;
    }

    return $settings;
}

add_filter('give_get_settings_gateways', 'lyfepay_givewp_register_payment_gateway_setting_fields');



function lyfepay_givewp_includes()

{

    lyfepay_givewp_include_admin_files();

    // Load files which are necessary for frontend as well as admin end.

    require_once plugin_dir_path(__FILE__) . 'includes/lyfepay-givewp-helpers.php';

    // Bailout, if any of the lyfePAY gateways are not active.

    if (!lyfepay_givewp_supported_payment_methods()) {

        return;
    }
}



function lyfepay_givewp_include_admin_files()

{

    require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-helpers.php';
}



function give_get_donation_lyfepay_cc_info()

{

    // Sanitize the values submitted with donation form.

    $post_data = give_clean($_POST);

    $cc_info                        = [];

    $cc_info['card_name']           = !empty($post_data['card_name']) ? $post_data['card_name'] : '';

    $cc_info['card_number_easy']    = !empty($post_data['card_number_easy']) ? $post_data['card_number_easy'] : '';

    $cc_info['card_number_easy']    = str_replace(' ', '', $cc_info['card_number_easy']);

    $cc_info['card_cvc']            = !empty($post_data['card_cvc']) ? $post_data['card_cvc'] : '';

    $cc_info['card_exp_month']      = !empty($post_data['card_exp_month_0']) ? $post_data['card_exp_month_0'] : '';

    $cc_info['card_exp_year']       = !empty($post_data['card_exp_year']) ? $post_data['card_exp_year'] : '';

    // Return cc info.

    return $cc_info;
}



function give_get_donation_lyfepay_ach_info()

{

    $post_data  = give_clean($_POST);

    $ach_info   =  [];

    $ach_info['account_number'] = !empty($post_data['account_number']) ? $post_data['account_number'] : '';

    $ach_info['routing_number'] = !empty($post_data['routing_number']) ? $post_data['routing_number'] : '';

    $ach_info['account_type']   = !empty($post_data['account_type']) ? $post_data['account_type'] : '';

    return $ach_info;
}



function lyfepay_givewp_display_minimum_recurring_version_notice()

{

    if (

        defined('GIVE_RECURRING_PLUGIN_BASENAME') &&

        is_plugin_active(GIVE_RECURRING_PLUGIN_BASENAME)

    ) {

        if (

            version_compare(LYFEPAY_FOR_GIVE_VERSION, '2.0.6', '>=') &&

            version_compare(LYFEPAY_FOR_GIVE_VERSION, '2.1', '<') &&

            version_compare(GIVE_RECURRING_VERSION, '1.7', '<')

        ) {

            Give()->notices->register_notice(array(

                'id'          => 'lyfepay-for-give-require-minimum-recurring-version',

                'type'        => 'error',

                'dismissible' => false,

                'description' => __('Please update the <strong>Give Recurring Donations</strong> add-on to version 1.7+ to be compatible with the latest version of the lyfePAY payment gateway.', 'lyfepay-give'),

            ));
        } elseif (

            version_compare(LYFEPAY_FOR_GIVE_VERSION, '2.1', '>=') &&

            version_compare(GIVE_RECURRING_VERSION, '1.8', '<')

        ) {

            Give()->notices->register_notice(array(

                'id'          => 'lyfepay-for-give-require-minimum-recurring-version',

                'type'        => 'error',

                'dismissible' => false,

                'description' => __('Please update the <strong>Give Recurring Donations</strong> add-on to version 1.8+ to be compatible with the latest version of the lyfePAY payment gateway.', 'lyfepay-give'),

            ));
        }
    }
}

add_action('admin_notices', 'lyfepay_givewp_display_minimum_recurring_version_notice');



// Register the gateway with the givewp gateway api

add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {



    require_once plugin_dir_path(__FILE__) . 'paymentgateways/lyfepay-card/class-lyfepay-gateway.php';

    $paymentGatewayRegister->registerGateway(LyfePayGateway::class);


    require_once plugin_dir_path(__FILE__) . 'paymentgateways/lyfepay-ach/class-lyfepay-ach.php';

    $paymentGatewayRegister->registerGateway(LyfePayACH::class);
});



// Register the gateways subscription module

add_filter(

    "givewp_gateway_lyfepay-gateway_subscription_module",

    static function () {


        require_once plugin_dir_path(__FILE__) . 'paymentgateways/lyfepay-card/class-lyfepay-gateway-subscription-module.php';

        return LyfePayGatewaySubscriptionModule::class;
    }

);



add_filter(

    "givewp_gateway_lyfepay-ach_subscription_module",

    static function () {


        require_once plugin_dir_path(__FILE__) . 'paymentgateways/lyfepay-ach/class-lyfepay-ach-subscription.php';

        return LyfePayACHGatewaySubscriptionModule::class;
    }

);

function lyfepay_plugin_setting_page($actions)
{
    $settinglinks = array(
        '<a href="' . admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=lyfepay-settings') . '">Settings</a>',
    );
    $actions = array_merge($actions, $settinglinks);
    return $actions;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'lyfepay_plugin_setting_page', '10', '4');
