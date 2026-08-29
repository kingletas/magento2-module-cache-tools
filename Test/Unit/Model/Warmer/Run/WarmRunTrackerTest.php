<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Run;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\ResourceModel\WarmRun\Collection;
use Commerce\CacheTools\Model\ResourceModel\WarmRun\CollectionFactory;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunFactory;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WarmRunTrackerTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    /** @var array<int, array<string, mixed>> Rows the resource was asked to save. */
    private array $saved = [];

    /** @var array<int, array{runId: int, processed: int, failed: int}> */
    private array $increments = [];

    /** @var array<int, array{field: string, condition: mixed}> */
    private array $filters = [];

    /** @var array<int, array{field: string, direction: string}> */
    private array $orders = [];

    /** @var WarmRun[] */
    private array $openRuns = [];

    private bool $hasOpen = true;
    private bool $completes = true;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->saved = [];
        $this->increments = [];
        $this->filters = [];
        $this->orders = [];
        $this->openRuns = [];
        $this->hasOpen = true;
        $this->completes = true;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Starting a second run of the same type interleaves its batches with the
     * first and leaves the older row stuck at "running" for good.
     */
    public function testAnOpenRunOfTheSameTypeIsReportedFromTheResource(): void
    {
        $this->assertTrue($this->tracker()->hasOpenRun('product'));

        $this->hasOpen = false;

        $this->assertFalse($this->tracker()->hasOpenRun('product'));
    }

    public function testOpeningARunRecordsItsTypeTotalAndStartTime(): void
    {
        $this->tracker()->open('product', 500);

        $row = $this->saved[0];
        $this->assertSame('product', $row[WarmRunInterface::WARM_TYPE]);
        $this->assertSame(WarmRunInterface::STATUS_RUNNING, $row[WarmRunInterface::STATUS]);
        $this->assertSame(500, $row[WarmRunInterface::TOTAL_PRODUCTS]);
        $this->assertSame(self::NOW, $row[WarmRunInterface::STARTED_AT]);
    }

    /**
     * The counters start at zero rather than unset: they are incremented by the
     * database, and `NULL + 1` is NULL.
     */
    public function testTheCountersStartAtZeroRatherThanUnset(): void
    {
        $this->tracker()->open('product', 500);

        $this->assertSame(0, $this->saved[0][WarmRunInterface::PROCESSED_PRODUCTS]);
        $this->assertSame(0, $this->saved[0][WarmRunInterface::FAILED_PRODUCTS]);
    }

    /**
     * The consumer needs the id to report its batches against, and a run nobody
     * can address never completes.
     */
    public function testTheNewRunsIdIsReturned(): void
    {
        $this->assertSame(7, $this->tracker()->open('product', 500));
    }

    /**
     * A warm run is a background job nobody watches; the log is how an operator
     * finds out one started.
     */
    public function testOpeningARunIsRecordedInTheLog(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(
                static fn (string $message): bool => str_contains($message, '#7') && str_contains($message, 'product')
            ));

        $this->tracker()->open('product', 500);
    }

    /**
     * Every mutation is one atomic statement, because concurrent consumers
     * advance the same run.
     */
    public function testProgressIsDelegatedToTheAtomicUpdate(): void
    {
        $this->tracker()->incrementProgress(7, 10, 2);

        $this->assertSame([['runId' => 7, 'processed' => 10, 'failed' => 2]], $this->increments);
    }

    public function testCompletionIsDelegatedAndReported(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('#7 completed'));

        $this->assertTrue($this->tracker()->completeIfDone(7));
    }

    /**
     * Only the call that actually closed the run announces it, so a run does
     * not appear to complete once per consumer that finished a batch.
     */
    public function testARunThatWasNotClosedByThisCallSaysNothing(): void
    {
        $this->logger->expects($this->never())->method('info');

        $this->completes = false;

        $this->assertFalse($this->tracker()->completeIfDone(7));
    }

    public function testOpenRunsAreListedOldestFirst(): void
    {
        $this->openRuns = [$this->runWithId(1), $this->runWithId(2)];

        $runs = $this->tracker()->getOpenRuns();

        $this->assertCount(2, $runs);
        $this->assertSame(
            [['field' => WarmRunInterface::RUN_ID, 'direction' => 'ASC']],
            $this->orders
        );
    }

    public function testOnlyRunningRunsAreListed(): void
    {
        $this->tracker()->getOpenRuns();

        $this->assertSame(
            [['field' => WarmRunInterface::STATUS, 'condition' => WarmRunInterface::STATUS_RUNNING]],
            $this->filters
        );
    }

    public function testAnInstallWithNoOpenRunsListsNothing(): void
    {
        $this->assertSame([], $this->tracker()->getOpenRuns());
    }

    private function runWithId(int $id): WarmRun
    {
        $run = $this->entity();
        $run->setData(WarmRunInterface::RUN_ID, $id);

        return $run;
    }

    private function entity(): WarmRun
    {
        $resource = $this->createMock(WarmRunResource::class);
        $resource->method('getIdFieldName')->willReturn(WarmRunInterface::RUN_ID);

        return new WarmRun(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $resource
        );
    }

    private function tracker(): WarmRunTracker
    {
        $runFactory = $this->createMock(WarmRunFactory::class);
        $runFactory->method('create')->willReturnCallback(fn (): WarmRun => $this->entity());

        $resource = $this->createMock(WarmRunResource::class);
        $resource->method('hasOpenRun')->willReturnCallback(fn (): bool => $this->hasOpen);
        $resource->method('save')->willReturnCallback(
            function ($run) use (&$resource) {
                $this->saved[] = $run->getData();
                $run->setId(7);

                return $resource;
            }
        );
        $resource->method('incrementProgress')->willReturnCallback(
            function (int $runId, int $processed, int $failed): int {
                $this->increments[] = ['runId' => $runId, 'processed' => $processed, 'failed' => $failed];

                return 1;
            }
        );
        $resource->method('completeIfDone')->willReturnCallback(fn (): bool => $this->completes);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(
            function (): Collection {
                $collection = $this->createMock(Collection::class);
                $collection->method('addFieldToFilter')->willReturnCallback(
                    function ($field, $condition = null) use (&$collection) {
                        $this->filters[] = ['field' => (string) $field, 'condition' => $condition];

                        return $collection;
                    }
                );
                $collection->method('setOrder')->willReturnCallback(
                    function ($field, $direction = 'DESC') use (&$collection) {
                        $this->orders[] = ['field' => (string) $field, 'direction' => (string) $direction];

                        return $collection;
                    }
                );
                $collection->method('getItems')->willReturnCallback(fn (): array => $this->openRuns);

                return $collection;
            }
        );

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        return new WarmRunTracker($runFactory, $resource, $collectionFactory, $dateTime, $this->logger);
    }
}
