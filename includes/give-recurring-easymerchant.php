<?php

use Give\ValueObjects\Money;
use GiveRecurring\Infrastructure\Log;
use GiveRecurring\PaymentGateways\DataTransferObjects\SubscriptionDto;
use GiveRecurring\PaymentGateways\Easy\Actions\RetrieveOrCreatePlan;
use GiveRecurring\PaymentGateways\Easy\Actions\UpdateSubscriptionAmount;
use Easy\Subscription;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Give_Recurring_Easy
 */
class Give_Recurring_Easy extends Give_Recurring_Gateway {
	/**
	 * Call Give easy Invoice Class for processing recurring donations.
	 *
	 * @var Give_Easy_Invoice
	 */
	public $invoice;

	/**
	 * Call Give easy Payment Intent Class for processing recurring donations.
	 *
	 * @var Give_Easy_Payment_Intent
	 */
	public $payment_intent;

	/**
	 * @var Give_Easy_Gateway
	 */
	private $easy_gateway;

	/**
	 * @var Give_Easy_Customer
	 */
	private $easy_customer;

	/**
	 * Get easy Started.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function init() {

		$this->id = 'easy';

		if (
			defined( 'GIVE_EASY_VERSION' ) &&
			version_compare( GIVE_EASY_VERSION, '2.2.0', '<' )
		) {
			add_action( 'admin_notices', array( $this, 'old_api_upgrade_notice' ) );

			// No easy SDK. Bounce.
			return false;
		}

		add_action( 'template_redirect', array( $this, 'listen_sca_payments' ) );

		// Bailout, if gateway is not active.
		if ( ! give_is_gateway_active( $this->id ) ) {
			return;
		}

		$this->easy_gateway = new Give_Easy_Gateway();
		$this->invoice        = new Give_Easy_Invoice();

		add_action( 'give_pre_refunded_payment', array( $this, 'process_refund' ) );
		add_action( 'give_recurring_cancel_easy_subscription', array( $this, 'cancel' ), 10, 2 );
	}

	/**
	 * Subscribes a easy Customer to a plan.
	 *
	 * @param  \easy\Customer      $easy_customer easy Customer Object.
	 * @param  string|\easy\Source $source          easy Source ID/Object.
	 * @param  string                $plan_id         easy Plan ID.
	 *
	 * @return bool|Subscription
	 */
	public function subscribe_customer_to_plan( $easy_customer, $source, $plan_id ) {
		if ( $easy_customer instanceof \easy\Customer ) {
			try {
				// Get metadata.
				$metadata = give_easy_prepare_metadata( $this->payment_id, $this->purchase_data );
				$args     = array(
					'customer' => $easy_customer->id,
					'items'    => array(
						array(
							'plan' => $plan_id,
						),
					),
					'metadata' => $metadata,
				);

				$args['default_payment_method'] = $source->id;

				$subscription                      = Subscription::create( $args, give_easy_get_connected_account_options() );
				$this->subscriptions['profile_id'] = $subscription->id;

				// Need additional authentication steps as subscription is still incomplete.
				if ( 'incomplete' ===  $subscription->status ) {

					// Verify the initial payment with invoice created during subscription.
					$invoice = $this->invoice->retrieve( $subscription->latest_invoice );

					// Set Payment Intent ID.
					give_insert_payment_note( $this->payment_id, 'easy Payment Intent ID: ' . $invoice->payment_intent );

					// Retrieve payment intent details.
					$intent_details = $this->payment_intent->retrieve( $invoice->payment_intent );

					$confirm_args = array(
						'return_url' => give_get_success_page_uri(),
					);

					if (
						give_easy_is_source_type( $source->id, 'tok' ) ||
						give_easy_is_source_type( $source->id, 'src' )
					) {
						$confirm_args['source'] = $source->id;
					} elseif ( give_easy_is_source_type( $source->id, 'pm' ) ) {
						$confirm_args['payment_method'] = $source->id;
					}

					$intent_details->confirm( $confirm_args );

					// Record the subscription in Give.
					$this->record_signup();

					// Process additional authentication steps for SCA or 3D secure.
					give_easy_process_additional_authentication( $this->payment_id, $intent_details );
				}

				return $subscription;
			} catch ( \easy\Error\Base $e ) {

				// There was an issue subscribing the easy customer to a plan.
				Give_easy_Logger::log_error( $e, $this->id );
			} catch ( Exception $e ) {

				// Something went wrong outside of easy.
				give_record_gateway_error(
					__( 'easy Error', 'give-recurring' ),
					sprintf(
						/* translators: %s Exception Message. */
						__( 'An error while subscribing a customer to a plan. Details: %s', 'give-recurring' ),
						$e->getMessage()
					)
				);
				give_set_error( 'easy Error', __( 'An error occurred while processing the donation. Please try again.', 'give-recurring' ) );
				give_send_back_to_checkout( '?payment-mode=easy' );
			} // End try().
		} // End if().
		return false;
	}

