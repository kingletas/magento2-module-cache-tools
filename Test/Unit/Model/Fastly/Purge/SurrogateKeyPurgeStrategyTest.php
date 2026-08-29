<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Fastly\Purge\SurrogateKeyPurgeStrategy;
use Commerce\CacheTools\Model\Fastly\PurgeResult;
use Commerce\CacheTools\Model\Fastly\Purger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SurrogateKeyPurgeStrategyTest extends TestCase
{
    /** @var string[] */
    private array $purgedKeys = [];

    private bool $purgeSucceeds = true;

    protected function setUp(): void
    {
        $this->purgedKeys = [];
        $this->purgeSucceeds = true;
    }

    public function testItIsOneStrategyAmongOthers(): void
    {
        $this->assertInstanceOf(PurgeStrategyInterface::class, $this->strategy());
    }

    /**
     * One request evicts every page carrying the key, listings and search
     * results included.
     */
    public function testTheProductsOwnCacheTagIsPurged(): void
    {
        $this->assertSame(1, $this->strategy()->purgeForProduct($this->product(10)));
        $this->assertSame([Product::CACHE_TAG . '_10'], $this->purgedKeys);
    }

    /**
     * A configured prefix keeps one store off another's pages on a shared
     * service.
     */
    public function testAConfiguredPrefixIsApplied(): void
    {
        $this->strategy('acme_')->purgeForProduct($this->product(10));

        $this->assertSame(['acme_' . Product::CACHE_TAG . '_10'], $this->purgedKeys);
    }

    /**
     * An unsaved product has no id, and `cat_p_0` is a key nothing carries - so
     * the request would be spent for nothing.
     */
    public function testAProductWithNoIdIsNotPurged(): void
    {
        $this->assertSame(0, $this->strategy()->purgeForProduct($this->product(0)));
        $this->assertSame([], $this->purgedKeys);
    }

    /**
     * The count is what the caller reports, so a refused purge must not be
     * counted as a success.
     */
    public function testARefusedPurgeCountsAsNothingPurged(): void
    {
        $this->purgeSucceeds = false;

        $this->assertSame(0, $this->strategy()->purgeForProduct($this->product(10)));
    }

    private function strategy(string $prefix = ''): SurrogateKeyPurgeStrategy
    {
        $purger = $this->createMock(Purger::class);
        $purger->method('purgeKey')->willReturnCallback(
            function (string $key): PurgeResult {
                $this->purgedKeys[] = $key;

                return new PurgeResult($key, $this->purgeSucceeds, __("Purged."));
            }
        );

        return new SurrogateKeyPurgeStrategy($purger, $prefix);
    }

    private function product(int $id): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
