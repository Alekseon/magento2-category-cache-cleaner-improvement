# Magento 2 Extension - Alekseon/CategoryCacheCleanerImprovement

- Reduce category cache refresh frquency.
- Prevent for refreshing category cache if stock status has been changed only for not visible individually products.

## Installation
- From your CLI run: composer alekseon/category-cache-cleaner-improvement
- Flush your cache.
- Upgrade database: bin/magento setup:upgrade

## Configuration

Enable/Disable improvement under:

stores -> configuration -> catalog -> cache -> Refresh the Category Cache only if stock status changed for visible products
