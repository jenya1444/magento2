<?php

namespace Magento\EMS\Pay\Model\Method;

use \Magento\EMS\Pay\Model\Currency;
use \Magento\EMS\Pay\Gateway\Config\Config;
use \Magento\EMS\Pay\Model\Hash;
use \Magento\EMS\Pay\Model\Response;
use \Magento\EMS\Pay\Model\Info;

abstract class AbstractMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_infoBlockType = 'ems_pay/payment_info';
    protected $_formBlockType = 'ems_pay/payment_form_form';

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * Payment config instance
     *
     * @var Config
     */
    protected $_config = null;

    /**
     * @var Hash
     */
    protected $_hashHandler;

    /**
     * @var Currency
     */
    protected $_currency;



    /**
     * Depending on magento tax configuration discount may be applied on row total price.
     * EMS gateway expects to be given price for single item instead of row total if qty > 1
     * In some cases when qty for given product is > 1 rowTotal/qty results in price with 3 digits after decimal point
     * Prices with more than 2 digits after decimal point are not accepted by EMS.
     *
     * This array stores amounts used to round item prices that had 3 digits after decimal point that are used to
     * update chargetotal sent to EMS
     *
     * @var array
     */
    protected $_roundingAmounts = [];

    /**
     * Stores current index of cart item fields
     *
     * @var int
     */
    protected $_itemFieldsIndex = 1;

    public function __construct(
        Currency $currency,
        Hash $hashHandler,

    )
    {
        $this->_currency = $currency;
        $this->_hashHandler = $hashHandler;
    }

    /**
     * Return Order place redirect url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('emspay/index/redirect', array('_secure' => true));
    }

    /**
     * Instantiate order state and set it to state object
     * @param string $paymentAction
     * @param Varien_Object
     *
     * @return \Magento\EMS\Pay\Model\Method\AbstractMethod
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Returns payment action
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        /**
         * TODO check if really needed
         */
        return 'authorize';
    }

    /**
     * @return string
     */
    public function getGatewayUrl()
    {
        return $this->_config->getGatewayUrl();
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getRedirectFormFields()
    {
        $debugData = [];
        $config = $this->_getConfig();

        try {
            $fields = [
                Info::TXNTYPE => $config->getTxnType(),
                Info::TIMEZONE => $this->_getTimezone(),
                Info::TXNDATETIME => $this->_getTransactionTime(),
                Info::HASH_ALGORITHM => $this->_getHashAlgorithm(),
                Info::HASH => $this->_getHash(),
                Info::STORENAME => $this->_getStoreName(),
                Info::MODE => $config->getDataCaptureMode(),
                Info::CHECKOUTOPTION => $this->_getCheckoutOption(),
                Info::CHARGETOTAL => $this->_getChargeTotal(),
                Info::CURRENCY => $this->_getOrderCurrencyCode(),
                Info::ORDER_ID => $this->_getOrderId(),
                Info::PAYMENT_METHOD => $this->_getPaymentMethod(),
                Info::RESPONSE_FAIL_URL => Mage::getUrl('emspay/index/fail', array('_secure' => true)),
                Info::RESPONSE_SUCCESS_URL => Mage::getUrl('emspay/index/success', array('_secure' => true)),
                Info::TRANSACTION_NOTIFICATION_URL => Mage::getUrl('emspay/index/ipn', array('_secure' => true)),
                Info::LANGUAGE => $this->_getLanguage(),
                Info::BEMAIL => $this->_getOrder()->getCustomerEmail(),
                Info::MOBILE_MODE => $this->_getMobileMode(),
            ];

            $fields = array_merge($fields, $this->_getAddressRequestFields());
            $fields = array_merge($fields, $this->_getMethodSpecificRequestFields());
            $this->_saveTransactionData();
        } catch (\Exception $ex) {
            $debugData['exception'] = $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine();
            $this->_debug($debugData);
            throw $ex;
        }

        $debugData[] = __('Generated redirect form fields');
        $debugData['redirect_form_fields'] = $fields;
        $this->_debug($debugData);

        return $fields;
    }

    /**
     * Generates payment request address fields
     *
     * @return array
     */
    protected function _getAddressRequestFields()
    {
        $fields = [];
        $order = $this->_getOrder();

        $billingAddress = $order->getBillingAddress();
        $fields[Info::BCOMPANY] = $billingAddress->getCompany();
        $fields[Info::BNAME] = $billingAddress->getName();
        $fields[Info::BADDR1] = $billingAddress->getStreet1();
        $fields[Info::BADDR2] = $billingAddress->getStreet2();
        $fields[Info::BCITY] = $billingAddress->getCity();
        $fields[Info::BSTATE] = $billingAddress->getRegion();
        $fields[Info::BCOUNTRY] = $billingAddress->getCountry();
        $fields[Info::BZIP] = $billingAddress->getPostcode();
        $fields[Info::BPHONE] = $billingAddress->getTelephone();

        $shippingAddress = $order->getShippingAddress();
        $fields[Info::SNAME] = $shippingAddress->getName();
        $fields[Info::SADDR1] = $shippingAddress->getStreet1();
        $fields[Info::SADDR2] = $shippingAddress->getStreet2();
        $fields[Info::SCITY] = $shippingAddress->getCity();
        $fields[Info::SSTATE] = $shippingAddress->getRegion();
        $fields[Info::SCOUNTRY] = $shippingAddress->getCountry();
        $fields[Info::SZIP] = $shippingAddress->getPostcode();

        return $fields;
    }

    /**
     * Generates cart related (items, shipping fee, discount) payment request fields
     *
     * @return array
     */
    protected function _getCartRequestFields()
    {
        $fields = [];
        $order = $this->_getOrder();

        $fields[Info::SHIPPING] = Info::CART_ITEM_SHIPPING_AMOUNT;
        $fields[Info::VATTAX] = $this->_roundPrice($order->getBaseTaxAmount());
        $fields[Info::SUBTOTAL] = $this->_getSubtotal();

        foreach ($order->getAllVisibleItems() as $item) {
            /** @var $item Mage_Sales_Model_Order_Item */
            $fields[Info::CART_ITEM_FIELD_INDEX . $this->_itemFieldsIndex] =
                $item->getId() . Info::CART_ITEM_FIELD_SEPARATOR .
                $item->getName() . Info::CART_ITEM_FIELD_SEPARATOR .
                (int)$item->getQtyOrdered() . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getItemPriceInclTax($item) . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getItemPrice($item) . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getItemTax($item) . Info::CART_ITEM_FIELD_SEPARATOR .
                Info::CART_ITEM_SHIPPING_AMOUNT;

            $this->_itemFieldsIndex++;
        }

        if ($this->_getRoundingAmount()) { //recalculate totals and hash
            $fields[Info::CHARGETOTAL] = $this->_getChargeTotal();
            $fields[Info::SUBTOTAL] = $this->_getSubtotal();
            $fields[Info::HASH] = $this->_getHash();
        }

        /* another approach of solving rounding issue - rounding amount added as separate cart issue
         * it's not used for now
                if ($this->getRoundingAmount()) {
                    $fields[Info::CART_ITEM_FIELD_INDEX . $this->_itemFieldsIndex] =
                        $this->getOrderId() . '_rounding' . Info::CART_ITEM_FIELD_SEPARATOR .
                        'Rounding fee' . Info::CART_ITEM_FIELD_SEPARATOR .
                        Info::SHIPPING_QTY . Info::CART_ITEM_FIELD_SEPARATOR .
                        $this->getRoundingAmount() . Info::CART_ITEM_FIELD_SEPARATOR .
                        $this->getRoundingAmount() . Info::CART_ITEM_FIELD_SEPARATOR .
                        0 . Info::CART_ITEM_FIELD_SEPARATOR .
                        Info::CART_ITEM_SHIPPING_AMOUNT;

                    $this->_itemFieldsIndex++;
                }
        */
        $fields[Info::CART_ITEM_FIELD_INDEX . $this->_itemFieldsIndex] =
            Info::SHIPPING_FIELD_NAME . Info::CART_ITEM_FIELD_SEPARATOR .
            Info::SHIPPING_FIELD_LABEL . Info::CART_ITEM_FIELD_SEPARATOR .
            Info::SHIPPING_QTY . Info::CART_ITEM_FIELD_SEPARATOR .
            $this->_roundPrice($order->getBaseShippingInclTax()) . Info::CART_ITEM_FIELD_SEPARATOR .
            $this->_roundPrice($order->getBaseShippingAmount()) . Info::CART_ITEM_FIELD_SEPARATOR .
            $this->_roundPrice($order->getBaseShippingTaxAmount()) . Info::CART_ITEM_FIELD_SEPARATOR .
            Info::CART_ITEM_SHIPPING_AMOUNT;;
        $this->_itemFieldsIndex++;

        if ($this->_getDiscountInclTax() != 0) {
            $fields[Info::CART_ITEM_FIELD_INDEX . $this->_itemFieldsIndex] =
                Info::DISCOUNT_FIELD_NAME . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getDiscountLabel() . Info::CART_ITEM_FIELD_SEPARATOR .
                Info::SHIPPING_QTY . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getDiscountInclTax() . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getDiscount() . Info::CART_ITEM_FIELD_SEPARATOR .
                $this->_getDiscountTaxAmount() . Info::CART_ITEM_FIELD_SEPARATOR .
                Info::CART_ITEM_SHIPPING_AMOUNT;;
        }

        return $fields;
    }

    /**
     * Generates payment request fields specific for used method
     *
     * @return array
     */
    protected function _getMethodSpecificRequestFields()
    {
        return [];
    }

    /**
     * @return string
     */
    protected function _getHash()
    {
        return $this->_hashHandler->generateRequestHash(
            $this->_getTransactionTime(),
            $this->_getChargeTotal(),
            $this->_getOrderCurrencyCode()
        );
    }

    /**
     * @return string
     */
    protected function _getHashAlgorithm()
    {
        return $this->_hashHandler->getHashAlgorithm();
    }

    /**
     * Retrieves checkout option
     *
     * @return string
     */
    protected function _getCheckoutOption()
    {
        return $this->_config->getCheckoutOption();
    }

    /**
     * Retrieves payment method code used by ems based on magento code
     *
     * @return string
     */
    protected function _getPaymentMethod()
    {
        return $this->_getMethodCodeMapper()->getEmsCodeByMagentoCode($this->getCode());
    }

    /**
     * @return string
     */
    protected function _getStoreName()
    {
        return $this->_config->getStoreName();
    }

    /**
     * Retrieves timezone from order
     *
     * @return string
     */
    protected function _getTimezone()
    {
        return $this->_getOrder()->getCreatedAtStoreDate()->getTimezone();
    }

    /**
     * @return string
     */
    protected function _getTransactionTime()
    {
        $order = $this->_getOrder();

        return $order->getCreatedAtStoreDate()->toString(Config::TXNDATE_ZEND_DATE_FORMAT);
    }

    /**
     * Retrieves amount to be charged from order
     *
     * @return float|string
     */
    protected function _getChargeTotal()
    {
        return $this->_roundPrice($this->_getOrder()->getBaseGrandTotal() + $this->_getRoundingAmount());
    }

    /**
     * @return float
     */
    protected function _getSubtotal()
    {
        $order = $this->_getOrder();
        return $this->_roundPrice($order->getBaseSubtotal() + $order->getBaseShippingAmount() + $this->_getDiscount() + $this->_getRoundingAmount());
    }

    /**
     * @return float
     */
    protected function _getDiscountInclTax()
    {
        return $this->_roundPrice($this->_getOrder()->getBaseDiscountAmount());
    }

    /**
     * @return float
     */
    protected function _getDiscount()
    {
        $order = $this->_getOrder();
        return $this->_roundPrice($this->_getDiscountInclTax() + $order->getBaseHiddenTaxAmount()); //discount is negative, hidden tax is positive number
    }

    /**
     * @return float
     */
    protected function _getDiscountTaxAmount()
    {
        return $this->_getDiscountInclTax() - $this->_getDiscount();
    }

    /**
     * @return string
     */
    protected function _getDiscountLabel()
    {
        return __('Discount') . ' (' . $this->_getOrder()->getDiscountDescription() . ')';
    }

    /**
     * Returns language code for current store
     *
     * @return string
     */
    protected function _getLanguage()
    {
        return $this->_config->getLanguage();
    }

    /**
     * @return int|float
     */
    protected function _getRoundingAmount()
    {
        $amount = 0;
        foreach ($this->_roundingAmounts as $rounding) {
            $amount += $rounding;
        }

        return $amount;
    }

    /**
     * @param Mage_Sales_Model_Order_Item $item
     * @return float|int
     */
    protected function _getItemPriceInclTax($item)
    {
        $qty = (int)$item->getQtyOrdered();
        $rowTotal = $item->getBaseRowTotal() + $item->getBaseTaxAmount() + $item->getBaseHiddenTaxAmount();
        $price = $this->_roundPrice($rowTotal / $qty);
        $rowTotalAfterRounding = $price * $qty;
        if ($rowTotalAfterRounding != $rowTotal) {
            $this->_roundingAmounts[$item->getId()] = round(100 * $rowTotalAfterRounding - 100 * $rowTotal) / 100;
        }

        return $price;
    }

    /**
     * @param Mage_Sales_Model_Order_Item $item
     * @return float|int
     */
    protected function _getItemPrice($item)
    {
        return $this->_roundPrice($item->getBaseRowTotal() / (int)$item->getQtyOrdered());
    }

    /**
     * @param Mage_Sales_Model_Order_Item $item
     * @return float|int
     */
    protected function _getItemTax($item)
    {
        return $this->_getItemPriceInclTax($item) - $this->_getItemPrice($item);
    }

    /**
     * Retrieves mobile mode flag value
     *
     * @return string
     */
    protected function _getMobileMode()
    {
        return $this->_config->isMobileMode() ? 'true' : 'false';
    }

    /**
     * @return string
     */
    protected function _getOrderId()
    {
        return $this->_getOrder()->getIncrementId();
    }

    /**
     * @return \Magento\Sales\Model\Order
     */
    protected function _getOrder()
    {
        return \Magento\Sales\Model\Order;->getLastRealOrder();
    }

    /**
     * Retrieves currency numeric code required by EMS gateway
     *
     * @return int
     */
    protected function _getOrderCurrencyCode()
    {
        $order = $this->_getOrder();

        return $this->_currency->getNumericCurrencyCode($order->getBaseCurrency());
    }

    /**
     * Checks whether payment method can be used with specific currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return $this->_config->isCurrencySupported($currencyCode);
    }

    /**
     * Returns human readable payment method name
     *
     * @return string
     */
    protected function _getPaymentMethodName()
    {
        return Mage::getModel('ems_pay/method_code_mapper')->getHumanReadableByEmsCode($this->_getPaymentMethod());
    }

    /**
     * @return string
     */
    protected function _getTextCurrencyCode()
    {
        return $this->_currency->getTextCurrencyCode($this->_getOrderCurrencyCode());
    }

    /**
     * Returns payment method logo file name
     *
     * @return string
     */
    public function getLogoFilename()
    {
        return $this->_config->getLogoFilename();
    }

    /**
     * Saves important information about the transaction for future use
     *
     * @return $this
     */
    protected function _saveTransactionData()
    {
        $data = [
            Info::CURRENCY => $this->_getTextCurrencyCode(),
            Info::CHARGETOTAL => $this->_getChargeTotal(),
            Info::TXNDATETIME => $this->_getTransactionTime(),
            Info::HASH_ALGORITHM => $this->_getHashAlgorithm(),
            Info::PAYMENT_METHOD => $this->_getPaymentMethodName(),
        ];

        $info = $this->getInfoInstance();
        foreach ($data as $key => $value) {
            $info->setAdditionalInformation($key, $value);
        }

        $info->save();
        return $this;
    }

    /**
     * @return string|null
     */
    public function getTransactionTimeSentInTransactionRequest()
    {
        $info = $this->getInfoInstance();
        return $info->getAdditionalInformation(Info::TXNDATETIME);
    }

    /**
     * @return string|null
     */
    public function getHashAlgorithmSentInTransactionRequest()
    {
        $info = $this->getInfoInstance();
        return $info->getAdditionalInformation(Info::HASH_ALGORITHM);
    }

    /**
     * @inheritdoc
     */
    protected function _debug($debugData)
    {
        if ($this->getDebugFlag()) {
            Mage::getModel('core/log_adapter', $this->_config->getLogFile())
                ->setFilterDataKeys($this->_debugReplacePrivateDataKeys)
                ->log($debugData);
        }
    }

    /**
     * @inheritdoc
     */
    public function getDebugFlag()
    {
        return $this->_config->isDebuggingEnabled();
    }

    /**
     * @return Config
     */
    protected function _getConfig()
    {
        if (null === $this->_config) {
            $params = [$this->getCode()];
            if ($store = $this->getStore()) {
                $params[] = is_object($store) ? $store->getId() : $store;
            }

            $this->_config = new Config ($params);
//            $this->_config = Mage::getModel('ems_pay/config', $params);
        }

        return $this->_config;
    }

    /**
     * @return EMS_Pay_Model_Method_Code_Mapper
     */
    protected function _getMethodCodeMapper()
    {
        return Mage::getModel('ems_pay/method_code_mapper');
    }

    /**
     * @param $price
     * @return float
     */
    protected function _roundPrice($price)
    {
        return Mage::app()->getStore()->roundPrice($price);
    }

    /**
     * Adds transaction specific information to payment object.
     * It's ment to be overridden and used by classes that inherit from this one
     *
     * @param Response $transactionResponse
     */
    public function addTransactionData(Response $transactionResponse)
    {
    }
}