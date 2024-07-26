<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\Commands\SubscriptionComplete;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\SubscriptionModule;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * @inheritDoc
 */

class LyfePayGatewaySubscriptionModule extends SubscriptionModule
{
    /**
     * @inerhitDoc
     *
     * @throws Exception|PaymentGatewayException
     */
    public function createSubscription(
        Donation $donation,
        Subscription $subscription,
        $gatewayData
    ) {

        try {
            $response = $this->makePaymentRequest([
                'amount'    => $donation->amount->formatToDecimal(),
                'name'      => trim("$donation->firstName $donation->lastName"),
                'email'     => $donation->email,
                'currency'  => $subscription->amount->getCurrency(),
                'period'    => $subscription->period->getValue(),
                'frequency' => $subscription->frequency
            ]);

            if (empty($response['status'])) {
                $message = empty($response['message']) ? 'Payment not successful!' : $response['message'];
                throw new PaymentGatewayException(__($message, 'lyfepay-give'));
            }

            return new SubscriptionComplete($response['charge_id'], $response['subscription_id']);
        } catch (Exception $e) {

            $errorMessage = $e->getMessage();

            $donation->status = DonationStatus::FAILED();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(esc_html__('Donation failed. Reason: %s', 'lyfepay-give'), $errorMessage)
            ]);

            throw new PaymentGatewayException($errorMessage);
        }
    }

    /**
     * @inerhitDoc
     *
     * @throws PaymentGatewayException
     */
    public function cancelSubscription(Subscription $subscription)
    {
        $apiKey                 = lyfepay_give_get_api_key();
        $apiSecretKey           = lyfepay_give_get_api_secret_key();
        if ($subscription->gatewayId != 'lyfepay-gateway') return false;
        if (give_is_test_mode()) {
            // GiveWP is in test mode
            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {

            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }
        try {
            // Step 1: cancel the subscription with your gateway.
            $response = wp_remote_post($apiUrl . '/subscriptions/' . $subscription->gatewaySubscriptionId . '/cancel/', array(
                'method'    => 'POST',
                'headers'   => array(
                    'X-Api-Key'      => $apiKey,
                    'X-Api-Secret'   => $apiSecretKey,
                    'Content-Type'   => 'application/json',
                ),
            ));


            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            // Step 2: update the subscription status to cancelled.
            $subscription->status = SubscriptionStatus::CANCELLED();
            $subscription->save();
        } catch (\Exception $exception) {
            throw new PaymentGatewayException(
                sprintf(
                    'Unable to cancel subscription. %s',
                    $exception->getMessage()
                ),
                $exception->getCode(),
                $exception
            );
        }
    }

    public function createRenewal(Subscription $subscription, int $count = 1, array $attributes = [])
    {
        return Donation::factory()->count($count)->create(
            array_merge([
                'amount' => $subscription->amount,
                'status' => DonationStatus::COMPLETE(),
                'type'   => DonationType::RENEWAL(),
                'subscriptionId' => $subscription->id,
            ], $attributes)
        );
        $apiKey                 = lyfepay_give_get_api_key();
        $apiSecretKey           = lyfepay_give_get_api_secret_key();
        if ($subscription->gatewayId != 'lyfepay-gateway') return false;
        if (give_is_test_mode()) {
            // GiveWP is in test mode
            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {

            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }
        try {
            // Step 1: Renew the subscription with your gateway.
            $response = wp_remote_post($apiUrl . '/subscriptions/' . $subscription->gatewaySubscriptionId . '/renew/', array(
                'method'    => 'POST',
                'headers'   => array(
                    'X-Api-Key'      => $apiKey,
                    'X-Api-Secret'   => $apiSecretKey,
                    'Content-Type'   => 'application/json',
                ),
            ));


            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            $subscription->status = SubscriptionStatus::RENEWED();
            $subscription->save();
        } catch (\Exception $exception) {
            throw new PaymentGatewayException(
                sprintf(
                    'Unable to Renew subscription. %s',
                    $exception->getMessage()
                ),
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * makePaymentRequest
     */
    private function makePaymentRequest(array $data): array
    {

        $cc_info                = give_get_donation_lyfepay_cc_info();

        // Use the card details in this function calling from "give_get_donation_lyfepay_cc_info" function.
        $cc_holder              = $cc_info['card_name'];
        $cc_number              = $cc_info['card_number_easy'];
        $month                  = $cc_info['card_exp_month'];
        $year                   = $cc_info['card_exp_year'];
        $cc_cvc                 = $cc_info['card_cvc'];
        $currentDate            = date("m/d/Y");
        $originalValues         = ["day", "week", "month", "quarter", "year"]; // API support these terms
        $replacementValues      = ["daily", "weekly", "monthly", "quarterly", "yearly"]; //givewp support these terms
        $apiKey                 = lyfepay_give_get_api_key();
        $apiSecretKey           = lyfepay_give_get_api_secret_key();
        if (isset($data['period'])) {
            $originalValue        = $data['period'];
            $key                  = array_search($originalValue, $originalValues);
            if ($key !== false) {
                $data['period']   = $replacementValues[$key];
            }
        }
        if (give_is_test_mode()) {
            // GiveWP is in test mode
            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {
            // GiveWP is not in test mode
            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }

        $body = json_encode([
            'payment_mode'    => 'auth_and_capture',
            'amount'          => $data['amount'],
            'name'            => $data['name'],
            'email'           => $data['email'],
            'description'     => 'GiveWP donation',
            'start_date'      => $currentDate,
            'currency'        => $data['currency'],
            'card_number'     => $cc_number,
            'exp_month'       => $month,
            'exp_year'        => $year,
            'cvc'             => $cc_cvc,
            'cardholder_name' => $cc_holder,
            'payment_type'    => 'recurring',
            'interval'        => $data['period'],
            'allowed_cycles'  => $data['frequency'],
        ]);
        $response = wp_remote_post($apiUrl . '/charges/', array(
            'method'    => 'POST',
            'headers'   => array(
                'X-Api-Key'      => $apiKey,
                'X-Api-Secret'   => $apiSecretKey,
                'Content-Type'   => 'application/json',
            ),
            'body'               => $body,
        ));
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        return $response_data;
    }
}
