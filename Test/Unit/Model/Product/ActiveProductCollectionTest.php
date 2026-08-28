<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Product;

use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ActiveProductCollectionTest extends TestCase
{
    /** @var array<int, array{field: mixed, condition: mixed}> */
    private array $fieldFilters = [];

    /** @var array<int, array{field: mixed, condition: mixed}> */
    private array $attributeFilters = [];

    /** @var array<int, int[]> */
    private array $idFilters = [];

    protected function setUp(): void
    {
        $this->fieldFilters = [];
        $this->attributeFilters = [];
        $this->idFilters = [];
    }

    public function testTheSimpleCollectionIsRestrictedToSimpleProducts(): void
    {
        $this->collection()->forSimple();

        self::assertSame(
            [['field' => 'type_id', 'condition' => ProductType::TYPE_SIMPLE]],
            $this->fieldFilters
        );
    }

    public function testTheConfigurableCollectionIsRestrictedToConfigurables(): void
    {
        $this->collection()->forConfigurable();

        self::assertSame(
            [['field' => 'type_id', 'condition' => ConfigurableType::TYPE_CODE]],
            $this->fieldFilters
        );
    }

    /**
     * Warming a disabled product spends real time populating a cache entry no
     * shopper will ever request.
     */
    public function testOnlyEnabledProductsAreWarmed(): void
    {
        $this->collection()->forSimple();

        self::assertContains(
            ['field' => 'status', 'condition' => Status::STATUS_ENABLED],
            $this->attributeFilters
        );
    }

    /**
     * Not-visible-individually children are covered by the parent's warm.
     */
    public function testChildVariantsAreLeftToTheirParentsWarm(): void
    {
        $this->collection()->forSimple();

        self::assertContains(
            ['field' => 'visibility', 'condition' => ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]],
            $this->attributeFilters
        );
    }

    /**
     * A named product list is filtered by the same rules, so a since-disabled
     * product is skipped.
     */
    public function testANamedSetIsStillFilteredToWhatIsWorthWarming(): void
    {
        $this->collection()->forIds(ProductType::TYPE_SIMPLE, [10, 11]);

        self::assertSame([[10, 11]], $this->idFilters);
        self::assertContains(
            ['field' => 'status', 'condition' => Status::STATUS_ENABLED],
            $this->attributeFilters
        );
    }

    private function collection(): ActiveProductCollection
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            function (): Collection {
                $collection = $this->createMock(Collection::class);
                $collection->method('addFieldToFilter')->willReturnCallback(
                    function ($field, $condition = null) use (&$collection) {
                        $this->fieldFilters[] = ['field' => $field, 'condition' => $condition];

                        return $collection;
                    }
                );
                $collection->method('addAttributeToFilter')->willReturnCallback(
                    function ($attribute, $condition = null, $joinType = 'inner') use (&$collection) {
                        $this->attributeFilters[] = ['field' => $attribute, 'condition' => $condition];

                        return $collection;
                    }
                );
                $collection->method('addIdFilter')->willReturnCallback(
                    function ($ids, $exclude = false) use (&$collection) {
                        $this->idFilters[] = array_map('intval', (array) $ids);

                        return $collection;
                    }
                );

                return $collection;
            }
        );

        return new ActiveProductCollection($factory);
    }
}
