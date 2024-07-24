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

use Give\Framework\PaymentGateways\Commands\SubscriptionProcessing;



/**

 * @inheritDoc

 */

class LyfePayACHGatewaySubscriptionModule extends SubscriptionModule

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

            $response = $this->getLyfePayACHPayment([

                'amount'    => $donation->amount->formatToDecimal(),

                'name'      => trim("$donation->firstName $donation->lastName"),

                'email'     => $donation->email,

                'currency'  => $subscription->amount->getCurrency(),

                'period'    => $subscription->period->getValue()

            ]);



            if (empty($response['status'])) {

                $message = empty($response['message']) ? 'Payment not successful!' : $response['message'];

                throw new PaymentGatewayException(__($message, 'lyfepay-give'));
            }

            // EasyMerchantWebhookHandler::handle_successful_subscription([

            //     'subscription_id' => $response['subscription_id'],

            //     'charge_id' => $response['charge_id'],

            // ]);

            return new SubscriptionProcessing($response['subscription_id'], $response['charge_id']);
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

        if ($subscription->gatewayId != 'lyfepay-ach') return false;

        if (give_is_test_mode()) {

            // GiveWP is in test mode

            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {



            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }

        try {

            // Step 1: cancel the subscription with your gateway.
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl . '/subscriptions/' . $subscription->gatewaySubscriptionId . '/cancel/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => array(
                    'X-Api-Key: ' . $apiKey,
                    'X-Api-Secret: ' . $apiSecretKey,
                    'Content-Type: application/json',
                    //'User-Agent: LyfePayEmulator/1.0'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);
            $response = json_decode($response, true);

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



    /**

     * @throws Exception

     */

    private function getLyfePayACHPayment(array $data): array

    {

        $ach_info               = give_get_donation_lyfepay_ach_info();

        $accountNumber          = $ach_info['account_number'];

        $routingNumber          = $ach_info['routing_number'];

        $accountType            = $ach_info['account_type'];

        $apiKey                 = lyfepay_give_get_api_key();

        $apiSecretKey           = lyfepay_give_get_api_secret_key();

        $originalValues         = ["daily", "weekly", "monthly", "quarterly", "yearly"]; // API support these terms

        $replacementValues      = ["day", "week", "month", "quarter", "year"]; //givewp support these terms

        if (isset($data['period'])) {

            $originalValue        = $data['period'];

            $key                  = array_search($originalValue, $replacementValues);

            if ($key !== false) {

                $data['period'] = $originalValues[$key];
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

            'payment_mode'      => 'auth_and_capture',

            'amount'            => $data['amount'],

            'name'              => $data['name'],

            'email'             => $data['email'],

            'description'       => 'GiveWP donation',

            'currency'          => $data['currency'],

            'routing_number'    => $routingNumber,

            'account_type'      => $accountType,

            'account_number'    => $accountNumber,

            'payment_type'      => 'recurring',

            'entry_class_code'  => 'CCD',

            'interval'          => $data['period'],

            'allowed_cycles'    => $data['times'],

        ]);
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $apiUrl . '/ach/charge/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array(
                'X-Api-Key: ' . $apiKey,
                'X-Api-Secret: ' . $apiSecretKey,
                'Content-Type: application/json',
                //'User-Agent: LyfePayEmulator/1.0'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response, true);
        return $response;
    }
}
