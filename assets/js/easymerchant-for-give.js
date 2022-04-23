var giveForm = false;
function easySubmitForm() {
    giveForm.submit();
}
var easyForGive = function () {
    function setForm(frm) {
        console.log('setting form');
        console.log({frm});
        giveForm = frm;
    }
    function initiate() {
        console.log('inside efg initiate')
        var publishable_key = easymerchant_for_give_vars.publishable_key;
        console.log({publishable_key});
        var amount = 20;//document.querySelector(".give-final-total-amount").textContent;
        // bind your value into easymerchant payments
        // easyMerchant.bindPaymentDetails(publishable_key, amount, afterSuccess);
    }
    // After Payment success you will get the response within this function
    function afterSuccess(response) {
        console.log('all good');
        giveForm.submit();
        console.log({response});return;
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
            giveForm = r;
            console.log('inside easy give form wrap');
            console.log(giveForm);
            console.log({r});
            setForm(r);

            var easy_form_prefix = document.querySelector('input[name="give-form-id-prefix"]');

            if (null !== r) {
                console.log('listening to easy form submit');
                document.addEventListener("give_gateway_loaded", d)
                r.onsubmit = function(e) {
                    console.log('submitting easy form')
                    var t = u(),
                        s = t.selectedGatewayId,
                        c = t.isEasyModalCheckoutGateway;
                        console.log({t});
                    if(!c) {
                        console.log('releasing form submit');
                        return true;
                    }
                    // return true;
                    console.log('controlling form submit');
                    e.preventDefault();
                    m = r.querySelector(".give-final-total-amount").textContent;
                    v = r.querySelector("#give-amount").value;
                    y = r.querySelector('input[name="give_email"]').value;
                    // easyMerchant.setAmount(v);
                    // showEasyModal();
                    data = {publishable_key: easymerchant_for_give_vars.publishable_key, amount: v, email: y, description: 'givewp donation'}
                    easyMerchant.bindPaymentDetails(data,afterSuccess);
                    cmodal = document.getElementsByClassName("em-easyModalClose")[0];
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
                    s = t.isEasyModalCheckoutGateway;
                    console.log({t})
                    if(i === 'easymerchant') {
                        // easyInit();
                        easyUIConnect.easyMerchantOnInit();
                    }
                    // alert('can trigger modal here');
                // a || "stripe" === i || s ? n.mountElement(o) : e && n.unMountElement(o), s && n.triggerStripeModal(r, n, l, o)
            }
        }));
    }))

    return { initiate }
};
easyForGive();