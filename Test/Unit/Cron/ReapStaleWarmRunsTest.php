<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Cron;

use Commerce\CacheTools\Cron\ReapStaleWarmRuns;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ReapStaleWarmRunsTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
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
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('reaping stale warm runs'));

        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->method('reap')->willThrowException(new RuntimeException('lock wait timeout'));

        (new ReapStaleWarmRuns($reaper, $this->logger))->execute();
    }

    public function testASuccessfulSweepSaysNothing(): void
    {
        $this->logger->expects($this->never())->method('error');

        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->method('reap')->willReturn(0);

        (new ReapStaleWarmRuns($reaper, $this->logger))->execute();
    }
}
