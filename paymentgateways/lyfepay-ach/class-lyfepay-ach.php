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

            $checkStatusApi = wp_remote_post($apiUrl . '/charges/' . $donation->gatewayTransactionId, array(
                'method'    => 'GET',
                'headers'   => array(
                    'X-Api-Key'     => $apiKey,
                    'X-Api-Secret'  => $apiSecretKey,
                    'Content-Type'  => 'application/json',
                )
            ));
            $checkPaidUnsetteled = wp_remote_retrieve_body($checkStatusApi);
            $checkStatus = json_decode($checkPaidUnsetteled, true);
            $refundAmount = $checkStatus['data']['amount'] - $donation->amount->formatToDecimal();
            if ($checkStatus['data']['status'] === 'Paid') {
                $response = wp_remote_post($apiUrl . '/refunds/', array(
                    'method'    => 'POST',
                    'headers'   => array(
                        'X-Api-Key'      => $apiKey,
                        'X-Api-Secret'   => $apiSecretKey,
                        'Content-Type'   => 'application/json',
                    ),
                    'body'               => json_encode([
                        "charge_id" => $checkStatus['data']['transaction_id'],
                        'amount'    => $refundAmount,
                    ]),
                ));

                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);
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
                $cancelledPayment = wp_remote_post($apiUrl . '/ach/cancel/', array(
                    'method'    => 'POST',
                    'headers'   => array(
                        'X-Api-Key'      => $apiKey,
                        'X-Api-Secret'   => $apiSecretKey,
                        'Content-Type'   => 'application/json',
                    ),
                    'body'               => json_encode([
                        "charge_id"      => $checkStatus['data']['transaction_id'],
                        "cancel_reason"  => "canceled by user"
                    ]),
                ));

                $cancelledResponse  = wp_remote_retrieve_body($cancelledPayment);
                $cancelled_data     = json_decode($cancelledResponse, true);
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
        $response = wp_remote_post($apiUrl . '/ach/charge/', array(
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
