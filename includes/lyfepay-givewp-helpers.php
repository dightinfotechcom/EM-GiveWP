<?php
function lyfepay_gateway_heading()
{
    return give_get_option('lyfepay_gateway_heading');
}

function lyfepay_gateway_description()
{
    return give_get_option('lyfepay_gateway_description');
}

function gateway_enable_disable_billing_address()
{
    return give_get_option('gateway_collect_billing');
}

function lyfepay_output_redirect_notice($formId, $args)
{
    return sprintf(

        '
      <fieldset class="no-fields">

        <p style="text-align: center;"><b>' . lyfepay_gateway_heading() . '</b></p>

        <p style="text-align: center;">

         ' . lyfepay_gateway_description() . '

        </p>

      </fieldset>

		',

        esc_html__('Make your donations quickly and securely with LyfePAY', 'give'),

        esc_html__('How it works:', 'give'),

        esc_html__(
            'An LyfePAY window will open after you click the Donate Now button where you can securely make your donation. You will then be brought back to this page to view your receipt.',
            'give'
        )

    );
}

function lyfepay_givewp_custom_credit_card_form($form_id)
{
    ob_start();

    /**
     * Fires while rendering credit card info form, before the fields.
     *
     * @param  int  $form_id  The form ID.
     *
     * @since 1.0
     */
    do_action('give_before_cc_fields', $form_id);
    $billing_fields_enabled = gateway_enable_disable_billing_address();
    if ($billing_fields_enabled) {
        do_action('give_after_cc_fields', $form_id);
    }
?>
    <fieldset id="give_cc_fields-<?php echo $form_id; ?>" class="give-do-validate">
        <legend>
            <?php echo apply_filters('give_credit_card_fieldset_heading', esc_html__('Credit Card Info', 'give')); ?>
        </legend>
        <?php
        if (is_ssl()) : ?>
            <div id="give_secure_site_wrapper-<?php echo $form_id; ?>">
                <span class="give-icon padlock"></span>
                <span><?php _e('This is a secure SSL encrypted payment.', 'give'); ?></span>
            </div>
        <?php
        endif; ?>
        <p id="give-card-number-wrap-<?php echo $form_id; ?>" class="form-row form-row-two-thirds form-row-responsive">
            <label for="card_number-<?php echo $form_id; ?>" class="give-label">
                <?php _e('Card Number', 'give'); ?>
                <span class="give-required-indicator">*</span>
                <?php
                echo Give()->tooltips->render_help(
                    __('The (typically) 16 digits on the front of your credit card.', 'give')
                );
                ?>
                <span class="card-type"></span>
            </label>
            <input type="hidden" name="card_number_easy" value="" />
            <input type="tel" autocomplete="off" name="card_number" id="card_number-<?php echo $form_id; ?>" class="card-number give-input required" placeholder="<?php _e('Card Number', 'give'); ?>" required aria-required="true" onkeyup="card_number_hidden()" />
        </p>

        <p id="give-card-cvc-wrap-<?php echo $form_id; ?>" class="form-row form-row-one-third form-row-responsive">
            <label for="card_cvc-<?php echo $form_id; ?>" class="give-label">
                <?php _e('CVC', 'give'); ?>
                <span class="give-required-indicator">*</span>
                <?php
                echo Give()->tooltips->render_help(
                    __('The 3 digit (back) or 4 digit (front) value on your card.', 'give')
                );
                ?>
            </label>

            <input type="tel" size="4" min="3" autocomplete="off" name="card_cvc" id="card_cvc-<?php echo $form_id; ?>" class="card-cvc give-input required" placeholder="<?php _e('CVC', 'give'); ?>" required aria-required="true" />
            <small class="red-error-cvv" style="color:red;display:none;">CVV must be 3 or 4 digit. Please check your card</small>
        </p>

        <p id="give-card-name-wrap-<?php echo $form_id; ?>" class="form-row form-row-two-thirds form-row-responsive">
            <label for="card_name-<?php echo $form_id; ?>" class="give-label">
                <?php _e('Cardholder Name', 'give'); ?>
                <span class="give-required-indicator">*</span>
                <?php
                echo Give()->tooltips->render_help(__('The name of the credit card account holder.', 'give'));
                ?>
            </label>

            <input type="text" autocomplete="off" name="card_name" id="card_name-<?php echo $form_id; ?>" class="card-name give-input required" placeholder="<?php esc_attr_e('Cardholder Name', 'give'); ?>" required aria-required="true" />
        </p>
        <?php
        /**
         * Fires while rendering credit card info form, before expiration fields.
         *
         * @param  int  $form_id  The form ID.
         *
         * @since 1.0
         */
        do_action('give_before_cc_expiration');
        ?>
        <p class="card-expiration form-row form-row-one-third form-row-responsive">
            <label for="card_expiry-<?php echo $form_id; ?>" class="give-label">
                <?php
                _e('Expiration', 'give'); ?>
                <span class="give-required-indicator">*</span>
                <?php
                echo Give()->tooltips->render_help(
                    __('The date your credit card expires, typically on the front of the card.', 'give')
                );
                ?>
            </label>

            <input type="hidden" id="card_exp_month_0-<?php echo $form_id; ?>" name="card_exp_month_0" class="card-expiry-month-0" />
            <input type="hidden" id="card_exp_year-<?php echo $form_id; ?>" name="card_exp_year" class="card-expiry-year" />

            <input type="tel" autocomplete="off" name="card_expiry" id="card_expiry-<?php echo $form_id; ?>" class="card-expiry give-input required" placeholder="<?php esc_attr_e('MM / YYYY', 'give'); ?>" required aria-required="true" />
            <small class="red-error" style="color:red;display:none;">Your card is expired. Please use other card </small>
        </p>
        <?php
        /**
         * Fires while rendering credit card info form, after expiration fields.
         *
         * @param int $form_id The form ID.
         *
         * @since 1.0
         */
        do_action('give_after_cc_expiration', $form_id);
        ?>
    </fieldset>
    <script>
        function card_number_hidden() {
            let ccNumber = document.querySelector('.card-number').value;
            document.querySelector('input[name=\'card_number_easy\']').value = ccNumber;
        }
        document.addEventListener("DOMContentLoaded", function() {
            var expiryInput = document.getElementById('card_expiry-<?php echo $form_id; ?>');

            function checkExpiry() {
                var inputValue = expiryInput.value;
                var currentDate = new Date();
                var enteredMonth = inputValue.split(' / ')[0];
                var enteredYear = parseInt(inputValue.split(' / ')[1]);
                document.querySelector('input[name=\'card_exp_month_0\']').value = enteredMonth;

                // Validate the entered month and year
                if (enteredMonth >= 1 && enteredMonth <= 12 && enteredYear >= currentDate.getFullYear()) {
                    // If the entered year is the current year, check if the entered month is in the future
                    if (enteredYear == currentDate.getFullYear() && enteredMonth < (currentDate.getMonth() + 1)) {
                        document.querySelector('.red-error').style.display = 'block';
                    } else {
                        document.querySelector('.red-error').style.display = 'none';
                    }
                } else {
                    // Invalid month or year format
                    document.querySelector('.red-error').style.display = 'block';
                }
            }

            // Check expiry on input (keyup) event
            expiryInput.addEventListener('input', checkExpiry);

            // Check expiry on change event (includes autofill)
            expiryInput.addEventListener('change', checkExpiry);

            var cardCvv = document.getElementById('card_cvc-<?php echo $form_id; ?>');

            function checkCvv() {
                var cvvValue = cardCvv.value;
                if (cvvValue.length <= 2) {
                    document.querySelector('.red-error-cvv').style.display = 'block';
                } else {
                    document.querySelector('.red-error-cvv').style.display = 'none';
                }
            }
            cardCvv.addEventListener('input', checkCvv);
            cardCvv.addEventListener('change', checkCvv);

        });
    </script>
<?php
    return ob_get_clean();
}

