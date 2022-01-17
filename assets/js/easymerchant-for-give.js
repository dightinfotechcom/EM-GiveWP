function easyMerchantInitiate() {
    var publishable_key = easymerchant_for_give_vars.publishable_key;
    var amount = 20;
    // bind your value into easymerchant payments
    easyMerchant.bindPaymentDetails(publishable_key, amount, afterSuccess);
    // After Payment success you will get the response within this function
    function afterSuccess(response) {
        consol.log({response});return;
        if (response.status === 200 && response.charge_id != "") {
            setTimeout(function() {
                window.location.reload()
            }, 3000);
        }
    }
}