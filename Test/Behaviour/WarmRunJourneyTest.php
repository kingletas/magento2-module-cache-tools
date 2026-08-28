<?php
/**
 * WarmRunJourneyTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Behaviour;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Lock\WarmLock;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Commerce\CacheTools\Model\Warmer\Publisher;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunFactory;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Commerce\CacheTools\Model\Warmer\WarmResult;
use Commerce\CacheTools\Queue\WarmConsumer;
use Commerce\CacheTools\Test\Behaviour\Fake\InMemoryWarmRuns;
use Commerce\CacheTools\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A cache warm run, from `bin/magento` to a closed row.
 */
class WarmRunJourneyTest extends TestCase
{
    private const SECTION = 'commerce_cachetools';
    private const NOW = '2026-08-27 09:00:00';

    private InMemoryWarmRuns $runs;
    private RecordingLogger $logger;

    /** @var string[] Serialized messages on the queue. */
    private array $queue = [];

    /** @var int[] Product ids the catalogue holds. */
    private array $catalogue = [];

    /** @var int[] Product ids actually warmed, in order. */
    private array $warmed = [];

    /** @var array<string, string> */
    private array $settings = [];

    private string $now = self::NOW;
    private bool $warmingFails = false;
    private bool $lockHeld = false;

    protected function setUp(): void
    {
        $this->runs = new InMemoryWarmRuns();
        $this->logger = new RecordingLogger();
        $this->queue = [];
        $this->catalogue = range(1, 250);
        $this->warmed = [];
        $this->now = self::NOW;
        $this->warmingFails = false;
        $this->lockHeld = false;
        $this->settings = [
            self::SECTION . '/warmer/simple_batch_size' => '100',
            self::SECTION . '/warmer/configurable_batch_size' => '50',
            self::SECTION . '/warmer/stale_run_hours' => '24',
        ];
    }

    public function testAWarmRunOpensQueuesConsumesAndCloses(): void
    {
        $runId = $this->queueWarm();

        $this->assertNotNull($runId);
        $this->assertCount(3, $this->queue, '250 products in batches of 100.');
        $this->assertSame(WarmRunInterface::STATUS_RUNNING, $this->runs->statusOf($runId));

        $this->consumeEverythingQueued();

        $this->assertCount(250, $this->warmed);
        $this->assertSame(WarmRunInterface::STATUS_COMPLETE, $this->runs->statusOf($runId));
    }

    /**
     * Two of three batches leaves the run open, which is also what a stranded
     * run looks like.
     */
    public function testARunStaysOpenUntilEveryBatchHasBeenAccountedFor(): void
    {
        $runId = $this->queueWarm();

        $this->consume(array_shift($this->queue));
        $this->consume(array_shift($this->queue));

        $this->assertSame(WarmRunInterface::STATUS_RUNNING, $this->runs->statusOf($runId));
        $this->assertSame(200, $this->runs->run($runId)[WarmRunInterface::PROCESSED_PRODUCTS]);
    }

    /**
     * An exception in the warmer that skipped the increment would leave the run
     * one batch short for ever.
     */
    public function testABatchThatFailsEntirelyIsStillCountedSoTheRunCanClose(): void
    {
        $this->warmingFails = true;

        $runId = $this->queueWarm();
        $this->consumeEverythingQueued();

        $this->assertSame(WarmRunInterface::STATUS_COMPLETE, $this->runs->statusOf($runId));
        $this->assertSame(250, $this->runs->run($runId)[WarmRunInterface::FAILED_PRODUCTS]);
    }

    /**
     * The consumer locks on the message content, so a duplicate does not warm
     * twice - and the skipped batch is not counted, which strands the run.
     */
    public function testARedeliveredBatchIsSkippedAndSaysSoRatherThanWarmingTwice(): void
    {
        $this->catalogue = range(1, 100);
        $runId = $this->queueWarm();
        $message = $this->queue[0];

        $this->consume($message);
        $warmedAfterFirst = count($this->warmed);

        // The same message again, with the lock still held by the first
        // delivery - which is what a redelivery during processing looks like.
        $this->lockHeld = true;
        $this->consume($message);

        $this->assertCount($warmedAfterFirst, $this->warmed, 'A redelivery must not warm the batch twice.');
        $this->assertNotSame([], $this->logger->warnings, 'And it must say that the run will not reach its total.');
    }

    /**
     * Interleaved batches from two runs must not advance the older row's
     * counter.
     */
    public function testASecondRunOfTheSameTypeIsRefusedWhileOneIsOpen(): void
    {
        $this->queueWarm();
        $this->queue = [];

        $second = $this->queueWarm();

        $this->assertNull($second);
        $this->assertSame([], $this->queue, 'Nothing should have been queued for the refused run.');
    }

