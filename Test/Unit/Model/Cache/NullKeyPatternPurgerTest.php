<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Model\Cache\NullKeyPatternPurger;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

class NullKeyPatternPurgerTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
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
        $this->purger()->purgeBySkus(['SKU-1']);

        $this->assertCount(1, $this->logger->warnings);
        $this->assertStringContainsString('swatch', $this->logger->warnings[0]);
    }

    /**
     * A product save calls this once per save.
     */
    public function testTheWarningIsEmittedOnlyOncePerProcess(): void
    {
        $purger = $this->purger();

        $purger->purgeBySkus(['SKU-1']);
        $purger->purgeBySkus(['SKU-2']);

        $this->assertCount(1, $this->logger->warnings);
    }

    /**
     * An empty batch had nothing to invalidate and is not warned about.
     */
    public function testAnEmptyBatchDoesNotWarn(): void
    {
        $this->assertSame(0, $this->purger()->purgeBySkus([]));
        $this->assertSame([], $this->logger->warnings);
    }

    private function purger(): NullKeyPatternPurger
    {
        return new NullKeyPatternPurger($this->logger);
    }
}
