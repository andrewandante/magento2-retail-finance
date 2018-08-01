define(
    [
        'Magento_Payment/js/view/payment/iframe',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Ui/js/model/messageList'
    ],
    function (Component, $, q, messages) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'LendingWorks_RetailFinance/payment/retail-finance-form',
                orderToken: '',
                canApplyForFinance: false
            },

            selectPaymentMethod: function () {
                self = this;
                $.ajax({
                    showLoader: true,
                    url: '/lwapi/payment/createorder',
                    type: "POST",
                    dataType: 'json'
                }).done(function (data) {
                    self.setOrderToken(data.token);
                    self.canApplyForFinance = true;
                    console.log('Request Sent');
                }).fail(function (data) {
                    console.log(data);
                    console.log('Request Failed');
                    $('#lw-place-order').attr('disabled', 'disabled');
                });
                return this._super();
            },

            getCode: function() {
                return 'lendingworks_retailfinance';
            },

            grandTotal: function() {
                var totals = q.getTotals()();
                var segments = totals['total_segments'];
                return segments[segments.length - 1].value;
            },

            isActive: function() {
                return true;
            },

            validate: function() {
                var $form = $('#' + this.getCode() + '-form');
                return $form.validation() && $form.validation('isValid');
            },

            getOrderToken: function () {
                return $('#lw-place-order').data('lw-order-token');
            },

            setOrderToken: function (token) {
                $('#lw-place-order').data('lw-order-token', token);
            },

            applyForFinance: function () {
                self = this;
                $.getScript('http://secure.docker:3000/checkout.js')
                    .done(function() {
                        LendingWorksCheckout(
                            self.getOrderToken(),
                            window.location.href,
                            function (status, id) {
                                console.log("my status is....");
                                console.log(status);
                                console.log('my id is....');
                                console.log(id);
                                if (status === 'cancelled') {
                                    console.log("cancelled...");
                                    $('#lw-place-order').removeAttr('disabled');
                                    messages.addErrorMessage({
                                        message: 'Your application has been cancelled. You may apply again if you wish.'});
                                    return;
                                }

                                if (status === 'declined') {
                                    console.log("declined....");
                                    messages.addErrorMessage({
                                        message: 'Sorry, your application has been declined. Please select another payment method.'});
                                    $('#lw-place-order').attr('disabled', 'disabled');
                                    // Re-enable submit button and display error message telling customer to
                                    // pick a different payment method. Also prevent the Lending Works popup
                                    // from being triggered again.
                                    return;
                                }

                                if (['approved', 'referred'].indexOf(status) !== -1) {
                                    console.log("ya good.");
                                    self.placeOrder();
                                }
                            }
                        )()

                    })
                    .fail(function (jqxhr, settings, exception) {
                    });
                },

        });
    }
);