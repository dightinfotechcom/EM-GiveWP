<?php

/**
 * Class LyfePayWebhookHandler
 */


use Give_Subscription;

class LyfePayWebhookHandler
{
    public $webhook_url;

    public function __construct($webhook_url)
    {

        $this->webhook_url = $webhook_url;
        $this->register_webhook_endpoint();
    }

    // Process
    public function process_webhook()
    {
        if (get_query_var('lyfepay')) {
            $payload = json_decode(file_get_contents('php://input'), true);
            if ($payload === null) {
                // Handle JSON decoding error
                status_header(400);
                exit('Invalid JSON payload');
            }
            $this->handle_webhook($payload);
            status_header(200);
            exit;
        }
    }

    // Register webhook endpoint
    public static function register_webhook_endpoint()
    {
        register_rest_route('', '/lyfepay', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook_request'),
            'args' => array(
                'lyfepay' => array(
                    'required' => true,
                    'validate_callback' => function ($param, $request, $key) {
                        return $param === 'lyfepay';
                    },
                ),
            ),
        ));
    }

    public static function handle_webhook_request($request)
    {
        if ($request === null) {
            status_header(400);
            exit('Invalid webhook request');
        }

        $payload = $request;

        $data = json_decode($payload, true);

        if ($data === null) {
            status_header(400);
            exit('Invalid JSON payload');
        }
        if (!isset($data['event'])) {
            status_header(400);
            exit('Invalid webhook payload. Missing "event" key.');
        }
        // Handle different event types
        switch ($data['event']) {
            case 'charge.updated':
                self::handle_charge_updated($data);
                break;
            case 'payment.success':
                self::handle_successful_payment($data);
                break;
            case 'subscription.renewed':
                self::handle_subscription_renewed($data);
                break;
            case 'subscription.completed':
                self::handle_successful_subscription($data);
                break;
            default:
                error_log('Unrecognized event type: ' . $data['event']);
                break;
        }
        status_header(200);
        exit;
    }

    /**
     * Handle successful payment webhook
     */
    private static function handle_successful_payment($data)
    {
        // Extract relevant information from the webhook payload
        $reference_number = $data['reference_number'];
        $amount = $data['amount'];
        $status = $data['status'];

        // Retrieve the donation record from your database based on the reference number
        $donation = self::get_donation_by_reference_number($reference_number);

        // Verify that the donation record exists
        if (!$donation) {
            // Log an error or handle the situation where the donation record is not found
            error_log('Donation record not found for reference number: ' . $reference_number);
            return;
        }

        // Update the donation status to indicate a successful payment
        if ($status === 'Paid') {
            $donation->status = DonationStatus::COMPLETED();
        } elseif ($status === 'Declined') {
            $donation->status = DonationStatus::ABANDONED();
        }
        $donation->save();

        // Log the payment status
        $status_message = ($status === 'Paid') ? 'successful' : 'declined';
        DonationNote::create([
            'donationId' => $donation->id,
            'content'    => sprintf(__('Payment %s. Amount: %s', 'lyfepay-give'), $status_message, $amount),
        ]);
    }

    // Helper function to retrieve donation by reference number
    private static function get_donation_by_reference_number($reference_number)
    {
        global $wpdb;

        // Construct the table name with WordPress prefix
        $table_name = $wpdb->prefix . 'give_donationmeta';

        // Prepare and execute the database query to retrieve the donation ID based on the reference number
        $donation_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT donation_id FROM $table_name WHERE meta_key = '_give_payment_transaction_id' AND meta_value = %s",
                $reference_number
            )
        );

        // Check if a donation ID was found
        if ($donation_id) {
            // Retrieve the donation record from the GiveWP donations table based on the donation ID
            $donation = give_get_payment($donation_id);

            // Check if the donation record exists
            if ($donation) {
                return $donation; // Return the donation object
            } else {
                // Log an error or handle the situation where the donation record is not found
                error_log('Donation record not found for reference number: ' . $reference_number);
            }
        } else {
            // Log an error or handle the situation where the reference number is not found
            error_log('Reference number not found: ' . $reference_number);
        }

        return null; // Return null if donation not found
    }

    /**
     * Handle charge updated webhook
     */
    private static function handle_charge_updated($data)
    {
        // Extract relevant information from the webhook payload
        $name = isset($data['name']) ? $data['name'] : '';
        $amount = isset($data['amount']) ? $data['amount'] : '';
        $status = isset($data['status']) ? $data['status'] : '';
        $reference_number = isset($data['reference_number']) ? $data['reference_number'] : '';
        // Determine the payment method (credit card or ACH) 
        $payment_method = isset($data['payment_type']) ? $data['payment_type'] : '';

        global $wpdb;

        // Construct the table name with WordPress prefix
        $table_name = $wpdb->prefix . 'give_donationmeta';

        // Retrieve the donation ID based on the reference number
        $transaction_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT donation_id FROM $table_name WHERE meta_key = '_give_payment_transaction_id' AND meta_value = %s",
                $reference_number
            )
        );

        // Check if a transaction ID was found
        if ($transaction_id) {
            // Update the relevant information in your system
            update_post_meta($transaction_id, '_give_payment_status', $status);
            update_post_meta($transaction_id, '_give_payment_method', $payment_method);

            // Log the charge update details
            error_log(sprintf(__('Charge updated. Name: %s Amount: %s Status: %s Reference Number: %s', 'lyfepay-give'), $name, $amount, $status, $reference_number));
        } else {
            // Log an error or handle the situation where the transaction ID is not found
            error_log('Transaction ID not found for reference number: ' . $reference_number);
        }
    }

    private static function handle_successful_subscription($data)
    {
        $subscriptionId = $data['subscription_id'];
        $chargeId = $data['charge_id'];

        $subscription = Subscription::find($subscriptionId);
        $subscription->status = 'active';
        $subscription->save();
    }

    private static function handle_subscription_renewed($data)
    {
        // Extract relevant information from the webhook payload
        $subscription_id = isset($data['subscription_id']) ? $data['subscription_id'] : '';
        $amount = isset($data['amount']) ? $data['amount'] : 0;
        $status = isset($data['status']) ? $data['status'] : '';
        $paid_count = isset($data['paid_count']) ? $data['paid_count'] : '';
        $date_next_renewal = isset($data['date_next_renewal']) ? $data['date_next_renewal'] : '';
        $reference_number = isset($data['transaction']['reference_number']) ? $data['transaction']['reference_number'] : '';
        $payment_method = isset($data['payment_type']) ? $data['payment_type'] : '';

        $db = new Give_Subscriptions_DB;
        $results = $db->get_subscriptions([
            'profile_id' => $subscription_id,
        ]);

        $subscription = new Give_Subscription($results[0]->id);
        if ($status === 'Active') {
            $post_date =  0;
            $payment = $subscription->add_payment(array(
                'amount'         => $amount,
                'transaction_id' => 0,
                'post_date'      => $date_next_renewal,
            ));

            print_r($data);
            die();
        }
    }

    /**
     * Verify the webhook request
     */
    private static function verify_webhook_request($headers, $payload, $api_secret)
    {
        if (!isset($headers['X-LyfePay-Signature'], $headers['X-LyfePay-Timestamp'])) {
            return false;
        }
        $timestamp = $headers['X-LyfePay-Timestamp'];
        $signature = $headers['X-LyfePay-Signature'];
        $allowed_time_window = 300;
        if (abs(time() - $timestamp) > $allowed_time_window) {
            return false;
        }
        $expected_signature = hash_hmac('sha256', $payload, $api_secret);
        return hash_equals($expected_signature, $signature);
    }
}
