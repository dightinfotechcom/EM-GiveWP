<?php



use Give\Donations\Models\Donation;

use Give\Donations\Models\DonationNote;

use Give\Donations\ValueObjects\DonationStatus;

use Give\Framework\Exceptions\Primitives\Exception;

use Give\Framework\PaymentGateways\Commands\GatewayCommand;

use Give\Framework\PaymentGateways\Commands\PaymentRefunded;

use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;

use Give\Framework\PaymentGateways\PaymentGateway;

use Give\Framework\PaymentGateways\Commands\PaymentProcessing;



class LyfePayACH extends PaymentGateway

{

    /**

     * @inheritDoc

     */

    public static function id(): string

    {

        return 'lyfepay-ach';
    }



    /**

     * @inheritDoc

     */

    public function getId(): string

    {

        return self::id();
    }



    /**

     * @inheritDoc

     */

    public function getName(): string

    {

        return __('LyfePAY ACH', 'lyfepay-give');
    }



    /**

     * @inheritDoc

     */

    public function getPaymentMethodLabel(): string

    {

        return __('LyfePAY ACH', 'lyfepay-give');
    }



    /**

     * @inheritDoc

     */

    public function getLegacyFormFieldMarkup(int $formId, array $args): string

    {

        return lyfepay_givewp_custom_ach_form($formId);
    }



    public function formSettings(int $formId): array

    {

        return [

            'publishable_key' => lyfepay_give_get_publishable_key()

        ];
    }



    /**

     * @inheritDoc

     */

    public function createPayment(Donation $donation, $gatewayData): GatewayCommand

    {

        try {

            $response = $this->getLyfePayACHPayment([

                'amount'         => $donation->amount->formatToDecimal(),

                'name'           => trim("$donation->firstName $donation->lastName"),

                'email'          => $donation->email,

                'currency'       => $donation->amount->getCurrency()->getCode(),

            ]);





            if (empty($response)) {

                throw new PaymentGatewayException(__('Response not returned!', 'lyfepay-give'));
            }



            if (empty($response['status'])) {

                $message = empty($response['message']) ? 'Payment Not Successful!' : $response['message'];

                throw new PaymentGatewayException(__($message, 'lyfepay-give'));
            }



            // Invoke the webhook handler for successful payment

            // LyfePayWebhookHandler::handle_successful_payment([

            //     'reference_number' => $response['charge_id'],

            //     'amount'           => $donation->amount->formatToDecimal(),

            //     'status'           => 'Paid',

            // ]);



            // Return the PaymentProcessing object

            return new PaymentProcessing($response['charge_id']);
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

     */

    public function refundDonation(Donation $donation): PaymentRefunded

    {

        $apiKey                 = lyfepay_give_get_api_key();

        $apiSecretKey           = lyfepay_give_get_api_secret_key();

        if (give_is_test_mode()) {

            // GiveWP is in test mode

            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {

            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }



        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl . '/charges/' . $donation->gatewayTransactionId,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'X-Api-Key: ' . $apiKey,
                    'X-Api-Secret: ' . $apiSecretKey,
                    'Content-Type: application/json',
                    // 'User-Agent: LyfePayEmulator/1.0'
                ),
            ));

            $checkStatusApi = curl_exec($curl);
            curl_close($curl);
            $checkStatus = json_decode($checkStatusApi, true);

            $refundAmount = $checkStatus['data']['amount'] - $donation->amount->formatToDecimal();
            $body         = json_encode([
                "charge_id" => $checkStatus['data']['transaction_id'],
                'amount'    => $refundAmount,
            ]);
            if ($checkStatus['data']['status'] === 'Paid') {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $apiUrl . '/refunds/',
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
                        // 'User-Agent: LyfePayEmulator/1.0'
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);
                $response_data = json_decode($response, true);
                $donation->status = DonationStatus::REFUNDED();

                $donation->save();

                DonationNote::create([

                    'donationId' => $donation->id,

                    'content' => sprintf(esc_html__('Refund processed successfully. Reason: %s', 'lyfepay-give'), 'refunded by user')

                ]);

                print_r($response_data['message']);

                echo "<script>

                    setTimeout(() => {

                        window.history.go(-1);

                    }, 1000);

                    </script>";

                die();

                return new PaymentRefunded();
            } else if ($checkStatus['data']['status'] === 'Paid Unsettled') {
                $body   = json_encode([
                    "charge_id"      => $checkStatus['data']['transaction_id'],
                    "cancel_reason"  => "canceled by user"
                ]);
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $apiUrl . '/ach/cancel/',
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
                        // 'User-Agent: LyfePayEmulator/1.0'
                    ),
                ));

                $cancelledResponse = curl_exec($curl);
                curl_close($curl);
                $cancelled_data = json_decode($cancelledResponse, true);
                $donation->status   = DonationStatus::CANCELLED();

                $donation->save();

                DonationNote::create([

                    'donationId' => $donation->id,

                    'content'    => sprintf(esc_html__('ACH Payment cancelled successfully. Reason: %s', 'lyfepay-give'), 'canceled by user')

                ]);

                print_r($cancelled_data['message']);

                echo "<script>

                setTimeout(() => {

                    window.history.go(-1);

                }, 1000);

                </script>";

                die();

                return new PaymentRefunded();
            }
        } catch (\Exception $exception) {

            throw new PaymentGatewayException('Unable to refund. ' . $exception->getMessage(), $exception->getCode(), $exception);
        }
    }



    private function getLyfePayACHPayment(array $data): array

    {

        $ach_info               = give_get_donation_lyfepay_ach_info();

        $accountNumber          = $ach_info['account_number'];

        $routingNumber          = $ach_info['routing_number'];

        $accountType            = $ach_info['account_type'];

        $apiKey                 = lyfepay_give_get_api_key();

        $apiSecretKey           = lyfepay_give_get_api_secret_key();



        if (give_is_test_mode()) {

            // GiveWP is in test mode

            $apiUrl = 'https://stage-api.stage-easymerchant.io/api/v1';
        } else {

            // GiveWP is not in test mode

            $apiUrl = 'https://api.easymerchant.io/api/v1';
        }



        $body = json_encode([

            "amount"            => $data['amount'],

            "name"              => $data['name'],

            'email'             => $data['email'],

            "description"       => "ACH Donation From Give",

            "routing_number"    => $routingNumber,

            "account_number"    => $accountNumber,

            "account_type"      => $accountType,

            "entry_class_code"  => "WEB",

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
                // 'User-Agent: LyfePayEmulator/1.0'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response, true);
        return $response;
    }
}