	/**
	 * Refund subscription charges and cancels the subscription if the parent donation triggered when refunding in wp-admin donation details.
	 *
	 * @access      public
	 * @since       1.1
	 *
	 * @param $payment Give_Payment
	 *
	 * @return      void
	 */
	public function process_refund( $payment ) {

		if ( empty( $_POST['give_refund_in_easy'] ) ) {
			return;
		}
		$statuses = array( 'give_subscription', 'publish' );

		if ( ! in_array( $payment->old_status, $statuses ) ) {
			return;
		}

		if ( 'easy' !== $payment->gateway ) {
			return;
		}

		switch ( $payment->old_status ) {

			case 'give_subscription' :

				// Refund renewal payment
				if ( empty( $payment->transaction_id ) || $payment->transaction_id == $payment->ID ) {

					// No valid charge ID
					return;
				}

				try {

					$refund = \easy\Refund::create( array(
						'charge' => $payment->transaction_id,
					) );

					$payment->add_note( sprintf( __( 'Charge %1$s refunded in easy. Refund ID: %1$s', 'give-recurring' ), $payment->transaction_id, $refund->id ) );

				} catch ( Exception $e ) {

					// some sort of other error
					$body = $e->getJsonBody();
					$err  = $body['error'];

					if ( isset( $err['message'] ) ) {
						$error = $err['message'];
					} else {
						$error = __( 'Something went wrong while refunding the charge in easy.', 'give-recurring' );
					}

					wp_die( $error, __( 'Error', 'give-recurring' ), array(
						'response' => 400,
					) );

				}

				break;

			case 'publish' :

				// Refund & cancel initial subscription donation.
				$db   = new Give_Subscriptions_DB();
				$subs = $db->get_subscriptions( array(
					'parent_payment_id' => $payment->ID,
					'number'            => 100,
				) );

				if ( empty( $subs ) ) {
					return;
				}

				foreach ( $subs as $subscription ) {

					try {

						$refund = \easy\Refund::create( array(
							'charge' => $subscription->transaction_id,
						) );

						$payment->add_note( sprintf( __( 'Charge %s refunded in easy.', 'give-recurring' ), $subscription->transaction_id ) );
						$payment->add_note( sprintf( __( 'Charge %1$s refunded in easy. Refund ID: %1$s', 'give-recurring' ), $subscription->transaction_id, $refund->id ) );

					} catch ( Exception $e ) {

						// some sort of other error
						$body = $e->getJsonBody();
						$err  = $body['error'];

						if ( isset( $err['message'] ) ) {
							$error = $err['message'];
						} else {
							$error = __( 'Something went wrong while refunding the charge in easy.', 'give-recurring' );
						}

						$payment->add_note( sprintf( __( 'Charge %1$s could not be refunded in easy. Error: %1$s', 'give-recurring' ), $subscription->transaction_id, $error ) );

					}

					// Cancel subscription.
					$this->cancel( $subscription, false );
					$subscription->cancel();
					$payment->add_note( sprintf( __( 'Subscription %d cancelled.', 'give-recurring' ), $subscription->id ) );

				}

				break;

		}// End switch().

	}


	/**
	 * Initial field validation before ever creating profiles or donors.
	 *
	 * Note: Please don't use this function. This function is for internal purposes only and can be removed
	 * anytime without notice.
	 *
	 * @access      public
	 * @since       1.0
	 *
	 * @param array $valid_data List of valid data.
	 * @param array $post_data  List of posted variables.
	 *
	 * @return      void
	 */
	public function validate_fields( $valid_data, $post_data ) {

		if (
			isset( $post_data['card_name'] ) &&
			empty( $post_data['card_name'] ) &&
			! isset( $post_data['is_payment_request'] )
		) {
			give_set_error( 'no_card_name', __( 'Please enter a name for the credit card.', 'give-recurring' ) );
		}

	}

