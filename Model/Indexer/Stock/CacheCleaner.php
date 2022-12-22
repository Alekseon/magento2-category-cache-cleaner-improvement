<?php
/**
 * Copyright © Alekseon sp. z o.o.
 * http://www.alekseon.com/
 */
namespace Alekseon\CategoryCacheCleanerImprovement\Model\Indexer\Stock;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Attribute;
use Magento\CatalogInventory\Model\Stock;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Class CacheCleaner
 * @package Alekseon\CategoryCacheCleanerImprovement\Model\Indexer\Stock
 */
class CacheCleaner extends \Magento\CatalogInventory\Model\Indexer\Stock\CacheCleaner
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;
    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface
     */
    private $stockConfiguration;
    /**
     * @var \Magento\Framework\Indexer\CacheContext
     */
    private $cacheContext;
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;
    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     */
    private $metadataPool;
    /**
     * @var
     */
    private $connection;
    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * IndexerStockCacheCleanerPlugin constructor.
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Framework\Indexer\CacheContext $cacheContext
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\EntityManager\MetadataPool $metadataPool
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Framework\Indexer\CacheContext $cacheContext,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\EntityManager\MetadataPool $metadataPool,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resource = $resource;
        $this->stockConfiguration = $stockConfiguration;
        $this->cacheContext = $cacheContext;
        $this->eventManager = $eventManager;
        $this->metadataPool = $metadataPool;
        $this->productFactory = $productFactory;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($resource, $stockConfiguration, $cacheContext, $eventManager, $metadataPool);
    }

    /**
     * @param array $productIds
     * @param callable $reindex
     */
    public function clean(array $productIds, callable $reindex)
    {
        if ($this->scopeConfig->getValue('catalog/cache/alekseon_cache_cleaner_enabled')) {
            $productStatusesBefore = $this->getProductStockStatuses($productIds);
            $reindex();
            $productStatusesAfter = $this->getProductStockStatuses($productIds);
            $productIds = $this->getProductIdsForCacheClean($productStatusesBefore, $productStatusesAfter);
            if ($productIds) {
                $this->cacheContext->registerEntities(Product::CACHE_TAG, array_unique($productIds));
                $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
                $productIdsForCategoryCacheClean = $this->getProductIdsForCategoryCacheClean($productStatusesBefore, $productStatusesAfter);
                $categoryIds = $this->getCategoryIdsByProductIds($productIdsForCategoryCacheClean);
                if ($categoryIds) {
                    $this->cacheContext->registerEntities(Category::CACHE_TAG, array_unique($categoryIds));
                    $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
                }
            }
        } else {
            parent::clean($productIds, $reindex);
        }
    }

    /**
     * @param array $productStatusesBefore
     * @param array $productStatusesAfter
     * @return array
     */
    protected function getProductIdsForCategoryCacheClean(array $productStatusesBefore, array $productStatusesAfter)
    {
        $productIds = array_unique(array_merge(array_keys($productStatusesBefore), array_keys($productStatusesAfter)));

        $productResource = $this->productFactory->create()->getResource();
        /** @var Attribute $visibilityAttribute */
        $visibilityAttribute = $productResource->getAttribute('visibility');

        if ($visibilityAttribute) {
            $visibleProductIds = [];

            $select = $productResource->getConnection()->select()
                ->from(
                    ['visibility' => $visibilityAttribute->getBackendTable()],
                    ['entity_id']
                )
                ->where('attribute_id = ?', $visibilityAttribute->getId(), \Zend_Db::INT_TYPE)
                ->where('entity_id IN (?)', $productIds, \Zend_Db::INT_TYPE)
                ->where('value NOT IN (?)', [\Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE], \Zend_Db::INT_TYPE)
                ->group('entity_id');

            foreach ($productResource->getConnection()->fetchAll($select) as $item) {
                $productId = (int) $item['entity_id'];
                $visibleProductIds[$productId] = $productId;
            }

            foreach ($productStatusesBefore as $productId => $status) {
                if (!isset($visibleProductIds[$productId])) {
                    unset($productStatusesBefore[$productId]['parent_id']);
                }
            }

            foreach ($productStatusesAfter as $productId => $status) {
                if (!isset($visibleProductIds[$productId])) {
                    unset($productStatusesAfter[$productId]['parent_id']);
                }
            }
        }

        return $this->getProductIdsForCacheClean($productStatusesBefore, $productStatusesAfter);
    }

    /**
     * @param array $productIds
     * @return array
     */
    private function getProductStockStatuses(array $productIds)
    {
        $linkField = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->getLinkField();
        $select = $this->getConnection()->select()
            ->from(
                ['css' => $this->resource->getTableName('cataloginventory_stock_status')],
                ['product_id', 'stock_status', 'qty']
            )
            ->joinLeft(
                ['cpr' => $this->resource->getTableName('catalog_product_relation')],
                'css.product_id = cpr.child_id',
                []
            )
            ->joinLeft(
                ['cpe' => $this->resource->getTableName('catalog_product_entity')],
                'cpr.parent_id = cpe.' . $linkField,
                ['parent_id' => 'cpe.entity_id']
            )
            ->where('product_id IN (?)', $productIds, \Zend_Db::INT_TYPE)
            ->where('stock_id = ?', Stock::DEFAULT_STOCK_ID)
            ->where('website_id = ?', $this->stockConfiguration->getDefaultScopeId());

        $statuses = [];
        foreach ($this->getConnection()->fetchAll($select) as $item) {
            $statuses[$item['product_id']] = $item;
        }
        return $statuses;
    }

    /**
     * @param array $productStatusesBefore
     * @param array $productStatusesAfter
     * @return array
     */
    private function getProductIdsForCacheClean(array $productStatusesBefore, array $productStatusesAfter)
    {
        $disabledProductsIds = array_diff(array_keys($productStatusesBefore), array_keys($productStatusesAfter));
        $enabledProductsIds = array_diff(array_keys($productStatusesAfter), array_keys($productStatusesBefore));
        $commonProductsIds = array_intersect(array_keys($productStatusesBefore), array_keys($productStatusesAfter));
        $productIds = array_merge($disabledProductsIds, $enabledProductsIds);

        $stockThresholdQty = $this->stockConfiguration->getStockThresholdQty();

        foreach ($commonProductsIds as $productId) {
            $statusBefore = $productStatusesBefore[$productId];
            $statusAfter = $productStatusesAfter[$productId];

            if ($statusBefore['stock_status'] !== $statusAfter['stock_status']
                || ($stockThresholdQty && $statusAfter['qty'] <= $stockThresholdQty)) {
                $productIds[] = $productId;
                if (isset($statusAfter['parent_id'])) {
                    $productIds[] = $statusAfter['parent_id'];
                }
            }
        }

        return $productIds;
    }

    /**
     * @param array $productIds
     * @return array
     */
    private function getCategoryIdsByProductIds(array $productIds): array
    {
        $categoryProductTable = $this->resource->getTableName('catalog_category_product');
        $select = $this->getConnection()->select()
            ->from(['catalog_category_product' => $categoryProductTable], ['category_id'])
            ->where('product_id IN (?)', $productIds);

        return $this->getConnection()->fetchCol($select);
    }

    /**
     * @return mixed
     */
    private function getConnection()
    {
        if (null === $this->connection) {
            $this->connection = $this->resource->getConnection();
        }

        return $this->connection;
    }
}
