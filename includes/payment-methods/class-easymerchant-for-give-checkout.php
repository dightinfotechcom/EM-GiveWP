<?php
/**
 * Give - Easymerchant Checkout
 *
 * @package    Give
 * @subpackage Easymerchant Core
 * @copyright  Copyright (c) 2019, GiveWP
 * @license    https://opensource.org/licenses/gpl-license GNU Public License
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check for Easymerchant_For_Give existence.
 *
 * @since 2.5.5
 */
if ( ! class_exists( 'Easymerchant_For_Give' ) ) {

	/**
	 * Class Easymerchant_For_Give.
	 *
	 * @since 2.5.5
	 */
	class Easymerchant_For_Give {

		/**
		 * Checkout Session of Stripe.
		 *
		 * @since  2.5.5
		 * @access public
		 *
		 * @var $stripe_checkout_session
		 */
		public $stripe_checkout_session;

		/**
		 * Easymerchant_For_Give constructor.
		 *
		 * @since  2.5.5
		 * @access public
		 */
		public function __construct() {

			$this->id = 'stripe_checkout';

			// Create object for Stripe Checkout Session for usage.
			// $this->stripe_checkout_session = new Easymerchant_For_Give_Session();

			// Remove CC fieldset.
			add_action( 'give_easymerchant_cc_form', [ $this, 'output_redirect_notice' ], 10, 2 );

			// Load the `redirect_to_checkout` function only when `redirect` is set as checkout type.
			add_action( 'give_easymerchant_cc_form', [ $this, 'showCheckoutModal' ], 10, 2 );

		}

		/**
		 * Render redirection notice.
		 *
		 * @param int   $formId Donation Form ID.
		 * @param array $args   List of arguments.
		 *
		 * @return bool
		 * @since 2.7.0
		 */
		public function output_redirect_notice( $formId, $args ) {
			// For Multi-step Sequoia Form Template.
			printf(
				'
					<button class="btn btn-success btn-lg col-xs-6 col-xs-offset-3 " style="margin-top: 20%;" type="button" id="easyBtn">Pay Now</button>
					<div id="easyload"></div>
					<fieldset class="no-fields">
						<div style="display: flex; justify-content: center; margin-top: 20px;">
						<svg width="173" height="73" viewBox="0 0 173 73" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
							<rect width="173" height="72.66" fill="url(#pattern0)"/>
							<defs>
								<pattern id="pattern0" patternContentUnits="objectBoundingBox" width="1" height="1">
									<use xlink:href="#image0" transform="scale(0.00125 0.00297619)"/>
								</pattern>
							</defs>
						</svg>
						</div>
						<p style="text-align: center;"><b>%1$s</b></p>
						<p style="text-align: center;">
							<b>%2$s</b> %3$s
						</p>
					</fieldset>
					',
				esc_html__( 'Make your donations quickly and securely with EasyMerchant', 'give' ),
				esc_html__( 'How it works:', 'give' ),
				esc_html__( 'An Easymerchant window will open after you click the Donate Now button where you can securely make your donation. You will then be brought back to this page to view your receipt.', 'give' )
			);

			remove_action('give_after_cc_fields', 'give_default_cc_address_fields');

            return false;

			// return Stripe::canShowBillingAddress( $formId, $args );
		}

		/**
		 * This function will be used for donation processing.
		 *
		 * @param array $donation_data List of donation data.
		 *
		 * @since  2.5.5
		 * @access public
		 *
		 * @return void
		 */
		public function process_payment( $donation_data ) {
			// Bailout, if the current gateway and the posted gateway mismatched.
			if ( $this->id !== $donation_data['post_data']['give-gateway'] ) {
				return;
			}

			// Make sure we don't have any left over errors present.
			give_clear_errors();

			// Any errors?
			$errors = give_get_errors();

			// No errors, proceed.
			if ( ! $errors ) {

				$form_id          = ! empty( $donation_data['post_data']['give-form-id'] ) ? intval( $donation_data['post_data']['give-form-id'] ) : 0;
				$price_id         = ! empty( $donation_data['post_data']['give-price-id'] ) ? $donation_data['post_data']['give-price-id'] : 0;
				$donor_email      = ! empty( $donation_data['post_data']['give_email'] ) ? $donation_data['post_data']['give_email'] : 0;
				$payment_method   = ! empty( $donation_data['post_data']['give_stripe_payment_method'] ) ? $donation_data['post_data']['give_stripe_payment_method'] : 0;
				$donation_summary = give_payment_gateway_donation_summary( $donation_data, false );

				// Get an existing Stripe customer or create a new Stripe Customer and attach the source to customer.
				$give_stripe_customer = new Give_Stripe_Customer( $donor_email, $payment_method );
				$stripe_customer_id   = $give_stripe_customer->get_id();
				$payment_method       = ! empty( $give_stripe_customer->attached_payment_method ) ?
					$give_stripe_customer->attached_payment_method->id :
					$payment_method;

				// We have a Stripe customer, charge them.
				if ( $stripe_customer_id ) {

					// Setup the payment details.
					$payment_data = [
						'price'           => $donation_data['price'],
						'give_form_title' => $donation_data['post_data']['give-form-title'],
						'give_form_id'    => $form_id,
						'give_price_id'   => $price_id,
						'date'            => $donation_data['date'],
						'user_email'      => $donation_data['user_email'],
						'purchase_key'    => $donation_data['purchase_key'],
						'currency'        => give_get_currency( $form_id ),
						'user_info'       => $donation_data['user_info'],
						'status'          => 'pending',
						'gateway'         => $this->id,
					];

					// Record the pending payment in Give.
					$donation_id = give_insert_payment( $payment_data );

					// Return error, if donation id doesn't exists.
					if ( ! $donation_id ) {
						give_record_gateway_error(
							__( 'Donation creating error', 'give' ),
							sprintf(
								/* translators: %s Donation Data */
								__( 'Unable to create a pending donation. Details: %s', 'give' ),
								wp_json_encode( $donation_data )
							)
						);
						give_set_error( 'stripe_error', __( 'The Stripe Gateway returned an error while creating a pending donation.', 'give' ) );
						give_send_back_to_checkout( '?payment-mode=' . give_clean( $_GET['payment-mode'] ) );
						return;
					}

					// Assign required data to array of donation data for future reference.
					$donation_data['donation_id'] = $donation_id;
					$donation_data['description'] = $donation_summary;
					$donation_data['customer_id'] = $stripe_customer_id;
					$donation_data['source_id']   = $payment_method;

					// Save Stripe Customer ID to Donation note, Donor and Donation for future reference.
					give_insert_payment_note( $donation_id, 'Stripe Customer ID: ' . $stripe_customer_id );
					$this->save_stripe_customer_id( $stripe_customer_id, $donation_id );
					give_update_meta( $donation_id, '_give_stripe_customer_id', $stripe_customer_id );

					if ( 'modal' === easymerchant_for_give_get_checkout_type() ) {
						$this->processModalCheckout( $donation_id, $donation_data );
					} elseif ( 'redirect' === easymerchant_for_give_get_checkout_type() ) {
						$this->process_checkout( $donation_id, $donation_data );
					} else {
						give_record_gateway_error(
							__( 'Invalid Checkout Error', 'give' ),
							sprintf(
								/* translators: %s Donation Data */
								__( 'Invalid Checkout type passed to process the donation. Details: %s', 'give' ),
								wp_json_encode( $donation_data )
							)
						);
						give_set_error( 'stripe_error', __( 'The Stripe Gateway returned an error while processing the donation.', 'give' ) );
						give_send_back_to_checkout( '?payment-mode=' . give_clean( $_GET['payment-mode'] ) );
						return;
					}

					// Don't execute code further.
					give_die();
				}
			}

		}

		/**
		 * Process Donation via Stripe Checkout Modal loaded with Stripe Elements.
		 *
		 * @param int   $donationId   Donation ID.
		 * @param array $donationData Donation Data.
		 *
		 * @since 2.8.0
		 *
		 * @return void
		 */
		public function processModalCheckout( $donationId, $donationData ) {
			$formId = ! empty( $donationData['post_data']['give-form-id'] ) ? intval( $donationData['post_data']['give-form-id'] ) : 0;

			/**
			 * This filter hook is used to update the payment intent arguments.
			 *
			 * @since 2.5.0
			 */
			$intentArgs = apply_filters(
				'give_stripe_create_intent_args',
				[
					'amount'               => $this->format_amount( $donationData['price'] ),
					'currency'             => give_get_currency( $formId ),
					'payment_method_types' => [ 'card' ],
					'statement_descriptor' => give_stripe_get_statement_descriptor(),
					'description'          => give_payment_gateway_donation_summary( $donationData ),
					'metadata'             => $this->prepare_metadata( $donationId, $donationData ),
					'customer'             => $donationData['customer_id'],
					'payment_method'       => $donationData['source_id'],
					'confirm'              => true,
					'return_url'           => give_get_success_page_uri(),
				]
			);

			// Send Stripe Receipt emails when enabled.
			if ( give_is_setting_enabled( give_get_option( 'stripe_receipt_emails' ) ) ) {
				$intentArgs['receipt_email'] = $donationData['user_email'];
			}

			$intent = $this->payment_intent->create( $intentArgs );

			// Save Payment Intent Client Secret to donation note and DB.
			give_insert_payment_note( $donationId, 'Stripe Payment Intent Client Secret: ' . $intent->client_secret );
			give_update_meta( $donationId, '_give_stripe_payment_intent_client_secret', $intent->client_secret );

			// Set Payment Intent ID as transaction ID for the donation.
			give_set_payment_transaction_id( $donationId, $intent->id );
			give_insert_payment_note( $donationId, 'Stripe Charge/Payment Intent ID: ' . $intent->id );

			// Process additional steps for SCA or 3D secure.
			give_stripe_process_additional_authentication( $donationId, $intent );

			if ( ! empty( $intent->status ) && 'succeeded' === $intent->status ) {
				// Process to success page, only if intent is successful.
				give_send_to_success_page();
			} else {
				// Show error message instead of confirmation page.
				give_send_back_to_checkout( '?payment-mode=' . give_clean( $_GET['payment-mode'] ) );
			}
		}

		/**
		 * This function is used to process donations via Stripe Checkout 2.0.
		 *
		 * @param int   $donation_id Donation ID.
		 * @param array $data        List of submitted data for donation processing.
		 *
		 * @since  2.5.5
		 * @access public
		 *
		 * @return void
		 */
		public function process_checkout( $donation_id, $data ) {

			// Define essential variables.
			$form_id          = ! empty( $data['post_data']['give-form-id'] ) ? intval( $data['post_data']['give-form-id'] ) : 0;
			$form_name        = ! empty( $data['post_data']['give-form-title'] ) ? $data['post_data']['give-form-title'] : false;
			$donation_summary = ! empty( $data['description'] ) ? $data['description'] : '';
			$donation_id      = ! empty( $data['donation_id'] ) ? intval( $data['donation_id'] ) : 0;
			$redirect_to_url  = ! empty( $data['post_data']['give-current-url'] ) ? $data['post_data']['give-current-url'] : site_url();

			// Format the donation amount as required by Stripe.
			$amount = give_stripe_format_amount( $data['price'] );

			// Fetch whether the billing address collection is enabled in admin settings or not.
			$is_billing_enabled = give_is_setting_enabled( give_get_option( 'stripe_collect_billing' ) );

			$session_args = [
				'customer'                   => $data['customer_id'],
				'client_reference_id'        => $data['purchase_key'],
				'payment_method_types'       => [ 'card' ],
				'billing_address_collection' => $is_billing_enabled ? 'required' : 'auto',
				'mode'                       => 'payment',
				'line_items'                 => [
					[
						'name'        => $form_name,
						'description' => $data['description'],
						'amount'      => $amount,
						'currency'    => give_get_currency( $form_id ),
						'quantity'    => 1,
					],
				],
				'payment_intent_data'        => [
					'capture_method'       => 'automatic',
					'description'          => $donation_summary,
					'metadata'             => $this->prepare_metadata( $donation_id ),
					'statement_descriptor' => give_stripe_get_statement_descriptor(),
				],
				'submit_type'                => 'donate',
				'success_url'                => give_get_success_page_uri(),
				'cancel_url'                 => give_get_failed_transaction_uri(),
				'locale'                     => give_stripe_get_preferred_locale(),
			];

			// If featured image exists, then add it to checkout session.
			if ( ! empty( get_the_post_thumbnail( $form_id ) ) ) {
				$session_args['line_items'][0]['images'] = [ get_the_post_thumbnail_url( $form_id ) ];
			}

			// Create Checkout Session.
			$session_id = false;
			/*$session    = $this->stripe_checkout_session->create( $session_args );
			$session_id = ! empty( $session->id ) ? $session->id : false;*/

			// Set Checkout Session ID as Transaction ID.
			if ( ! empty( $session_id ) ) {
				give_insert_payment_note( $donation_id, 'Stripe Checkout Session ID: ' . $session_id );
				give_set_payment_transaction_id( $donation_id, $session_id );
			}

			// Save donation summary to donation.
			give_update_meta( $donation_id, '_give_stripe_donation_summary', $donation_summary );

			// Redirect to show loading area to trigger redirectToCheckout client side.
			wp_safe_redirect(
				add_query_arg(
					[
						'action'  => 'checkout_processing',
						'session' => $session_id,
						'id'      => $form_id,
					],
					$redirect_to_url
				)
			);

			// Don't execute code further.
			give_die();
		}

		/**
		 * Stripe Checkout Modal HTML.
		 *
		 * @param int   $formId Donation Form ID.
		 * @param array $args   List of arguments.
		 *
		 * @since  2.8.0
		 * @access public
		 *
		 * @return void
		 */
		public function showCheckoutModal( $formId, $args ) {
			$idPrefix           = ! empty( $args['id_prefix'] ) ? $args['id_prefix'] : "{$formId}-1";
			$backgroundImageUrl = give_get_option( 'stripe_checkout_background_image', '' );
			$backgroundItem     = 'background-color: #000000;';

			// Load Background Image, if exists.
			if ( ! empty( $backgroundImageUrl ) ) {
				$backgroundImageUrl = esc_url( $backgroundImageUrl );
				$backgroundItem     = "background-image: url('{$backgroundImageUrl}'); background-size: cover;";
			}
			?>
			<div id="easymerchant give-stripe-checkout-modal-<?php echo $idPrefix; ?>" class="give-stripe-checkout-modal">
				<div class="give-stripe-checkout-modal-content">
					<div class="give-stripe-checkout-modal-container">
						<div class="give-stripe-checkout-modal-header" style="<?php echo $backgroundItem; ?>">
							<button class="give-stripe-checkout-modal-close">
								<svg
									width="20px"
									height="20px"
									viewBox="0 0 20 20"
									version="1.1"
									xmlns="http://www.w3.org/2000/svg"
									xmlns:xlink="http://www.w3.org/1999/xlink"
								>
									<defs>
										<path
											d="M10,8.8766862 L13.6440403,5.2326459 C13.9542348,4.92245137 14.4571596,4.92245137 14.7673541,5.2326459 C15.0775486,5.54284044 15.0775486,6.04576516 14.7673541,6.3559597 L11.1238333,9.99948051 L14.7673541,13.6430016 C15.0775486,13.9531961 15.0775486,14.4561209 14.7673541,14.7663154 C14.4571596,15.0765099 13.9542348,15.0765099 13.6440403,14.7663154 L10,11.1222751 L6.3559597,14.7663154 C6.04576516,15.0765099 5.54284044,15.0765099 5.2326459,14.7663154 C4.92245137,14.4561209 4.92245137,13.9531961 5.2326459,13.6430016 L8.87616671,9.99948051 L5.2326459,6.3559597 C4.92245137,6.04576516 4.92245137,5.54284044 5.2326459,5.2326459 C5.54284044,4.92245137 6.04576516,4.92245137 6.3559597,5.2326459 L10,8.8766862 Z"
											id="path-1"
										></path>
									</defs>
									<g
										id="Payment-recipes"
										stroke="none"
										stroke-width="1"
										fill="none"
										fill-rule="evenodd"
									>
										<g
											id="Elements-Popup"
											transform="translate(-816.000000, -97.000000)"
										>
											<g id="close-btn" transform="translate(816.000000, 97.000000)">
												<circle
													id="Oval"
													fill-opacity="0.3"
													fill="#AEAEAE"
													cx="10"
													cy="10"
													r="10"
												></circle>
												<mask id="mask-2" fill="white">
													<use xlink:href="#path-1"></use>
												</mask>
												<use
													id="Mask"
													fill-opacity="0.5"
													fill="#FFFFFF"
													opacity="0.5"
													xlink:href="#path-1"
												></use>
											</g>
										</g>
									</g>
								</svg>
							</button>
							<h3><?php echo give_get_option( 'stripe_checkout_name' ); ?></h3>
							<div class="give-stripe-checkout-donation-amount">
								<?php echo give_get_form_price( $formId ); ?>
							</div>
							<div class="give-stripe-checkout-donor-email"></div>
							<div class="give-stripe-checkout-form-title">
								<?php echo get_the_title( $formId ); ?>
							</div>
						</div>
						<div class="give-stripe-checkout-modal-body">
							<?php
							/**
							 * This action hook will be trigger in Stripe Checkout Modal before CC fields.
							 *
							 * @since 2.7.3
							 */
							do_action( 'easymerchant_for_give_modal_before_cc_fields', $formId, $args );

							// Load Credit Card Fields for Stripe Checkout.
							// echo Stripe::showCreditCardFields( $idPrefix );
							// Display the stripe container which can be occupied by Stripe for CC fields.
				            echo sprintf(
				                '<div id="%1$s" class="give-stripe-single-cc-field-wrap"></div>',
				                "give-stripe-single-cc-fields-{$idPrefix}"
				            );

							/**
							 * This action hook will be trigger in Stripe Checkout Modal after CC fields.
							 *
							 * @since 2.7.3
							 */
							do_action( 'easymerchant_for_give_modal_after_cc_fields', $formId, $args );
							?>
							<input type="hidden" name="give_validate_stripe_payment_fields" value="0"/>
						</div>
						<div class="give-stripe-checkout-modal-footer">
							<div class="card-errors"></div>
							<?php
							$display_label_field = give_get_meta( $formId, '_give_checkout_label', true );
							$display_label_field = apply_filters( 'give_donation_form_submit_button_text', $display_label_field, $formId, $args );
							$display_label       = ( ! empty( $display_label_field ) ? $display_label_field : esc_html__( 'Donate Now', 'give' ) );
							ob_start();
							?>
							<div class="give-submit-button-wrap give-stripe-checkout-modal-btn-wrap give-clearfix">
								<?php
								echo sprintf(
									'<input type="submit" class="%1$s" id="%2$s" value="%3$s" data-before-validation-label="%3$s" name="%4$s" data-is_legacy_form="%5$s" disabled/>',
									'give-btn give-stripe-checkout-modal-sequoia-donate-button',
									"give-stripe-checkout-modal-donate-button-{$idPrefix}",
									$display_label,
									'give_stripe_modal_donate',
									false
								);
								?>
								<span class="give-loading-animation"></span>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	}
}

new Easymerchant_For_Give();
