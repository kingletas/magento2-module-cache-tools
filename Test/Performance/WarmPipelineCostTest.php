<?php
/**
 * WarmPipelineCostTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Performance;

use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Lock\WarmLock;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Commerce\CacheTools\Model\Warmer\Publisher;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunFactory;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Commerce\CacheTools\Model\Warmer\WarmResult;
use Commerce\CacheTools\Queue\WarmConsumer;
use Commerce\CacheTools\Test\Support\InMemoryWarmRuns;
use Commerce\Foundation\Test\Support\BudgetAssertions;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What warming a catalogue costs before a single page is fetched.
 */
class WarmPipelineCostTest extends TestCase
{
    use BudgetAssertions;

    private const SECTION = 'commerce_cachetools';
    private const NOW = '2026-08-27 09:00:00';

    private InMemoryWarmRuns $runs;
    private LoggerInterface $logger;

    /** @var string[] */
    private array $queue = [];

    /** @var int[] */
    private array $catalogue = [];

    private int $collectionLoads = 0;
    private int $progressWrites = 0;

    protected function setUp(): void
    {
        $this->runs = new InMemoryWarmRuns();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->queue = [];
        $this->catalogue = [];
        $this->collectionLoads = 0;
        $this->progressWrites = 0;
    }

    public function testQueueingIsOneMessagePerBatchRatherThanPerProduct(): void
    {
        $this->assertCostPerBatch(
            'messages published to warm a catalogue',
            100,
            function (int $products): int {
                $this->runs = new InMemoryWarmRuns();
                $this->queue = [];
                $this->catalogue = range(1, $products);

                $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

                return count($this->queue);
            },
            [100, 1000, 10_000]
        );
    }

    public function testCollectingTheCatalogueIsOneQuery(): void
    {
        $this->assertConstantCost(
            'queries while collecting the products to warm',
            function (int $products): int {
                $this->runs = new InMemoryWarmRuns();
                $this->queue = [];
                $this->catalogue = range(1, $products);
                $this->collectionLoads = 0;

                $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

                return $this->collectionLoads;
            },
            [100, 10_000]
        );
    }

    /**
     * Every batch of the run updates the same row.
     */
    public function testConsumingABatchWritesProgressOnce(): void
    {
        $this->assertConstantCost(
            'progress writes per consumed batch',
            function (int $products): int {
                $this->runs = new InMemoryWarmRuns();
                $this->queue = [];
                $this->catalogue = range(1, $products);
                $this->progressWrites = 0;

                $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

                // One message, however large the batch it carries.
                $this->consumer()->process($this->queue[0]);

                return $this->progressWrites;
            },
            [50, 500]
        );
    }

    /**
     * An absolute budget: one row written at open, one per batch, and the
     * close.
     */
    public function testAWholeRunWritesTheRunRowOncePerBatchPlusOpenAndClose(): void
    {
        $this->catalogue = range(1, 1000);
        $this->queuer()->queue(BatchQueuer::TYPE_SIMPLE);

        $consumer = $this->consumer();

        foreach ($this->queue as $message) {
            $consumer->process($message);
        }

        $this->assertSame(10, count($this->queue), '1,000 products in batches of 100.');
        $this->assertCostAtMost('progress writes for a ten-batch run', 10, $this->progressWrites);
    }

    /**
     * A misconfigured batch size falls back rather than producing a run that
     * never returns.
     */
    public function testAMisconfiguredBatchSizeFallsBackRatherThanLoopingForever(): void
    {
        $this->catalogue = range(1, 500);

        $runId = $this->queuer(batchSize: '0')->queue(BatchQueuer::TYPE_SIMPLE);

        $this->assertNotNull($runId);
        $this->assertGreaterThan(0, count($this->queue));
        $this->assertLessThanOrEqual(500, count($this->queue));
    }

    private function queuer(string $batchSize = '100'): BatchQueuer
    {
        return new BatchQueuer(
            $this->activeProducts(),
            $this->config($batchSize),
            new Publisher($this->queuePublisher(), new Json(), 'commerce.cachetools.warm'),
            $this->tracker(),
            $this->lock(),
            $this->logger
        );
    }

    private function consumer(): WarmConsumer
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn('frontend');

        return new WarmConsumer(
            new Json(),
            $appState,
            $this->lock(),
            $this->tracker(),
            $this->logger,
            $this->warmer(),
            $this->warmer()
        );
    }

    private function tracker(): WarmRunTracker
    {
        $runFactory = $this->createMock(WarmRunFactory::class);
        $runFactory->method('create')->willReturnCallback(
            fn (): WarmRun => $this->createPartialMock(WarmRun::class, [])
        );

        $collection = $this->createMock(\Commerce\CacheTools\Model\ResourceModel\WarmRun\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);

        $collectionFactory = $this->createMock(
            \Commerce\CacheTools\Model\ResourceModel\WarmRun\CollectionFactory::class
        );
        $collectionFactory->method('create')->willReturn($collection);

        return new WarmRunTracker(
            $runFactory,
            $this->countingRuns(),
            $collectionFactory,
            $this->dateTime(),
            $this->logger
        );
    }

    /**
     * The in-memory table, with its progress writes counted.
     */
    private function countingRuns(): InMemoryWarmRuns
    {
        return new class ($this) extends InMemoryWarmRuns {
            public function __construct(private readonly WarmPipelineCostTest $test)
            {
                parent::__construct();
            }

            public function incrementProgress(int $runId, int $processed, int $failed): int
            {
                $this->test->recordProgressWrite();

                return parent::incrementProgress($runId, $processed, $failed);
            }
        };
    }

    /**
     * @internal Called by the counting resource above.
     */
    public function recordProgressWrite(): void
    {
        $this->progressWrites++;
    }

    private function lock(): WarmLock
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->method('unlock')->willReturn(true);

        return new WarmLock($lockManager, $this->logger);
    }

    private function warmer(): WarmTaskInterface
    {
        return new class implements WarmTaskInterface {
            /**
             * @param int[] $productIds
             */
            public function warm(array $productIds): WarmResult
            {
                return new WarmResult(count($productIds), count($productIds));
            }
        };
    }

    private function activeProducts(): ActiveProductCollection
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getAllIds')->willReturnCallback(function (): array {
            $this->collectionLoads++;

            return $this->catalogue;
        });

        $active = $this->createMock(ActiveProductCollection::class);
        $active->method('forSimple')->willReturn($collection);
        $active->method('forConfigurable')->willReturn($collection);

        return $active;
    }

    private function queuePublisher(): PublisherInterface
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function ($topic, $data): void {
            $this->queue[] = (string) $data;
        });

        return $publisher;
    }

    private function config(string $batchSize): Config
    {
        return new Config(
            $this->scopeConfig([
                self::SECTION . '/warmer/simple_batch_size' => $batchSize,
                self::SECTION . '/warmer/configurable_batch_size' => $batchSize,
            ]),
            self::SECTION,
            $this->createMock(EncryptorInterface::class)
        );
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        return $dateTime;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
