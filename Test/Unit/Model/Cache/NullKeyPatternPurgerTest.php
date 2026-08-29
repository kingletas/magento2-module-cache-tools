<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Model\Cache\NullKeyPatternPurger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NullKeyPatternPurgerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testItIsTheFallbackBindingForThePurgerContract(): void
    {
        $this->assertInstanceOf(KeyPatternPurgerInterface::class, $this->purger());
    }

    public function testNothingIsPurgedAndTheCountSaysSo(): void
    {
        $this->assertSame(0, $this->purger()->purgeBySkus(['SKU-1', 'SKU-2']));
    }

    public function testItReportsThatPatternPurgingIsUnavailable(): void
    {
        $this->assertFalse($this->purger()->isSupported());
    }

    /**
     * Silence would be worse: a no-op purge looks exactly like a working one
     * until a shopper sees a stale swatch.
     */
    public function testTheFirstRealPurgeWarnsThatNothingIsBeingInvalidated(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('swatch'));

        $this->purger()->purgeBySkus(['SKU-1']);
    }

    /**
     * A product save calls this once per save.
     */
    public function testTheWarningIsEmittedOnlyOncePerProcess(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $purger = $this->purger();

        $purger->purgeBySkus(['SKU-1']);
        $purger->purgeBySkus(['SKU-2']);
    }

    /**
     * An empty batch had nothing to invalidate and is not warned about.
     */
    public function testAnEmptyBatchDoesNotWarn(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $this->assertSame(0, $this->purger()->purgeBySkus([]));
    }

    private function purger(): NullKeyPatternPurger
    {
        return new NullKeyPatternPurger($this->logger);
    }
}
