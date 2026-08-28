<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Console\Warmer;

use Commerce\CacheTools\Console\Warmer\QueueWarmCommand;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class QueueWarmCommandTest extends TestCase
{
    /** @var string[] */
    private array $queued = [];

    /** @var string[] */
    private array $areaCalls = [];

    /** @var string[] Types for which no run is started. */
    private array $alreadyRunning = [];

    private bool $areaAlreadySet = false;
    private ?\Throwable $queueFailure = null;

    protected function setUp(): void
    {
        $this->queued = [];
        $this->areaCalls = [];
        $this->alreadyRunning = [];
        $this->areaAlreadySet = false;
        $this->queueFailure = null;
    }

    /**
     * Naming no type warms the whole catalogue rather than half of it.
     */
    public function testBothTypesAreQueuedByDefault(): void
    {
        $this->tester()->execute([]);

        $this->assertSame([BatchQueuer::TYPE_SIMPLE, BatchQueuer::TYPE_CONFIGURABLE], $this->queued);
    }

    public function testASingleTypeCanBeQueued(): void
    {
        $this->tester()->execute(['--type' => BatchQueuer::TYPE_CONFIGURABLE]);

        $this->assertSame([BatchQueuer::TYPE_CONFIGURABLE], $this->queued);
    }

    /**
     * A mistyped type would otherwise fall through to warming nothing and
     * exiting zero, which reads as a successful run.
     */
    public function testAnUnknownTypeIsRefused(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['--type' => 'bundle']));
        $this->assertStringContainsString('Unknown type "bundle"', $tester->getDisplay());
        $this->assertSame([], $this->queued);
    }

    /**
     * The area is set after the argument check and before any URL or block
     * work.
     */
    public function testTheFrontendAreaIsSetBeforeQueueing(): void
    {
        $this->tester()->execute([]);

        $this->assertSame(['get', 'set:' . Area::AREA_FRONTEND], $this->areaCalls);
    }

    public function testAnAreaThatIsAlreadySetIsLeftAlone(): void
    {
        $this->areaAlreadySet = true;

        $this->tester()->execute([]);

        $this->assertSame(['get'], $this->areaCalls);
    }

    public function testEachQueuedRunIsReportedWithItsId(): void
    {
        $tester = $this->tester();
        $tester->execute(['--type' => BatchQueuer::TYPE_SIMPLE]);

        $this->assertStringContainsString('Queued simple warm run #7', $tester->getDisplay());
    }

    /**
     * An operator who queues a run and never starts a consumer sees nothing
     * happen; the command says what to do next.
     */
    public function testTheConsumerCommandIsPrintedAfterAnythingIsQueued(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('queue:consumers:start', $tester->getDisplay());
    }

    /**
     * A run of the same type already in progress is not an error - it is the
     * lock doing its job - so the command says so and moves on.
     */
    public function testATypeAlreadyRunningIsReportedAndSkipped(): void
    {
        $this->alreadyRunning = [BatchQueuer::TYPE_SIMPLE];

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Skipped simple', $tester->getDisplay());
        $this->assertStringContainsString('Queued configurable', $tester->getDisplay());
    }

    /**
     * Nothing queued means nothing to consume, so the advice would send an
     * operator to start a consumer with no work for it.
     */
    public function testTheConsumerAdviceIsWithheldWhenNothingWasQueued(): void
    {
        $this->alreadyRunning = [BatchQueuer::TYPE_SIMPLE, BatchQueuer::TYPE_CONFIGURABLE];

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringNotContainsString('queue:consumers:start', $tester->getDisplay());
    }

    public function testAFailureToQueueIsReportedAndExitsNonZero(): void
    {
        $this->queueFailure = new RuntimeException('the queue is unreachable');

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('the queue is unreachable', $tester->getDisplay());
    }

    private function command(): QueueWarmCommand
    {
        $queuer = $this->createMock(BatchQueuer::class);
        $queuer->method('queue')->willReturnCallback(
            function (string $type): ?int {
                if ($this->queueFailure !== null) {
                    throw $this->queueFailure;
                }

                if (in_array($type, $this->alreadyRunning, true)) {
                    return null;
                }

                $this->queued[] = $type;

                return 7;
            }
        );

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

        return new QueueWarmCommand($queuer, $appState);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command());
    }
}
