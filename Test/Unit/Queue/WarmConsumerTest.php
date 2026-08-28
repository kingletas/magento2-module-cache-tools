<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Queue;

use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Commerce\CacheTools\Model\Warmer\WarmResult;
use Commerce\CacheTools\Queue\WarmConsumer;
use Commerce\CacheTools\Test\Support\PassthroughLock;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WarmConsumerTest extends TestCase
{
    /** @var array<int, array{warmer: string, ids: int[]}> */
    private array $warmed = [];

    /** @var array<int, array{runId: int, processed: int, failed: int}> */
    private array $progress = [];

    /** @var int[] */
    private array $completions = [];

    /** @var string[] */
    private array $areaCalls = [];

    private WarmResult $result;
    private ?\Throwable $warmFailure = null;
    private bool $areaAlreadySet = false;
    private PassthroughLock $lock;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->warmed = [];
        $this->progress = [];
        $this->completions = [];
        $this->areaCalls = [];
        $this->result = new WarmResult(2, 2);
        $this->warmFailure = null;
        $this->areaAlreadySet = false;
        $this->lock = new PassthroughLock();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testABatchIsWarmedAndItsProgressRecorded(): void
    {
        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]));

        $this->assertSame([['warmer' => 'simple', 'ids' => [10, 11]]], $this->warmed);
        $this->assertSame([['runId' => 7, 'processed' => 2, 'failed' => 0]], $this->progress);
        $this->assertSame([7], $this->completions);
    }

    public function testEachTypeGoesToItsOwnWarmer(): void
    {
        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_CONFIGURABLE, [10]));

        $this->assertSame('configurable', $this->warmed[0]['warmer']);
    }

    /**
     * Consumers run headless, and an admin-area URL populates a cache entry no
     * shopper hits.
     */
    public function testTheFrontendAreaIsForcedBeforeAnythingIsWarmed(): void
    {
        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10]));

        $this->assertSame(['get', 'set:' . Area::AREA_FRONTEND], $this->areaCalls);
    }

    public function testAnAreaThatIsAlreadySetIsLeftAlone(): void
    {
        $this->areaAlreadySet = true;

        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10]));

        $this->assertSame(['get'], $this->areaCalls);
    }

    /**
     * Keyed on the message content, so a redelivery of the *same* batch is
     * skipped while a different batch of the same run proceeds.
     */
    public function testTheLockIsKeyedOnTheBatchRatherThanTheRun(): void
    {
        $consumer = $this->consumer();
        $consumer->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]));
        $consumer->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [12, 13]));

        $this->assertCount(2, $this->lock->taken);
        $this->assertNotSame($this->lock->taken[0], $this->lock->taken[1]);
        $this->assertStringContainsString('7', $this->lock->taken[0]);
    }

    /**
     * A skipped batch is not counted against the run, so only the reaper will
     * close it.
     */
    public function testASkippedBatchWarnsThatTheRunWillNotReachItsTotal(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('will not reach its total'));

        $message = $this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]);
        $consumer = $this->consumer();

        $this->lock->held = [
            sprintf('commerce_cachetools_warm_consume_%d_%s', 7, hash('xxh128', $message)),
        ];

        $consumer->process($message);

        $this->assertSame([], $this->warmed);
    }

    /**
     * Counted as processed-and-failed regardless: without this the run can
     * never reach its total and stays open until the reaper closes it.
     */
    public function testABatchThatFailsEntirelyIsStillCountedAgainstTheRun(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->warmFailure = new RuntimeException('collection blew up');

        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]));

        $this->assertSame([['runId' => 7, 'processed' => 2, 'failed' => 2]], $this->progress);
    }

    /**
     * Never rethrown: a message that fails deterministically would return to
     * the queue and loop forever, blocking the run behind it.
     */
    public function testAFailedBatchIsNotRethrownAtTheConsumer(): void
    {
        $this->warmFailure = new RuntimeException('collection blew up');

        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10]));

        $this->assertSame([7], $this->completions);
    }

    public function testPerProductFailuresAreReportedAgainstTheRun(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('SKU-2'));

        $this->result = new WarmResult(2, 1, ['Failed warming simple product SKU-2: media row is corrupt']);

        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]));

        $this->assertSame([['runId' => 7, 'processed' => 2, 'failed' => 1]], $this->progress);
    }

    public function testEveryBatchLeavesATraceOfWhatItWarmed(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('warmed 2 of 2'));

        $this->consumer()->process($this->message(7, BatchQueuer::TYPE_SIMPLE, [10, 11]));
    }

    /**
     * A malformed message is discarded with a warning rather than requeued to
     * fail forever.
     */
    public function testAnUnparseableMessageIsDiscardedWithAWarning(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $this->consumer()->process('{not json');

        $this->assertSame([], $this->warmed);
    }

    public function testAMessageMissingAnyOfItsPartsIsDiscarded(): void
    {
        $this->logger->expects($this->exactly(4))->method('warning');

        $consumer = $this->consumer();

        $consumer->process($this->message(0, BatchQueuer::TYPE_SIMPLE, [10]));
        $consumer->process($this->message(7, '', [10]));
        $consumer->process($this->message(7, BatchQueuer::TYPE_SIMPLE, []));
        $consumer->process('"just a string"');

        $this->assertSame([], $this->warmed);
    }

    /**
     * Ids come back from the broker as strings after a JSON round trip, and the
     * collection filters on integer entity ids.
     */
    public function testTheProductIdsArriveAsIntegers(): void
    {
        $this->consumer()->process(
            (new Json())->serialize([
                'run_id' => '7',
                'type' => BatchQueuer::TYPE_SIMPLE,
                'product_ids' => ['10', '11'],
            ])
        );

        $this->assertSame([10, 11], $this->warmed[0]['ids']);
    }

    /**
     * @param int[] $productIds
     */
    private function message(int $runId, string $type, array $productIds): string
    {
        return (new Json())->serialize([
            'run_id' => $runId,
            'type' => $type,
            'product_ids' => $productIds,
        ]);
    }

    private function consumer(): WarmConsumer
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturnCallback(
            function (): string {
                $this->areaCalls[] = 'get';

                if (!$this->areaAlreadySet) {
                    throw new LocalizedException(__('Area code is not set'));
                }

                return Area::AREA_FRONTEND;
            }
        );
        $appState->method('setAreaCode')->willReturnCallback(function (string $code): void {
            $this->areaCalls[] = 'set:' . $code;
        });

        $tracker = $this->createMock(WarmRunTracker::class);
        $tracker->method('incrementProgress')->willReturnCallback(
            function (int $runId, int $processed, int $failed): void {
                $this->progress[] = ['runId' => $runId, 'processed' => $processed, 'failed' => $failed];
            }
        );
        $tracker->method('completeIfDone')->willReturnCallback(
            function (int $runId): bool {
                $this->completions[] = $runId;

                return true;
            }
        );

        return new WarmConsumer(
            new Json(),
            $appState,
            $this->lock,
            $tracker,
            $this->logger,
            $this->warmer('simple'),
            $this->warmer('configurable')
        );
    }

    private function warmer(string $name): WarmTaskInterface&MockObject
    {
        $warmer = $this->createMock(WarmTaskInterface::class);
        $warmer->method('warm')->willReturnCallback(
            function (array $productIds) use ($name): WarmResult {
                if ($this->warmFailure !== null) {
                    throw $this->warmFailure;
                }

                $this->warmed[] = ['warmer' => $name, 'ids' => $productIds];

                return $this->result;
            }
        );

        return $warmer;
    }
}
