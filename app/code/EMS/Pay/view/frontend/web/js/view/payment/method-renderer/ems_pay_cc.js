define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/quote',
        'mage/url',
        'Magento_Checkout/js/action/place-order',
        'jquery',
        'EMS_Pay/js/view/payment/method-renderer/form-builder',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Checkout/js/model/full-screen-loader'

    ],
    function (Component, customer, quote, url, placeOrderAction, $,formBuilder,errorProcessor,fullScreenLoader) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'EMS_Pay/payment/ems_pay_cc',
                selectedCardType: '',
                redirectAfterPlaceOrder: false
            },
            initObservable: function () {
                this._super()
                    .observe('selectedCardType');
                return this;
            },

            getTransportName: function() {
                return window.checkoutConfig.payment.paypalBillingAgreement.transportName;
            },
            /**
             * @returns {String}
             */
            getCode: function () {
                return 'ems_pay_cc';
            },

            getAvailableCardTypes: function () {
                return window.checkoutConfig.payment[this.getCode()].availableCardTypes;
            },

            getLogoFileNames: function () {
                return window.checkoutConfig.emsPayGeneral.logoFileNames;
            },

            getCardTypeFieldName: function () {
                return window.checkoutConfig.payment[this.getCode()].cardTypeFieldName;
            },

            getCardList: function() {
                return _.map(this.getAvailableCardTypes(), function(value, key) {
                    return {
                        'value': key,
                        'type': value
                    }
                });
            },
            getLogoList: function() {
                return _.map(this.getLogoFileNames(), function(value, key) {
                    return {
                        'value': key,
                        'type': value
                    }
                });
            },

            getRedirectUrl: function () {
                return window.checkoutConfig.payment.emsPayGeneral.emspayRedirectUrl
            },

            getLogos: function (type) {
                return window.checkoutConfig.payment[this.getCode()].logoFileNames.hasOwnProperty(type)
                    ? window.checkoutConfig.payment[this.getCode()].logoFileNames[type]
                    : false
            },

            getData: function() {

                var additionalData = null;
                if (this.getCardTypeFieldName()) {
                    additionalData = {};
                    additionalData[this.getCardTypeFieldName()] = this.selectedCardType();
                }
                return {'method': this.getCode(), 'additional_data': additionalData};
            },
            /**
             * @returns {Boolean}
             */
            isActive: function () {
                return window.checkoutConfig.payment[this.getCode()].isActive;
            },

            afterPlaceOrder: function (/*data, event*/) {
                // window.location.replace(url.build(this.getRedirectUrl()));
                var self = this;
                $.get(url.build(this.getRedirectUrl()))
                    .done(function (response) {
                        customer.invalidate(['cart']);
                        formBuilder.build(response).submit();
                    }).fail(function (response) {
                    errorProcessor.process(response, self.messageContainer);
                }).always(function () {
                    fullScreenLoader.stopLoader();
                });

            }



        });
    }
);
