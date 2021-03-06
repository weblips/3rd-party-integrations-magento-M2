<?php
/**
 * @category   Emarsys
 * @package    Emarsys_Emarsys
 * @copyright  Copyright (c) 2019 Emarsys. (http://www.emarsys.net/)
 */
namespace Emarsys\Emarsys\Block;

use Magento\{
    Catalog\Model\Category,
    Catalog\Model\Product,
    Framework\Exception\LocalizedException,
    Framework\Exception\NoSuchEntityException,
    Framework\View\Element\Template,
    Framework\View\Element\Template\Context,
    Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory,
    Catalog\Model\CategoryFactory,
    Framework\App\Request\Http,
    Framework\Registry,
    Directory\Model\CurrencyFactory,
    Framework\EntityManager\MetadataPool,
    Catalog\Api\Data\CategoryInterface,
    Store\Model\StoreManagerInterface
};
use Emarsys\Emarsys\Helper\Data\Proxy as EmarsysHelper;

/**
 * Class JavascriptTracking
 * @package Emarsys\Emarsys\Block
 */
class JavascriptTracking extends Template
{
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CategoryFactory
     */
    protected $categoryFactory;

    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @var CurrencyFactory
     */
    protected $currencyFactory;

    /**
     * @var CategoryCollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * JavascriptTracking constructor.
     *
     * @param Context                   $context
     * @param CategoryFactory           $categoryFactory
     * @param Http                      $request
     * @param Registry                  $registry
     * @param CurrencyFactory           $currencyFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param MetadataPool              $metadataPool
     * @param array                     $data
     */
    public function __construct(
        Context $context,
        CategoryFactory $categoryFactory,
        Http $request,
        Registry $registry,
        CurrencyFactory $currencyFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        MetadataPool $metadataPool,
        array $data = []
    ) {
        $this->storeManager = $context->getStoreManager();
        $this->categoryFactory = $categoryFactory;
        $this->_request = $request;
        $this->coreRegistry = $registry;
        $this->currencyFactory = $currencyFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->metadataPool = $metadataPool;
        parent::__construct($context, $data);
    }

    /**
     * @return array
     * @throws NoSuchEntityException
     */
    public function getPageHandleStatus()
    {
        $handle = $this->_request->getFullActionName();

        $pageResult = [];

        $pageHandles = [
            'cms_index_index' => Data::XPATH_CMS_INDEX_INDEX,
            'catalog_category_view' => Data::XPATH_CATALOG_CATEGORY_VIEW,
            'catalog_product_view' => Data::XPATH_CATALOG_PRODUCT_VIEW,
            'checkout_cart_index' => Data::XPATH_CHECKOUT_CART_INDEX,
            'checkout_onepage_success' => Data::XPATH_CHECKOUT_ONEPAGE_SUCCESS,
            'catalogsearch_result_index' => Data::XPATH_CATALOGSEARCH_RESULT_INDEX
        ];

        if (array_key_exists($handle, $pageHandles)) {
            $jsStatus = $this->getJsEnableStatusForAllPages();
            if ($jsStatus == 1) {
                $path = $pageHandles[$handle];
                $pageValue = $this->storeManager->getStore()->getConfig($path);
                $pageData = explode('||', $pageValue);
                $pageResult['logic'] = $pageData[0];
                $pageResult['templateId'] = $pageData[1];
                $pageResult['status'] = 'Valid';
            } else {
                $pageResult['status'] = 'Invalid';
            }
        } else {
            $pageResult['status'] = 'Invalid';
        }

        return $pageResult;
    }

    /**
     * Get Merchant Id from DB
     *
     * @return array
     * @throws NoSuchEntityException
     */
    public function getMerchantId()
    {
        return $this->storeManager->getStore()->getConfig(Data::XPATH_WEBEXTEND_MERCHANT_ID);
    }

    /**
     * Get Status of Web Extended Javascript integration from DB
     *
     * @return bool
     * @throws NoSuchEntityException
     */
    public function getJsEnableStatusForAllPages()
    {
        return (bool)$this->storeManager->getStore()->getConfig(Data::XPATH_WEBEXTEND_JS_TRACKING_ENABLED);
    }

    /**
     * @return bool
     * @throws NoSuchEntityException
     */
    public function isTestModeEnabled()
    {
        return (bool)$this->storeManager->getStore()->getConfig(Data::XPATH_WEBEXTEND_MODE);
    }

    /**
     * Get Tracking Data
     *
     * @return mixed
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getTrackingData()
    {
        return \Zend_Json::encode([
            'product' => $this->getCurrentProduct(),
            'category' => $this->getCategory(),
            'search' => $this->getSearchData(),
            'exchangeRate' => $this->getExchangeRate(),
            'slug' => $this->getStoreSlug(),
            'displayCurrency' => $this->getDisplayCurrency(),
        ]);
    }

    /**
     * Get Current Product
     *
     * @return bool|mixed
     */
    public function getCurrentProduct()
    {
        $product = $this->coreRegistry->registry('current_product');
        if ($product instanceof Product) {
            return [
                'sku' => $product->getSku(),
                'id'  => $product->getId(),
            ];
        }

        return false;
    }

    /**
     * Get Category
     *
     * @return mixed
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getCategory()
    {
        $category = $this->coreRegistry->registry('current_category');
        if ($category instanceof Category) {
            $categoryList = [];

            $categoryIds = $this->removeDefaultCategories($category->getPathIds());

            $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();

            /** @var Collection $categoryCollection */
            $categoryCollection = $this->categoryCollectionFactory->create()
                ->setStore($this->storeManager->getStore())
                ->addAttributeToSelect('name')
                ->addFieldToFilter($linkField, ['in' => $categoryIds]);

            /** @var Category $category */
            foreach ($categoryCollection as $categoryItem) {
                $categoryList[] = addcslashes($categoryItem->getName(), "'");
            }

            return [
                'names' => $categoryList,
                'ids'   => $categoryIds,
            ];
        }
        return false;
    }

    /**
     * @param array $categoryIds
     *
     * @return array
     * @throws NoSuchEntityException
     */
    protected function removeDefaultCategories($categoryIds)
    {
        $returnArray = [];
        $basicCategoryIds = [
            1,
            $this->storeManager->getStore()->getRootCategoryId(),
        ];
        foreach ($categoryIds as $categoryId) {
            if (!in_array($categoryId, $basicCategoryIds)) {
                $returnArray[] = $categoryId;
            }
        }

        return $returnArray;
    }

    /**
     * Get Search Data
     *
     * @return bool|mixed
     * @throws Exception
     */
    public function getSearchData()
    {
        $q = $this->_request->getParam('q');
        if ($q != '') {
            return [
                'term' => $this->escapeJs($q),
            ];
        }
        return false;
    }

    /**
     * @return float
     * @throws NoSuchEntityException
     */
    public function getExchangeRate()
    {
        $currentCurrency = $this->storeManager->getStore()->getCurrentCurrency()->getCode();
        $baseCurrency = $this->storeManager->getStore()->getBaseCurrency()->getCode();
        return (float)$this->currencyFactory->create()->load($baseCurrency)->getAnyRate($currentCurrency);
    }

    /**
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function getDisplayCurrency()
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    /**
     * @return string
     * @throws NoSuchEntityException
     */
    public function getStoreSlug()
    {
        return $this->storeManager->getStore()->getCode();
    }
}