	/**
	 * Can update subscription CC details.
	 *
	 * @since 1.7
	 *
	 * @param bool   $ret
	 * @param object $subscription
	 *
	 * @return bool
	 */
	public function can_update( $ret, $subscription ) {

		if (
			'easy' === $subscription->gateway
			&& ! empty( $subscription->profile_id )
			&& in_array( $subscription->status, array(
				'active',
				'failing',
			), true )
		) {
			return true;
		}

		return $ret;
	}

	/**
	 * @since 1.12.6
	 *
	 * @param bool $ret
	 * @param Give_Subscription $subscription
	 *
	 * @return bool
	 */
	public function can_update_subscription( $ret, $subscription ) {
		return $this->can_update( $ret, $subscription );
	}

	/**
	 * easy Recurring Customer ID.
	 *
	 * The Give easy gateway stores it's own customer_id so this method first checks for that, if it exists.
	 * If it does it will return that value. If it does not it will return the recurring gateway value.
	 *
	 * @param string $user_email Donor Email.
	 *
	 * @return string The donor's easy customer ID.
	 */
	public function get_easy_recurring_customer_id( $user_email ) {

		// First check user meta to see if they have made a previous donation
		// w/ easy via non-recurring donation so we don't create a duplicate easy customer for recurring.
		$customer_id = give_easy_get_customer_id( $user_email );

		// If no data found check the subscribers profile to see if there's a recurring ID already.
		if ( empty( $customer_id ) ) {

			$subscriber = new Give_Recurring_Subscriber( $user_email );

			$customer_id = $subscriber->get_recurring_donor_id( $this->id );
		}

		return $customer_id;

	}

	/**
	 * Get easy Subscription.
	 *
	 * @param $easy_subscription_id
	 *
	 * @return mixed
	 */
	public function get_easy_subscription( $easy_subscription_id ) {

		$easy_subscription = Subscription::retrieve( $easy_subscription_id );

		return $easy_subscription;

	}

	/**
	 * Get gateway subscription.
	 *
	 * @param $subscription
	 *
	 * @return bool|mixed
	 */
	public function get_gateway_subscription( $subscription ) {

		if ( $subscription instanceof Give_Subscription ) {

			$easy_subscription_id = $subscription->profile_id;

			$easy_subscription = $this->get_easy_subscription( $easy_subscription_id );

			return $easy_subscription;
		}

		return false;
	}

	/**
	 * Get subscription details.
	 *
	 * @param Give_Subscription $subscription
	 *
	 * @return array|bool
	 */
	public function get_subscription_details( $subscription ) {

		$easy_subscription = $this->get_gateway_subscription( $subscription );
		if ( false !== $easy_subscription ) {

			$subscription_details = array(
				'status'         => $easy_subscription->status,
				'created'        => $easy_subscription->created,
				'billing_period' => $easy_subscription->plan->interval,
				'frequency'      => $easy_subscription->plan->interval_count,
			);

			return $subscription_details;
		}

		return false;
	}

	/**
	 * Get transactions.
	 *
	 * @param  Give_Subscription $subscription
	 * @param string             $date
	 *
	 * @return array
	 */
	public function get_gateway_transactions( $subscription, $date = '' ) {

		$subscription_invoices = $this->get_invoices_for_give_subscription( $subscription, $date = '' );
		$transactions          = array();

		foreach ( $subscription_invoices as $invoice ) {

			$transactions[] = array(
				'amount'         => give_easy_cents_to_dollars( $invoice->amount_due ),
				'date'           => $invoice->created,
				'transaction_id' => $invoice->charge,
			);
		}

		return $transactions;
	}

	/**
	 * Get invoices for a Give subscription.
	 *
	 * @param Give_Subscription $subscription
	 * @param string            $date
	 *
	 * @return array
	 */
	private function get_invoices_for_give_subscription( $subscription, $date = '' ) {
		$subscription_invoices = array();

		if ( $subscription instanceof Give_Subscription ) {

			$easy_subscription_id = $subscription->profile_id;

			/**
			 * Customer ID is also saved in the give_donationmeta table when a donation is made with easy PG.
			 * We have to check if the customer ID is in the give_donationmeta table because if multiple easy accounts are connected,
			 * the same donor will have a different customer ID for each connected account.
			 */
			$easy_customer_id = Give()->payment_meta->get_meta( $subscription->parent_payment_id, '_give_easy_customer_id', true );

			if ( ! $easy_customer_id ) {
				$easy_customer_id = $this->get_easy_recurring_customer_id( $subscription->donor->email );
			}

			$subscription_invoices  = $this->get_invoices_for_subscription( $easy_customer_id, $easy_subscription_id, $date );
		}

		return $subscription_invoices;
	}

