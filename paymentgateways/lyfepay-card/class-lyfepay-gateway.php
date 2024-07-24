<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\Receipts\DonationReceipt;

/**
 * @inheritDoc
 */

class LyfePayGateway extends PaymentGateway
{

    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'lyfepay-gateway';
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
        return __('LyfePAY', 'lyfepay-give');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string
    {
        return __('LyfePAY', 'lyfepay-give');
    }

    public function __invoke(DonationReceipt $receipt): DonationReceipt
    {
        $this->fillDonationDetails($receipt);
        return $receipt;
    }

    /**
     * Display gateway fields for v2 donation forms
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        $output1 = lyfepay_output_redirect_notice($formId, $args);
        // Call the second function
        $output2 = lyfepay_givewp_custom_credit_card_form($formId);
        // Concatenate the results or use any other logic based on your requirements
        $result = $output1 . $output2;

        return $result;
    }

    /**
     * Formsetting Publishable Key
     */
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
            $response = $this->makePaymentRequest([
                'amount'    => $donation->amount->formatToDecimal(),
                'name'      => trim("$donation->firstName $donation->lastName"),
                'email'     => $donation->email,
                'currency'  => $donation->amount->getCurrency()->getCode(),
            ]);

            if (empty($response)) {
                throw new PaymentGatewayException(__('Something went wrong!', 'lyfepay-give'));
            }

            if (empty($response['status'])) {
                $message = empty($response['message']) ? 'Payment not successful!' : $response['message'];
                throw new PaymentGatewayException(__($message, 'lyfepay-give'));
            }

            return new PaymentComplete($response['charge_id']);
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
                    'X-Api-Key: ' . $apiKey . '',
                    'X-Api-Secret: ' . $apiSecretKey . '',
                    'Content-Type: application/json',
                    // 'User-Agent:   LyfePayEmulator/1.0'
                ),
            ));

            $checkStatusApi = curl_exec($curl);
            curl_close($curl);
            $checkStatus = json_decode($checkStatusApi, true);
            $refundAmount = $checkStatus['data']['amount'] - $donation->amount->formatToDecimal();
            $body = json_encode([
                "charge_id" => $donation->gatewayTransactionId,
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
                        'X-Api-Key: ' . $apiKey . '',
                        'X-Api-Secret: ' . $apiSecretKey . '',
                        'Content-Type: application/json',
                        // 'User-Agent:   LyfePayEmulator/1.0'
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
            }
            print_r($response_data['message']);

            echo "<script>
                setTimeout(() => {
                    window.history.go(-1);
                }, 1000);
                </script>";
            die();
            return new PaymentRefunded();
        } catch (\Exception $exception) {
            throw new PaymentGatewayException('Unable to refund. ' . $exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * makePaymentRequest
     */
    private function makePaymentRequest(array $data): array
    {

        $cc_info                  = give_get_donation_lyfepay_cc_info();

        // Use the card details in this function calling from "give_get_donation_lyfepay_cc_info" function.
        $cc_holder              = $cc_info['card_name'];
        $cc_number              = $cc_info['card_number_easy'];
        $month                  = $cc_info['card_exp_month'];
        $year                   = $cc_info['card_exp_year'];
        $cc_cvc                 = $cc_info['card_cvc'];
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
            'payment_mode'   => 'auth_and_capture',
            'amount'         => $data['amount'],
            'name'           => $data['name'],
            'email'          => $data['email'],
            'description'    => 'GiveWp Donation',
            'currency'       => $data['currency'],
            'card_number'    => $cc_number,
            'exp_month'      => $month,
            'exp_year'       => $year,
            'cvc'            => $cc_cvc,
            'cardholder_name' => $cc_holder,
        ]);

        try {

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl . '/charges/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{
                    "payment_mode": "auth_and_capture",
                    "card_number": "' . $cc_number . '",
                    "exp_month": "' . $month . '",
                    "exp_year": "' . $year . '",
                    "cvc": "' . $cc_cvc . '",
                    "currency": "' . $data['currency'] . '",
                    "cardholder_name": "' . $cc_holder . '",
                    "name": "' . $data['name'] . '",
                    "email": "' . $data['email'] . '",
                    "amount": "' . $data['amount'] . '",
                    "description": "GiveWp Donation"
                }',
                CURLOPT_HTTPHEADER => array(
                    'X-Api-Key: ' . $apiKey . '',
                    'X-Api-Secret: ' . $apiSecretKey . '',
                    'Content-Type: application/json',
                    // 'User-Agent: LyfePayEmulator/1.0',
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            // Decode JSON response to PHP array
            $response = json_decode($response, true);

            // echo $response;
            return $response;
        } catch (Exception $e) {
            // Improved exception handling
            echo 'Curl error: ' . $e->getMessage() . "\n";
            echo 'Curl error number: ' . $e->getCode() . "\n";
        }
    }
}
