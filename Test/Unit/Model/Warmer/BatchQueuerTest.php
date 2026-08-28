<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Commerce\CacheTools\Model\Warmer\Publisher;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Commerce\CacheTools\Test\Unit\Fake\PassthroughLock;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use InvalidArgumentException;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BatchQueuerTest extends TestCase
{
    /** @var array<int, array{runId: int, type: string, ids: int[]}> */
    private array $published = [];

    /** @var array<int, array{type: string, total: int}> */
    private array $opened = [];

    /** @var int[] */
    private array $completed = [];

    /** @var int[] */
    private array $productIds = [1, 2, 3, 4, 5];

    private bool $hasOpenRun = false;
    private int $simpleBatchSize = 2;
    private int $configurableBatchSize = 3;
    private PassthroughLock $lock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->published = [];
        $this->opened = [];
        $this->completed = [];
        $this->productIds = [1, 2, 3, 4, 5];
        $this->hasOpenRun = false;
        $this->simpleBatchSize = 2;
        $this->configurableBatchSize = 3;
        $this->lock = new PassthroughLock();
        $this->logger = new RecordingLogger();
    }

    public function testARunIsOpenedForTheProductsItWillWarm(): void
    {
        $runId = $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        self::assertSame(7, $runId);
        self::assertSame([['type' => BatchQueuer::TYPE_SIMPLE, 'total' => 5]], $this->opened);
    }

    public function testTheProductsArePublishedInConfiguredBatches(): void
    {
        $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        self::assertSame([[1, 2], [3, 4], [5]], array_column($this->published, 'ids'));
    }

    /**
     * Configurables cost far more to warm than simples - each one renders a
     * swatch payload - so the two types carry their own batch size.
     */
    public function testEachTypeUsesItsOwnBatchSize(): void
    {
        $this->queuer()->queue(BatchQueuer::TYPE_CONFIGURABLE);

        self::assertSame([[1, 2, 3], [4, 5]], array_column($this->published, 'ids'));
    }

    public function testEveryBatchCarriesItsRunAndType(): void
    {
        $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        foreach ($this->published as $batch) {
            self::assertSame(7, $batch['runId']);
            self::assertSame(BatchQueuer::TYPE_SIMPLE, $batch['type']);
        }
    }

    /**
     * The whole operation runs under a per-type lock, so no product is enqueued
     * twice.
     */
    public function testTheWholeOperationRunsUnderAPerTypeLock(): void
    {
        $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);
        $this->queuer()->queue(BatchQueuer::TYPE_CONFIGURABLE);

        self::assertCount(2, $this->lock->taken);
        self::assertNotSame($this->lock->taken[0], $this->lock->taken[1]);
        self::assertStringContainsString(BatchQueuer::TYPE_SIMPLE, $this->lock->taken[0]);
    }

    public function testAHeldLockQueuesNothingAndReportsNoRun(): void
    {
        $this->lock->held = ['commerce_cachetools_warm_queue_' . BatchQueuer::TYPE_SIMPLE];

        self::assertNull($this->queuer()->queue(BatchQueuer::TYPE_SIMPLE));
        self::assertSame([], $this->published);
        self::assertSame([], $this->opened);
    }

    /**
     * A second run of the same type interleaves its batches with the first and
     * leaves the older row stuck at "running" for good.
     */
    public function testASecondRunOfTheSameTypeIsRefused(): void
    {
        $this->hasOpenRun = true;

        self::assertNull($this->queuer()->queue(BatchQueuer::TYPE_SIMPLE));
        self::assertSame([], $this->opened);
        self::assertCount(1, $this->logger->infos);
    }

    /**
     * A run with nothing to queue is closed immediately: left open it would
     * block the next run of its type until the reaper got to it hours later.
     */
    public function testARunWithNoProductsIsOpenedAndClosedAtOnce(): void
    {
        $this->productIds = [];

        $runId = $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        self::assertSame(7, $runId);
        self::assertSame([7], $this->completed);
        self::assertSame([], $this->published);
    }

    /**
     * The type reaches this from a console argument and a di.xml virtual type,
     * and an unrecognised one would open a run nothing ever consumes.
     */
    public function testAnUnknownTypeIsRefusedBeforeAnythingIsLocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown warm type "bundle"');

        try {
            $this->queuer()->queue('bundle');
        } finally {
            self::assertSame([], $this->lock->taken);
        }
    }

    public function testQueueingIsRecordedWithItsCounts(): void
    {
        $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        self::assertCount(1, $this->logger->infos);
        self::assertStringContainsString('#7', $this->logger->infos[0]);
        self::assertStringContainsString('5 ' . BatchQueuer::TYPE_SIMPLE, $this->logger->infos[0]);
        self::assertStringContainsString('3 batch(es)', $this->logger->infos[0]);
    }

    private function queuer(): BatchQueuer
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getAllIds')->willReturnCallback(fn (): array => $this->productIds);

        $activeProducts = $this->createMock(ActiveProductCollection::class);
        $activeProducts->method('forSimple')->willReturn($collection);
        $activeProducts->method('forConfigurable')->willReturn($collection);

        $config = $this->createMock(Config::class);
        $config->method('getSimpleBatchSize')->willReturnCallback(fn (): int => $this->simpleBatchSize);
        $config->method('getConfigurableBatchSize')->willReturnCallback(fn (): int => $this->configurableBatchSize);

        $publisher = $this->createMock(Publisher::class);
        $publisher->method('publishBatch')->willReturnCallback(
            function (int $runId, string $type, array $productIds): void {
                $this->published[] = ['runId' => $runId, 'type' => $type, 'ids' => $productIds];
            }
        );

        $tracker = $this->createMock(WarmRunTracker::class);
        $tracker->method('hasOpenRun')->willReturnCallback(fn (): bool => $this->hasOpenRun);
        $tracker->method('open')->willReturnCallback(
            function (string $type, int $total): int {
                $this->opened[] = ['type' => $type, 'total' => $total];

                return 7;
            }
        );
        $tracker->method('completeIfDone')->willReturnCallback(
            function (int $runId): bool {
                $this->completed[] = $runId;

                return true;
            }
        );

        return new BatchQueuer($activeProducts, $config, $publisher, $tracker, $this->lock, $this->logger);
    }
}