	/**
	 * Get invoices for subscription.
	 *
	 * @param $easy_customer_id
	 * @param $easy_subscription_id
	 * @param $date
	 *
	 * @return array
	 */
	public function get_invoices_for_subscription( $easy_customer_id, $easy_subscription_id, $date ) {
		$subscription_invoices = array();
		$invoices              = $this->get_invoices_for_customer( $easy_customer_id, $date );

		foreach ( $invoices as $invoice ) {
			if ( $invoice->subscription == $easy_subscription_id ) {
				$subscription_invoices[] = $invoice;
			}
		}

		return $subscription_invoices;
	}

	/**
	 * Get invoices for easy customer.
	 *
	 * @param string $easy_customer_id
	 * @param string $date
	 *
	 * @return array|bool
	 */
	private function get_invoices_for_customer( $easy_customer_id = '', $date = '' ) {
		$args     = array(
			'limit' => 100,
			'status' => 'paid'
		);
		$has_more = true;
		$invoices = array();

		if ( ! empty( $date ) ) {
			$date_timestamp = strtotime( $date );
			$args['date']   = array(
				'gte' => $date_timestamp,
			);
		}

		if ( ! empty( $easy_customer_id ) ) {
			$args['customer'] = $easy_customer_id;
		}

		while ( $has_more ) {
			try {
				$collection             = \easy\Invoice::all( $args );
				$invoices               = array_merge( $invoices, $collection->data );
				$has_more               = $collection->has_more;
				$last_obj               = end( $invoices );
				$args['starting_after'] = $last_obj->id;

			} catch ( \easy\Error\Base $e ) {

				Give_easy_Logger::log_error( $e, $this->id );

				return false;

			} catch ( Exception $e ) {

				// Something went wrong outside of easy.
				give_record_gateway_error( __( 'easy Error', 'give-recurring' ), sprintf( __( 'The easy Gateway returned an error while getting invoices a easy customer. Details: %s', 'give-recurring' ), $e->getMessage() ) );

				return false;

			}
		}

		return $invoices;
	}

	/**
	 * Outputs the payment method update form
	 *
	 * @since  1.7
	 *
	 * @param  Give_Subscription $subscription The subscription object
	 *
	 * @return void
	 */
	public function update_payment_method_form( $subscription ) {

		if ( $subscription->gateway !== $this->id ) {
			return;
		}

		// addCreditCardForm() only shows when easy Checkout is enabled so we fake it
		add_filter( 'give_get_option_easy_checkout', '__return_false' );

		// Remove Billing address fields.
		if ( has_action( 'give_after_cc_fields', 'give_default_cc_address_fields' ) ) {
			remove_action( 'give_after_cc_fields', 'give_default_cc_address_fields', 10 );
		}

		$form_id           = ! empty( $subscription->form_id ) ? absint( $subscription->form_id ) : 0;
		$args['id_prefix'] = "$form_id-1";
		$easyCard = new Give_easy_Card();
		$easyCard->addCreditCardForm( $form_id, $args );
	}

