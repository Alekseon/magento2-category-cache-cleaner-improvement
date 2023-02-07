<?php
/**
 * Copyright © Alekseon sp. z o.o.
 * http://www.alekseon.com/
 */
namespace Alekseon\CategoryCacheCleanerImprovement\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\View;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 *
 */
class ProductPageCacheIdentifierPlugin
{
     /**
     * @var
     */
    protected $coreRegistry;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param \Magento\Framework\Registry $coreRegistry
     */
    public function __construct(
        \Magento\Framework\Registry $coreRegistry,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->coreRegistry = $coreRegistry;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param View $productViewBlock
     * @param $identities
     * @return mixed
     */
    public function afterGetIdentities(ProductInterface $product, $identities)
    {
        $currentProduct = $this->coreRegistry->registry('current_product');
        if ($currentProduct && $currentProduct->getId() == $product->getId()) {
            $cacheTag = $this->scopeConfig->getValue('alekseon/cache_cleaner_improvement/product_page_tag');
            $identities[] = $cacheTag . '_' . $product->getId();
        }
        return $identities;
    }
}
