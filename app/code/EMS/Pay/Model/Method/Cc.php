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

class Cc extends AbstractMethodCc
{
    protected $_code = Config::METHOD_CC;
    protected $_formBlockType = 'ems_pay/payment_form_cc';

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
     * @var \EMS\Pay\Model\HashFactory
     */
    private $hashFactory;


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
     * @internal param Hash $hashHandler
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
        $this->_hashFactory = $hashFactory;
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
     * Name of field used in form
     *
     * @var string
     */
    protected $_cardTypeFieldName = 'ems_card_type';
    /**
     * @inheritdoc
     */
    protected function _is3DSecureEnabled()
    {
        return $this->_config->isCreditCard3DSecureEnabled();
    }
    /**
     * @param string $code
     * @return bool
     */
    protected function _validateCardType($code)
    {
        return !$this->_config->isCreditCardTypeEnabled($code);
    }
    /**
     * Returns list of enabled credit card types
     *
     * @return array card names indexed by card code
     */
    protected function _getEnabledCardTypes()
    {
        return $this->_config->getEnabledCreditCardTypes();
    }
}