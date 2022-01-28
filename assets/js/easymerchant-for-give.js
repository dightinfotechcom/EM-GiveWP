var easyForGive = function () {
    function initiate() {
        console.log('inside efg initiate')
        var publishable_key = easymerchant_for_give_vars.publishable_key;
        console.log({publishable_key});
        var amount = 20;//document.querySelector(".give-final-total-amount").textContent;
        // bind your value into easymerchant payments
        easyMerchant.bindPaymentDetails(publishable_key, amount, afterSuccess);
    }
    // After Payment success you will get the response within this function
    function afterSuccess(response) {
        consol.log({response});return;
        if (response.status === 200 && response.charge_id != "") {
            setTimeout(function() {
                // alert('all good');
                // window.location.reload()
            }, 3000);
        }
    }

    // document.querySelector('input[name="payment-mode"]:checked').value;
    // give-final-total-amount

    document.addEventListener("DOMContentLoaded", (function(e) {
        console.log('inside easy DOMContentLoaded');
        Array.from(document.querySelectorAll(".give-form-wrap")).forEach((function(e) {
            var r = e.querySelector(".give-form");
            console.log('inside easy give form wrap');
            console.log({r});

            var easy_form_prefix = document.querySelector('input[name="give-form-id-prefix"]');

            if (null !== r) {
                console.log('listening to easy form submit');
                document.addEventListener("give_gateway_loaded", d)
                r.onsubmit = function(e) {
                    console.log('submitting easy form')
                    e.preventDefault();
                    m = r.querySelector(".give-final-total-amount").textContent;
                    v = r.querySelector("#give-amount").value;
                    y = r.querySelector('input[name="give_email"]').value;
                    easyMerchant.setAmount(v);
                    showEasyModal();
                    // easyMerchant.bindPaymentDetails(easymerchant_for_give_vars.publishable_key,20,afterSuccess);
                    cmodal = document.getElementsByClassName("easyModalClose")[0];
                    cmodal.addEventListener('click', function(e){
                        var t = r.querySelector(".give-submit");
                        null !== t && (t.value = t.getAttribute("data-before-validation-label"),
                        t.removeAttribute("disabled"))
                    });
                }
            } else {
                console.log('else part');
            }

            function u() {
                var e = r.querySelector('input[name="give-gateway"]'),
                    t = e ? e.value : ""
                return {
                    formGateway: e,
                    selectedGatewayId: t,
                    isEasyModalCheckoutGateway: e && "easymerchant" === t
                }
            }

            function d() {
                var e = !(arguments.length > 0 && void 0 !== arguments[0]) || arguments[0],
                    t = u(),
                    i = t.selectedGatewayId,
                    s = t.isStripeModalCheckoutGateway;
                    console.log({t})
                    if(i === 'easymerchant') {
                        easyInit();
                        // easyUIConnect.easyMerchantOnInit();
                    }
                    // alert('can trigger modal here');
                // a || "stripe" === i || s ? n.mountElement(o) : e && n.unMountElement(o), s && n.triggerStripeModal(r, n, l, o)
            }
        }));
    }))

    return { initiate }
};
easyForGive();