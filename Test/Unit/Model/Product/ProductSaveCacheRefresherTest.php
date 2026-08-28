<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Product;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\Purge\PurgeStrategyPool;
use Commerce\CacheTools\Model\Product\ProductSaveCacheRefresher;
use Commerce\Foundation\Api\ConfigurableParentSkuResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ProductSaveCacheRefresherTest extends TestCase
{
    /** @var int[] */
    private array $edgePurges = [];

    /** @var array<int, string[]> */
    private array $keyPurges = [];

    private bool $fastlyEnabled = true;
    private bool $patternPurgeSupported = true;
    private ?string $parentSku = null;
    private ?\Throwable $edgeFailure = null;
    private ?\Throwable $parentFailure = null;
    private ?\Throwable $keyFailure = null;
    private ?PurgeStrategyInterface $strategy = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->edgePurges = [];
        $this->keyPurges = [];
        $this->fastlyEnabled = true;
        $this->patternPurgeSupported = true;
        $this->parentSku = null;
        $this->edgeFailure = null;
        $this->parentFailure = null;
        $this->keyFailure = null;
        $this->strategy = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testTheSavedProductIsPurgedFromTheEdgeAndTheKeyedCaches(): void
    {
        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([10], $this->edgePurges);
        $this->assertSame([['SKU-1']], $this->keyPurges);
    }

    /**
     * The parent's options payload embeds the child's availability, so the
     * parent is purged too.
     */
    public function testTheConfigurableParentIsPurgedAlongsideTheChild(): void
    {
        $this->parentSku = 'PARENT-1';

        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([['SKU-1', 'PARENT-1']], $this->keyPurges);
    }

    /**
     * The three layers are independent, so a failure in one does not skip the
     * others.
     */
    public function testAFailedEdgePurgeStillLeavesTheKeyedCachesPurged(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->edgeFailure = new RuntimeException('Fastly returned 503');

        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([['SKU-1']], $this->keyPurges);
    }

    /**
     * Purge what we can rather than nothing: a link table that will not answer
     * still leaves the saved SKU's own entries worth clearing.
     */
    public function testAFailedParentLookupStillPurgesTheSavedSku(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $this->parentFailure = new RuntimeException('link table is locked');

        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([['SKU-1']], $this->keyPurges);
    }

    public function testAFailedKeyPurgeIsLoggedRatherThanThrown(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->keyFailure = new RuntimeException('redis is down');

        $this->refresher()->refresh($this->product('SKU-1'));
    }

    /**
     * A store not using Fastly has no edge to purge, and asking the strategy
     * pool would resolve a client it cannot authenticate.
     */
    public function testNoEdgePurgeHappensWhileFastlyIsDisabled(): void
    {
        $this->fastlyEnabled = false;

        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([], $this->edgePurges);
        $this->assertSame([['SKU-1']], $this->keyPurges);
    }

    /**
     * A cache backend that cannot match by pattern has nothing to purge, and
     * the null purger would only warn.
     */
    public function testNoKeyPurgeHappensWhenTheBackendCannotMatchPatterns(): void
    {
        $this->patternPurgeSupported = false;

        $this->refresher()->refresh($this->product('SKU-1'));

        $this->assertSame([], $this->keyPurges);
        $this->assertSame([10], $this->edgePurges);
    }

    /**
     * A store with no purge strategy configured has an empty pool, and the
     * saved product's keyed entries still need clearing.
     */
    public function testAnEmptyStrategyPoolIsNotAFailure(): void
    {
        $this->logger->expects($this->never())->method('error');

        $this->strategy = null;

        $this->refresher(withStrategy: false)->refresh($this->product('SKU-1'));

        $this->assertSame([['SKU-1']], $this->keyPurges);
    }

    /**
     * A product with no SKU is one that failed to save; there is nothing to key
     * a purge on.
     */
    public function testAProductWithNoSkuIsLeftAlone(): void
    {
        $this->refresher()->refresh($this->product(''));

        $this->assertSame([], $this->edgePurges);
        $this->assertSame([], $this->keyPurges);
    }

    private function refresher(bool $withStrategy = true): ProductSaveCacheRefresher
    {
        if ($withStrategy) {
            $strategy = $this->createMock(PurgeStrategyInterface::class);
            $strategy->method('purgeForProduct')->willReturnCallback(
                function (ProductInterface $product): int {
                    if ($this->edgeFailure !== null) {
                        throw $this->edgeFailure;
                    }

                    $this->edgePurges[] = (int) $product->getId();

                    return 1;
                }
            );
            $this->strategy = $strategy;
        }

        $pool = $this->createMock(PurgeStrategyPool::class);
        $pool->method('get')->willReturnCallback(fn (): ?PurgeStrategyInterface => $this->strategy);

        $keyPurger = $this->createMock(KeyPatternPurgerInterface::class);
        $keyPurger->method('isSupported')->willReturnCallback(fn (): bool => $this->patternPurgeSupported);
        $keyPurger->method('purgeBySkus')->willReturnCallback(
            function (array $skus): int {
                if ($this->keyFailure !== null) {
                    throw $this->keyFailure;
                }

                $this->keyPurges[] = $skus;

                return count($skus);
            }
        );

        $parentResolver = $this->createMock(ConfigurableParentSkuResolverInterface::class);
        $parentResolver->method('resolve')->willReturnCallback(
            function (): ?string {
                if ($this->parentFailure !== null) {
                    throw $this->parentFailure;
                }

                return $this->parentSku;
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('isFastlyEnabled')->willReturnCallback(fn (): bool => $this->fastlyEnabled);

        return new ProductSaveCacheRefresher($pool, $keyPurger, $parentResolver, $config, $this->logger);
    }

    private function product(string $sku): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