/**
 * Get Publishable Key.
 *
 * @param int $form_id Form ID.
 *
 * @since 2.5.0
 *
 * @return string
 */
function lyfepay_give_get_publishable_key($form_id = 0)
{
    return give_get_option('lyfepay_publishable_key', '');
}

/**
 * Get API Key.
 *
 * @param int $form_id Form ID.
 *
 * @since 2.5.0
 *
 * @return string
 */
function lyfepay_give_get_api_key($form_id = 0)
{
    return give_get_option('lyfepay_api_key', '');
}

/**
 * Get API Secret Key.
 *
 * @param int $form_id Form ID.
 *
 * @since 2.5.0
 *
 * @return string
 */
function lyfepay_give_get_api_secret_key($form_id = 0)
{
    return give_get_option('lyfepay_api_secret_key', '');
}

/**
 * Get Webhook Url
 * 
 * @param int $form_id Form ID
 * 
 * @since 2.5.0
 * 
 * @return string
 */
function lyfepay_givewp_get_webhook_url()
{
    return give_get_option('lyfepay_webhook_url', '');
}

/**
 * ACH Form
 * 
 * @param int $form_id Form ID.
 * 
 * @since 2.5.0
 * 
 * @return string
 */
function lyfepay_givewp_custom_ach_form($form_id)
{

    ob_start();

    /**
     * Fires while rendering ACH info form, before the fields.
     *
     * @param  int  $form_id  The form ID.
     *
     * @since 1.0
     */
    do_action('give_before_cc_fields', $form_id);

?>
    <fieldset id="give_cc_fields-<?php echo $form_id; ?>" class="give-do-validate">
        <legend><?php esc_attr_e('Bank Account Info', 'give'); ?></legend>
        <?php
        if (is_ssl()) :
        ?>
            <div id="give_secure_site_wrapper-<?php echo $form_id; ?>">
                <span class="give-icon padlock"></span>
                <span><?php _e('This is a secure SSL encrypted payment.', 'give'); ?></span>
            </div>
        <?php
        endif;


        echo '<p id="give-account-number-wrap-' . $form_id . '"  class="form-row form-row-full form-row-responsive"> <input type="tel" autocomplete="off" size="16" id="account_number_' . $form_id . '" pattern="[0-9]*" placeholder="Account number" name="account_number" class="easy-account-number give-input required" area-required="true" style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;" required /></p>';


        echo '<p id="give-routing-number-wrap-' . $form_id . '"  class="form-row form-row-first form-row-responsive"><input type="tel" autocomplete="off" id="routing_number_' . $form_id . '" pattern="[0-9]*" placeholder="Routing number" size="9" name="routing_number" class="easy-routing-number give-input required" area-required="true" style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;" required /></p>';

        echo '<p id="give-account-type-wrap-' . $form_id . '"  class="form-row form-row-last form-row-responsive"><select autocomplete="off" id="account_type_' . $form_id . '"  name="account_type" class="easy-account_type give-input required" style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;" required ><option value="">Account Type *</option><option value="saving">Saving</option><option value="checking">Checking</option><option value="ledger">General Ledger</option></select></p>';

        ?>
    </fieldset>
    <script>
        var accountNumberInputs = document.getElementsByClassName('easy-account-number');
        var routingNumberInputs = document.getElementsByClassName('easy-routing-number');

        function validateInput(inputElement, maxLength) {
            var inputValue = inputElement.value.replace(/[^0-9]/g, ''); // Remove non-numeric characters
            inputValue = inputValue.slice(0, maxLength); // Limit length

            inputElement.value = inputValue;

            if (inputValue.length === maxLength) {
                inputElement.setCustomValidity('');
            } else {
                inputElement.setCustomValidity('Invalid input. Please enter ' + maxLength + ' digit numeric value.');
            }
        }

        Array.from(accountNumberInputs).forEach(function(input) {
            input.addEventListener('input', function() {
                validateInput(input, 16);
            });
        });

        Array.from(routingNumberInputs).forEach(function(input) {
            input.addEventListener('input', function() {
                validateInput(input, 9);
            });
        });
    </script>

<?php


    $billing_fields_enabled = gateway_enable_disable_billing_address();
    if ($billing_fields_enabled) {
        do_action('give_after_cc_fields', $form_id);
    }

    $form = ob_get_clean();
    return $form;
}
