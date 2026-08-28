<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\Task\ProductCacheWarmer;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Commerce\Foundation\Api\ProductImageUrlResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Catalog\Model\Product\Type as ProductType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductCacheWarmerTest extends TestCase
{
    /** @var array<int, array{sku: string, imageType: string}> */
    private array $resolved = [];

    /** @var string[] */
    private array $swatched = [];

    /** @var array<int, array{type: string, ids: int[]}> */
    private array $collectionsBuilt = [];

    /** @var string[][] */
    private array $selectedAttributes = [];

    /** @var array<string, mixed> */
    private array $flags = [];

    /** @var string[] SKUs whose warm throws. */
    private array $failing = [];

    /** @var ProductInterface[] */
    private array $items = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->resolved = [];
        $this->swatched = [];
        $this->collectionsBuilt = [];
        $this->selectedAttributes = [];
        $this->flags = [];
        $this->failing = [];
        $this->logger = new RecordingLogger();
        $this->items = [];
    }

    public function testItSatisfiesTheWarmTaskContract(): void
    {
        $this->assertInstanceOf(WarmTaskInterface::class, $this->warmer());
    }

    public function testEveryImageRoleIsResolvedForEveryProduct(): void
    {
        $this->items = [$this->product('SKU-1'), $this->product('SKU-2')];

        $result = $this->warmer(imageTypes: [
            ProductImageUrlResolverInterface::IMAGE_SMALL,
            ProductImageUrlResolverInterface::IMAGE_LARGE,
        ])->warm([10, 11]);

        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->warmed);
        $this->assertCount(4, $this->resolved);
    }

    /**
     * One class serves both types; which products it loads is a di.xml
     * argument.
     */
    public function testTheProductTypeIsConfiguration(): void
    {
        $this->warmer(productType: ConfigurableType::TYPE_CODE)->warm([10]);

        $this->assertSame(ConfigurableType::TYPE_CODE, $this->collectionsBuilt[0]['type']);
    }

    /**
     * The swatch cache applies to configurables only, and warming it for a
     * simple product renders a payload that does not exist.
     */
    public function testTheSwatchCacheIsWarmedOnlyWhereItApplies(): void
    {
        $this->items = [$this->product('SKU-1')];

        $this->warmer(warmSwatches: false)->warm([10]);
        $this->assertSame([], $this->swatched);

        $this->warmer(warmSwatches: true)->warm([10]);
        $this->assertSame(['SKU-1'], $this->swatched);
    }

    /**
     * The collection is loaded in full, so the attribute list is what keeps a
     * warm run from loading every EAV value for every product.
     */
    public function testOnlyTheConfiguredAttributesAreSelected(): void
    {
        $this->warmer(loadAttributes: ['image', 'small_image'])->warm([10]);

        $this->assertSame([['image', 'small_image']], $this->selectedAttributes);
    }

    public function testNoAttributesAreSelectedWhenNoneAreConfigured(): void
    {
        $this->warmer()->warm([10]);

        $this->assertSame([], $this->selectedAttributes);
    }

    /**
     * The stock-status filter is implied by the ids, and re-applying it joins
     * the stock index again.
     */
    public function testTheStockIndexIsNotJoinedASecondTime(): void
    {
        $this->warmer()->warm([10]);

        $this->assertTrue($this->flags['has_stock_status_filter']);
    }

    /**
     * One unwarmable product must not abandon the rest of the batch: a single
     * corrupted media row would otherwise cost the run 499 warms.
     */
    public function testOneFailingProductDoesNotAbandonTheBatch(): void
    {
        $this->items = [$this->product('SKU-1'), $this->product('SKU-2'), $this->product('SKU-3')];
        $this->failing = ['SKU-2'];

        $result = $this->warmer()->warm([10, 11, 12]);

        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->warmed);
        $this->assertSame(1, $result->getFailed());
    }

    /**
     * The failure travels back with the result as well as going to the log.
     */
    public function testAFailureIsReportedInTheResultAndTheLog(): void
    {
        $this->items = [$this->product('SKU-1')];
        $this->failing = ['SKU-1'];

        $result = $this->warmer()->warm([10]);

        $this->assertCount(1, $result->messages);
        $this->assertStringContainsString('SKU-1', $result->messages[0]);
        $this->assertCount(1, $this->logger->errors);
    }

    /**
     * A batch whose products have all gone warms nothing, and that is not a
     * failure.
     */
    public function testABatchThatLoadsNothingIsNotAFailure(): void
    {
        $result = $this->warmer()->warm([10, 11]);

        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->getFailed());
        $this->assertSame([], $result->messages);
    }

    /**
     * @param string[] $imageTypes
     * @param string[] $loadAttributes
     */
    private function warmer(
        string $productType = ProductType::TYPE_SIMPLE,
        array $imageTypes = [ProductImageUrlResolverInterface::IMAGE_LARGE],
        bool $warmSwatches = false,
        array $loadAttributes = []
    ): ProductCacheWarmer {
        $activeProducts = $this->createMock(ActiveProductCollection::class);
        $activeProducts->method('forIds')->willReturnCallback(
            function (string $typeId, array $productIds): Collection {
                $this->collectionsBuilt[] = ['type' => $typeId, 'ids' => $productIds];

                $collection = $this->createMock(Collection::class);
                $collection->method('addAttributeToSelect')->willReturnCallback(
                    function ($attribute, $joinType = false) use (&$collection) {
                        $this->selectedAttributes[] = (array) $attribute;

                        return $collection;
                    }
                );
                $collection->method('setFlag')->willReturnCallback(
                    function (string $key, $value = null) use (&$collection) {
                        $this->flags[$key] = $value;

                        return $collection;
                    }
                );
                $collection->method('getItems')->willReturnCallback(fn (): array => $this->items);

                return $collection;
            }
        );

        $resolver = $this->createMock(ProductImageUrlResolverInterface::class);
        $resolver->method('resolveByProduct')->willReturnCallback(
            function (ProductInterface $product, string $imageType = ''): ?string {
                $sku = (string) $product->getSku();

                if (in_array($sku, $this->failing, true)) {
                    throw new RuntimeException('media row is corrupt');
                }

                $this->resolved[] = ['sku' => $sku, 'imageType' => $imageType];

                return 'https://cdn.test/' . $sku . '.jpg';
            }
        );

        $swatchWarmer = $this->createMock(SwatchCacheWarmerInterface::class);
        $swatchWarmer->method('warm')->willReturnCallback(
            function (ProductInterface $product): bool {
                $this->swatched[] = (string) $product->getSku();

                return true;
            }
        );

        return new ProductCacheWarmer(
            $activeProducts,
            $resolver,
            $swatchWarmer,
            $this->logger,
            $productType,
            $imageTypes,
            $warmSwatches,
            $loadAttributes
        );
    }

    private function product(string $sku): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