    public function testOnceARunHasClosedTheNextOneStarts(): void
    {
        $first = $this->queueWarm();
        $this->consumeEverythingQueued();

        $second = $this->queueWarm();

        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    /**
     * The threshold is measured from the run's last progress rather than from
     * when it started.
     */
    public function testAStrandedRunIsReapedOnceItHasStoppedMakingProgress(): void
    {
        $runId = $this->queueWarm();
        $this->consume(array_shift($this->queue));

        // Still moving as far as the reaper can tell.
        $this->assertSame(0, $this->reap());
        $this->assertSame(WarmRunInterface::STATUS_RUNNING, $this->runs->statusOf($runId));

        // Two days pass and the remaining batches never arrive.
        $this->runs->noProgressSince($runId, $this->hoursAgo(48));

        $this->assertSame(1, $this->reap());
        $this->assertSame(WarmRunInterface::STATUS_STALE, $this->runs->statusOf($runId));
    }

    /**
     * A stranded run blocks every future run of its type, which is why the
     * reaper exists.
     */
    public function testReapingAStrandedRunLetsWarmingStartAgain(): void
    {
        $runId = $this->queueWarm();
        $this->queue = [];
        $this->runs->noProgressSince((int) $runId, $this->hoursAgo(48));

        $this->reap();

        $this->assertNotNull($this->queueWarm(), 'A reaped run must not keep blocking its type.');
    }

    /**
     * A run that opens with a total of zero is ordinary rather than stranded.
     */
    public function testARunWithNothingToWarmClosesStraightAway(): void
    {
        $this->catalogue = [];

        $runId = $this->queueWarm();

        $this->assertNotNull($runId);
        $this->assertSame(WarmRunInterface::STATUS_COMPLETE, $this->runs->statusOf($runId));
        $this->assertSame([], $this->queue);
    }

    /**
     * Queue payloads come from another process and, on a bad day, from an older
     * version of this module.
     */
    public function testAnUndecodableMessageIsDroppedWithoutAdvancingAnyRun(): void
    {
        $runId = $this->queueWarm();

        $this->consume('this is not a serialized batch');

        $this->assertSame(0, $this->runs->run($runId)[WarmRunInterface::PROCESSED_PRODUCTS]);
        $this->assertSame(WarmRunInterface::STATUS_RUNNING, $this->runs->statusOf($runId));
    }

    private function queueWarm(string $type = BatchQueuer::TYPE_SIMPLE): ?int
    {
        return $this->queuer()->queue($type);
    }

    private function consumeEverythingQueued(): void
    {
        $messages = $this->queue;
        $this->queue = [];

        foreach ($messages as $message) {
            $this->consume($message);
        }
    }

    private function consume(?string $message): void
    {
        if ($message === null) {
            return;
        }

        $this->consumer()->process($message);
    }

    private function reap(): int
    {
        return (new StaleRunReaper($this->runs, $this->config(), $this->dateTime(), $this->logger))->reap();
    }

    private function queuer(): BatchQueuer
    {
        return new BatchQueuer(
            $this->activeProducts(),
            $this->config(),
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

        return new WarmRunTracker($runFactory, $this->runs, $collectionFactory, $this->dateTime(), $this->logger);
    }

    /**
     * A lock manager that grants everything unless the test says a delivery is
     * already holding it.
     */
    private function lock(): WarmLock
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(fn (): bool => !$this->lockHeld);
        $lockManager->method('unlock')->willReturn(true);
        $lockManager->method('isLocked')->willReturnCallback(fn (): bool => $this->lockHeld);

        return new WarmLock($lockManager, $this->logger);
    }

    /**
     * A warmer that records what it was given, or refuses.
     */
    private function warmer(): WarmTaskInterface
    {
        $test = $this;

        return new class ($test, $this->warmingFails) implements WarmTaskInterface {
            public function __construct(
                private readonly WarmRunJourneyTest $test,
                private readonly bool $fails
            ) {
            }

            /**
             * @param int[] $productIds
             */
            public function warm(array $productIds): WarmResult
            {
                if ($this->fails) {
                    throw new RuntimeException('the storefront is not answering');
                }

                $this->test->recordWarmed($productIds);

                return new WarmResult(count($productIds), count($productIds));
            }
        };
    }

    /**
     * @param int[] $productIds
     *
     * @internal Called by the warmer double above.
     */
    public function recordWarmed(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->warmed[] = (int) $productId;
        }
    }

    private function activeProducts(): ActiveProductCollection
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getAllIds')->willReturnCallback(fn (): array => $this->catalogue);

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

    private function config(): Config
    {
        // The encryptor is only reached by the Fastly credentials, which
        // nothing in a warm run touches.
        return new Config(
            new ArrayScopeConfig($this->settings),
            self::SECTION,
            $this->createMock(EncryptorInterface::class)
        );
    }

    /**
     * Gets a timestamp before the test clock, not before the wall clock.
     */
    private function hoursAgo(int $hours): string
    {
        return gmdate('Y-m-d H:i:s', strtotime(sprintf('-%d hours', $hours), (int) strtotime($this->now)));
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        // Magento's own signature is `gmtDate($format = null, $input = null)`,
        // and the tracker calls it with neither argument.
        $dateTime->method('gmtTimestamp')->willReturnCallback(
            fn (): int => (int) strtotime($this->now)
        );
        $dateTime->method('gmtDate')->willReturnCallback(
            fn ($format = null, $input = null): string =>
                $input === null ? $this->now : gmdate((string) ($format ?: 'Y-m-d H:i:s'), (int) $input)
        );

        return $dateTime;
    }
}
