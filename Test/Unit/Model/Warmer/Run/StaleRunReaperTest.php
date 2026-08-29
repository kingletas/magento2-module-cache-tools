<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Run;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StaleRunReaperTest extends TestCase
{
    /** @var array<int, array{noProgressSince: string, finishedAt: string}> */
    private array $sweeps = [];

    /** @var array<int, int> Timestamps the cutoff was formatted from. */
    private array $cutoffTimestamps = [];

    private int $reaped = 2;
    private int $staleHours = 6;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->sweeps = [];
        $this->cutoffTimestamps = [];
        $this->reaped = 2;
        $this->staleHours = 6;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * A lost message would leave the row running for good and block every
     * future run of its type.
     */
    public function testAbandonedRunsAreClosed(): void
    {
        $this->assertSame(2, $this->reaper()->reap());
        $this->assertCount(1, $this->sweeps);
    }

    /**
     * Measured from the last progress update, so warming a large catalogue over
     * several hours is never killed mid-flight.
     */
    public function testTheCutoffIsTheConfiguredThresholdBackFromNow(): void
    {
        $before = time();

        $this->reaper()->reap();

        $this->assertEqualsWithDelta($before - 6 * 3600, $this->cutoffTimestamps[0], 5);
    }

    public function testTheThresholdIsConfigurable(): void
    {
        $this->staleHours = 24;
        $before = time();

        $this->reaper()->reap();

        $this->assertEqualsWithDelta($before - 24 * 3600, $this->cutoffTimestamps[0], 5);
    }

    /**
     * A reaped run is a lost message or a killed consumer - something an
     * operator should know happened, so it is a warning rather than an info.
     */
    public function testAReapedRunIsWarnedAboutWithItsCountAndThreshold(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(
                static fn (string $message): bool=> str_contains($message, '2 warm run(s)')
                    && str_contains($message, '6 hour threshold')
            ));

        $this->reaper()->reap();
    }

    /**
     * The sweep runs on a schedule and usually finds nothing; a line per run
     * would bury the runs that did reap something.
     */
    public function testASweepThatFoundNothingSaysNothing(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $this->reaped = 0;

        $this->assertSame(0, $this->reaper()->reap());
    }

    private function reaper(): StaleRunReaper
    {
        $resource = $this->createMock(WarmRunResource::class);
        $resource->method('markStaleRuns')->willReturnCallback(
            function (string $noProgressSince, string $finishedAt): int {
                $this->sweeps[] = ['noProgressSince' => $noProgressSince, 'finishedAt' => $finishedAt];

                return $this->reaped;
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('getStaleRunHours')->willReturnCallback(fn (): int => $this->staleHours);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            function ($format = 'Y-m-d H:i:s', $input = null): string {
                if ($input !== null) {
                    $this->cutoffTimestamps[] = (int) $input;
                }

                return gmdate((string) ($format ?? 'Y-m-d H:i:s'), $input === null ? time() : (int) $input);
            }
        );

        return new StaleRunReaper($resource, $config, $dateTime, $this->logger);
    }
}
