<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Product;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;

/**
 * Builds collections of the products worth warming.
 */
class ActiveProductCollection
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function forSimple(): Collection
    {
        return $this->build(ProductType::TYPE_SIMPLE);
    }

    public function forConfigurable(): Collection
    {
        return $this->build(ConfigurableType::TYPE_CODE);
    }

    /**
     * @param int[] $productIds
     */
    public function forIds(string $typeId, array $productIds): Collection
    {
        $collection = $this->build($typeId);
        $collection->addIdFilter($productIds);

        return $collection;
    }

    private function build(string $typeId): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('type_id', $typeId);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        // Not-visible-individually products are the child variants of a
        // configurable; the parent's warm covers them.
        $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);

        return $collection;
    }
}
