<?php
/**
 * Created by PhpStorm.
 * User: dev01
 * Date: 14.11.17
 * Time: 13:19
 */

namespace EMS\Pay\Model\Method;

use EMS\Pay\Gateway\Config\Config;
use EMS\Pay\Model\Method\Cc\AbstractMethodCc;
use EMS\Pay\Model\Response;
use EMS\Pay\Model\Method\Mapper;
use EMS\Pay\Model\Currency;
use EMS\Pay\Model\Hash;
use EMS\Pay\Model\Info;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Checkout\Model\Session;

class Klarna extends \EMS\Pay\Model\Method\EmsAbstractMethod
{
    protected $_code = Config::METHOD_KLARNA;

    /**
     * Payment data
     *
     * @var \Magento\Payment\Helper\Data
     */
    protected $_paymentData;

    /**
     * @var \Magento\Payment\Model\Method\Logger
     */
    protected $logger;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    private $timezone;


    /**
     * @param Currency $currency
     * @param \EMS\Pay\Model\HashFactory $hashFactory
     * @param Session $session
     * @param Mapper $mapper
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone
     * @param StoreManagerInterface $storeManager
     * @param \EMS\Pay\Gateway\Config\ConfigFactory $configFactory
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Currency $currency,
        \EMS\Pay\Model\HashFactory $hashFactory,
        Session $session,
        Mapper $mapper,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        StoreManagerInterface $storeManager,
        \EMS\Pay\Gateway\Config\ConfigFactory $configFactory,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []

    )
    {
        parent::__construct(
            $currency,
            $hashFactory,
            $session,
            $mapper,
            $timezone,
            $storeManager,
            $configFactory,
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection
        );
        $this->_currency = $currency;
        $this->hashFactory = $hashFactory;
        $this->_session = $session;
        $this->_mapper = $mapper;
        $this->_storeManager = $storeManager;
        $this->_store = $storeManager->getStore();
        $this->_configFactory = $configFactory;
        $this->_config = $this->_getConfig();
        $this->hash = $this->_initHash();
        $this->_scopeConfig = $scopeConfig;
        $this->_paymentData = $paymentData;
        $this->logger = $logger;
        $this->timezone = $timezone;
    }

    /**
     * Generates payment request fields specific for Klarna
     *
     * @return array
     */
    protected function _getMethodSpecificRequestFields()
    {
        $fields = [];
        /** @var $billingAddress Mage_Sales_Model_Order_Address */
        $billingAddress = $this->_getOrder()->getBillingAddress();
        $fields[Info::KLARNA_FIRSTNAME] = $billingAddress->getFirstname();
        $fields[Info::KLARNA_LASTNAME] = $billingAddress->getLastname();
        $fields[Info::KLARNA_STREET] = $billingAddress->getStreetLine(1);
        $fields[Info::KLARNA_PHONE] = $billingAddress->getTelephone();
        $fields = array_merge($fields, $this->_getCartRequestFields());
        return $fields;
    }
    /**
     * @inheritdoc
     */
    public function isApplicableToQuote($quote, $checksBitMask)
    {
        $isApplicable = parent::isApplicableToQuote($quote, $checksBitMask);
        if ($isApplicable === false) {
            return false;
        }
        if ($checksBitMask & self::CHECK_USE_FOR_CURRENCY) {
            if (!$this->_currency->isCurrencySupportedByKlarna(
                $quote->getStore()->getBaseCurrencyCode(),
                $quote->getBillingAddress()->getCountry())
            ) {
                return false;
            }
        }
        return true;
    }
    /**
     * @inheritdoc
     */
    public function canUseForCountry($country)
    {
        $canUse = parent::canUseForCountry($country);
        return $canUse && $this->_config->isCountrySupportedByKlarna($country);
    }
    /**
     * @inheritdoc
     */
    protected function _getCheckoutOption()
    {
        return Config::CHECKOUT_OPTION_CLASSIC; //klarna supports only classic
    }
}