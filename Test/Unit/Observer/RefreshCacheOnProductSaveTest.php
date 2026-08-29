<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Observer;

use Commerce\CacheTools\Model\Product\ProductSaveCacheRefresher;
use Commerce\CacheTools\Observer\RefreshCacheOnProductSave;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RefreshCacheOnProductSaveTest extends TestCase
{
    /** @var string[] */
    private array $refreshed = [];

    private ?\Throwable $refreshFailure = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->refreshed = [];
        $this->refreshFailure = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testASavedProductIsPassedToTheRefresher(): void
    {
        $this->observer()->execute($this->event($this->product('SKU-1')));

        $this->assertSame(['SKU-1'], $this->refreshed);
    }

    /**
     * The event carries whatever dispatched it; a missing product must not
     * become a type error inside a product save.
     */
    public function testAnEventWithoutAProductIsIgnored(): void
    {
        $this->logger->expects($this->never())->method('error');

        $observer = $this->observer();

        $observer->execute(new Observer(['event' => new Event([])]));
        $observer->execute(new Observer(['event' => new Event(['product' => 'SKU-1'])]));

        $this->assertSame([], $this->refreshed);
    }

    /**
     * The save has already committed, so cache housekeeping that throws must
     * not fail it.
     */
    public function testAFailureNeverFailsTheSaveThatTriggeredIt(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('SKU-1'));

        $this->refreshFailure = new RuntimeException('redis is down');

        $this->observer()->execute($this->event($this->product('SKU-1')));
    }

    private function event(ProductInterface $product): Observer
    {
        return new Observer(['event' => new Event(['product' => $product])]);
    }

    private function observer(): RefreshCacheOnProductSave
    {
        $refresher = $this->createMock(ProductSaveCacheRefresher::class);
        $refresher->method('refresh')->willReturnCallback(
            function (ProductInterface $product): void {
                if ($this->refreshFailure !== null) {
                    throw $this->refreshFailure;
                }

                $this->refreshed[] = (string) $product->getSku();
            }
        );

        return new RefreshCacheOnProductSave($refresher, $this->logger);
    }

    private function product(string $sku): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
