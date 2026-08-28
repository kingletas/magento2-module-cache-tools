<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Cron;

use Commerce\CacheTools\Cron\ReapStaleWarmRuns;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReapStaleWarmRunsTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testTheScheduledSweepReapsAbandonedRuns(): void
    {
        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->expects($this->once())->method('reap')->willReturn(2);

        (new ReapStaleWarmRuns($reaper, $this->logger))->execute();
    }

    /**
     * Housekeeping is noted rather than thrown, so a locked table is not a
     * repeating cron failure.
     */
    public function testAFailedSweepIsLoggedRatherThanThrownAtCron(): void
    {
        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->method('reap')->willThrowException(new RuntimeException('lock wait timeout'));

        (new ReapStaleWarmRuns($reaper, $this->logger))->execute();

        $this->assertCount(1, $this->logger->errors);
        $this->assertStringContainsString('reaping stale warm runs', $this->logger->errors[0]);
    }

    public function testASuccessfulSweepSaysNothing(): void
    {
        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->method('reap')->willReturn(0);

        (new ReapStaleWarmRuns($reaper, $this->logger))->execute();

        $this->assertSame([], $this->logger->errors);
    }
}