	/**
	 * @inheritdoc
	 */
	public function update_payment_method( $subscriber, $subscription, $data = null ) {

		if ( $data === null ) {
			$post_data = give_clean( $_POST );
		} else {
			$post_data = $data;
		}

		// Check for any existing errors.
		$errors    = give_get_errors();
		$form_id   = ! empty( $subscription->form_id ) ? $subscription->form_id : false;

		// Set App info.
		give_easy_set_app_info( $form_id );

		if ( empty( $errors ) ) {

			$source_id   = ! empty( $post_data['give_easy_payment_method'] ) ? $post_data['give_easy_payment_method'] : 0;
			$customer_id = Give()->donor_meta->get_meta( $subscriber->id, give_easy_get_customer_key(), true );

			// We were unable to retrieve the customer ID from meta so let's pull it from the API
			try {

				$easy_subscription = Subscription::retrieve( $subscription->profile_id );

			} catch ( Exception $e ) {

				give_set_error( 'give_recurring_easy_error', $e->getMessage() );
				return;
			}

			// If customer id doesn't exist, take the customer id from subscription.
			if ( empty( $customer_id ) ) {
				$customer_id = $easy_subscription->customer;
			}

			try {

				$easy_customer = \easy\Customer::retrieve( $customer_id );
			} catch ( Exception $e ) {

				give_set_error( 'give-recurring-easy-customer-retrieval-error', $e->getMessage() );
				return;
			}

			// No errors in easy, continue on through processing
			try {

				// Fetch payment method details.
				$easy_payment_method = new Give_easy_Payment_Method();

				if ( $source_id ) {
					if ( give_easy_is_source_type( $source_id, 'pm' ) ) {

						$payment_method = $easy_payment_method->retrieve( $source_id );

						// Set Card ID as default payment method to customer and subscription.
						$payment_method->attach( array(
							'customer' => $easy_customer->id,
						) );

						// Set default payment method for subscription.
						Subscription::update(
							$subscription->profile_id,
							array(
								'default_payment_method' => $source_id,
							)
						);
					} else {
						$card = $easy_customer->sources->create( array( 'source' => $source_id ) );
						$easy_customer->default_source = $card->id;

						// Set default source for subscription.
						Subscription::update(
							$subscription->profile_id,
							array(
								'default_source' => $source_id,
							)
						);
					}

				} elseif ( ! empty( $post_data['give_easy_existing_card'] ) ) {
					if ( give_easy_is_source_type( $post_data['give_easy_existing_card'], 'pm' ) ) {

						$payment_method = $easy_payment_method->retrieve( $post_data['give_easy_existing_card'] );
						$payment_method->attach( array(
							'customer' => $easy_customer->id,
						) );

						// Set default payment method for subscription.
						Subscription::update(
							$subscription->profile_id,
							array(
								'default_payment_method' => $post_data['give_easy_existing_card'],
							)
						);
					} else {
						$easy_customer->default_source     = $post_data['give_easy_existing_card'];

						// Set default source for subscription.
						Subscription::update(
							$subscription->profile_id,
							array(
								'default_source' => $post_data['give_easy_existing_card'],
							)
						);
					}
				}

				// Save the updated subscription details.
				$easy_subscription->save();

				// Save the updated customer details.
				$easy_customer->save();

			} catch ( \easy\Error\Card $e ) {

				$body = $e->getJsonBody();
				$err  = $body['error'];

				if ( isset( $err['message'] ) ) {
					give_set_error( 'payment_error', $err['message'] );
				} else {
					give_set_error( 'payment_error', __( 'There was an error processing your payment, please ensure you have entered your card number correctly.', 'give-recurring' ) );
				}

			} catch ( \easy\Error\ApiConnection $e ) {

				$body = $e->getJsonBody();
				$err  = $body['error'];

				if ( isset( $err['message'] ) ) {
					give_set_error( 'payment_error', $err['message'] );
				} else {
					give_set_error( 'payment_error', __( 'There was an error processing your payment (easy\'s API is down), please try again', 'give-recurring' ) );
				}

			} catch ( \easy\Error\InvalidRequest $e ) {

				$body = $e->getJsonBody();
				$err  = $body['error'];

				// Bad Request of some sort. Maybe Christoff was here ;)
				if ( isset( $err['message'] ) ) {
					give_set_error( 'request_error', $err['message'] );
				} else {
					give_set_error( 'request_error', __( 'The easy API request was invalid, please try again', 'give-recurring' ) );
				}

			} catch ( \easy\Error\Api $e ) {

				$body = $e->getJsonBody();
				$err  = $body['error'];

				if ( isset( $err['message'] ) ) {
					give_set_error( 'request_error', $err['message'] );
				} else {
					give_set_error( 'request_error', __( 'The easy API request was invalid, please try again', 'give-recurring' ) );
				}

			} catch ( \easy\Error\Authentication $e ) {

				$body = $e->getJsonBody();
				$err  = $body['error'];

				// Authentication error. easy keys in settings are bad.
				if ( isset( $err['message'] ) ) {
					give_set_error( 'request_error', $err['message'] );
				} else {
					give_set_error( 'api_error', __( 'The API keys entered in settings are incorrect', 'give-recurring' ) );
				}

			} catch ( Exception $e ) {
				give_set_error( 'update_error', __( 'There was an error with this payment method. Please try with another card.', 'give-recurring' ) );
			}

		}

	}

