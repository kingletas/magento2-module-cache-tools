<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Run;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StaleRunReaperTest extends TestCase
{
    /** @var array<int, array{noProgressSince: string, finishedAt: string}> */
    private array $sweeps = [];

    /** @var array<int, int> Timestamps the cutoff was formatted from. */
    private array $cutoffTimestamps = [];

    private int $reaped = 2;
    private int $staleHours = 6;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->sweeps = [];
        $this->cutoffTimestamps = [];
        $this->reaped = 2;
        $this->staleHours = 6;
        $this->logger = new RecordingLogger();
    }

    /**
     * A lost message would leave the row running for good and block every
     * future run of its type.
     */
    public function testAbandonedRunsAreClosed(): void
    {
        self::assertSame(2, $this->reaper()->reap());
        self::assertCount(1, $this->sweeps);
    }

    /**
     * Measured from the last progress update, so warming a large catalogue over
     * several hours is never killed mid-flight.
     */
    public function testTheCutoffIsTheConfiguredThresholdBackFromNow(): void
    {
        $before = time();

        $this->reaper()->reap();

        self::assertEqualsWithDelta($before - 6 * 3600, $this->cutoffTimestamps[0], 5);
    }

    public function testTheThresholdIsConfigurable(): void
    {
        $this->staleHours = 24;
        $before = time();

        $this->reaper()->reap();

        self::assertEqualsWithDelta($before - 24 * 3600, $this->cutoffTimestamps[0], 5);
    }

    /**
     * A reaped run is a lost message or a killed consumer - something an
     * operator should know happened, so it is a warning rather than an info.
     */
    public function testAReapedRunIsWarnedAboutWithItsCountAndThreshold(): void
    {
        $this->reaper()->reap();

        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('2 warm run(s)', $this->logger->warnings[0]);
        self::assertStringContainsString('6 hour threshold', $this->logger->warnings[0]);
    }

    /**
     * The sweep runs on a schedule and usually finds nothing; a line per run
     * would bury the runs that did reap something.
     */
    public function testASweepThatFoundNothingSaysNothing(): void
    {
        $this->reaped = 0;

        self::assertSame(0, $this->reaper()->reap());
        self::assertSame([], $this->logger->warnings);
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