	/**
	 * @inheritdoc
	 *
	 * @since 1.12.6 implement updateSubscriptionAmountOneasy function
	 */
	public function update_subscription( $subscriber, $subscription, $data = null ) {
		if ( $data === null ) {
			$data = give_clean( $_POST ); // WPCS: input var ok, sanitization ok, CSRF ok.
		}
		$renewalAmount = $this->getNewRenewalAmount( $data );

		if ( give_get_errors() ) {
			return;
		}

		try{
			give( UpdateSubscriptionAmount::class )->handle( $subscription, $renewalAmount  );
		} catch ( Exception $e ) {
			give_set_error(
				'give_recurring_easy_update_subscription',
				esc_html__(
					'The easy gateway returned an error while updating the subscription.',
					'give-recurring'
				)
			);

			Log::error(
				'easy Subscription Update Error',
				[
					'Description' => $e->getMessage(),
					'Subscription Data' => $subscription,
					'Renewal Amount' => $renewalAmount,
					'Subscriber' => $subscriber
				]
			);
		}
	}

	/**
	 * Can Cancel.
	 *
	 * @param bool $canCancel The value being filtered.
	 * @param $subscription
	 *
	 * @access public
	 *
	 * @since  1.9.0
	 * @since 1.12.2 Return the original filtered value if no change so that failing subscriptions can be canceled.
	 *
	 * @return bool
	 */
	public function can_cancel( $canCancel, $subscription ) {
		if( $subscription->gateway === $this->id ) {
			$canCancel = give_recurring_easy_can_cancel( $canCancel, $subscription );
		}
		return $canCancel;
	}

	/**
	 * Can Sync.
	 *
	 * @param $ret
	 * @param $subscription
	 *
	 * @since  1.9.1
	 * @access public
	 *
	 * @return bool
	 */
	public function can_sync( $ret, $subscription ) {

		if (
			$subscription->gateway === $this->id
			&& ! empty( $subscription->profile_id )
		) {
			$ret = true;
		}

		return $ret;
	}

	/**
	 * Cancels a easy Subscription.
	 *
	 * @param  Give_Subscription $subscription
	 * @param  bool              $valid
	 *
	 * @since  1.9.1
	 * @access public
	 *
	 * @return bool
	 */
	public function cancel( $subscription, $valid ) {

		if ( empty( $valid ) ) {
			return false;
		}

		try {

			// Get the easy customer ID.
			$easy_customer_id = $this->get_easy_recurring_customer_id( $subscription->donor->email );

			// Must have a easy customer ID.
			if ( ! empty( $easy_customer_id ) ) {

				$subscription = Subscription::retrieve( $subscription->profile_id );
				$subscription->cancel();

				return true;
			}

			return false;

		} catch ( \easy\Error\Base $e ) {

			// There was an issue cancelling the subscription w/ easy :(
			give_record_gateway_error( __( 'easy Error', 'give-recurring' ), sprintf( __( 'The easy Gateway returned an error while cancelling a subscription. Details: %s', 'give-recurring' ), $e->getMessage() ) );
			give_set_error( 'easy Error', __( 'An error occurred while cancelling the donation. Please try again.', 'give-recurring' ) );

			return false;

		} catch ( Exception $e ) {

			// Something went wrong outside of easy.
			give_record_gateway_error( __( 'easy Error', 'give-recurring' ), sprintf( __( 'The easy Gateway returned an error while cancelling a subscription. Details: %s', 'give-recurring' ), $e->getMessage() ) );
			give_set_error( 'easy Error', __( 'An error occurred while cancelling the donation. Please try again.', 'give-recurring' ) );

			return false;

		}

	}

	/**
	 * Validate easy payment method.
	 *
	 * @since 1.11.1
	 *
	 * @param string|bool $payment_method_id
	 */
	private function validateeasyPaymentMethod( $payment_method_id ){
		// Send donor back to checkout page, if no payment method id exists.
		if ( ! empty( $payment_method_id ) ) {
			return;
		}

		give_record_gateway_error(
			esc_html__( 'easy Payment Method Error', 'give-recurring' ),
			esc_html__( 'The payment method failed to generate during a recurring donation. This is usually caused by a JavaScript error on the page preventing easy’s JavaScript from running correctly. Reach out to GiveWP support for assistance.', 'give-recurring' )
		);
		give_set_error( 'no-payment-method-id', __( 'Unable to generate Payment Method ID. Please contact a site administrator for assistance.', 'give-recurring' ) );
		give_send_back_to_checkout( '?payment-mode=' . give_clean( $_GET['payment-mode'] ) );
	}
}

new Give_Recurring_Easy();
