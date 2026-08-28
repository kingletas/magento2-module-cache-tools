<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Run;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WarmRunTest extends TestCase
{
    /**
     * Read off the declared name rather than the injected instance: a mis-wired
     * `_init()` sends every save to another module's table.
     */
    public function testTheEntityDeclaresItsOwnResourceModel(): void
    {
        $declared = (new ReflectionProperty(WarmRun::class, '_resourceName'))->getValue($this->entity());

        self::assertSame(WarmRunResource::class, $declared);
    }

    public function testTheEntityIsKeyedOnTheRunId(): void
    {
        self::assertSame(WarmRunInterface::RUN_ID, $this->entity()->getIdFieldName());
    }

    public function testEveryFieldIsReadBackWithADefiniteType(): void
    {
        $run = $this->entity();
        $run->setData(WarmRunInterface::RUN_ID, '5');
        $run->setData(WarmRunInterface::WARM_TYPE, 'product');
        $run->setData(WarmRunInterface::STATUS, WarmRunInterface::STATUS_RUNNING);
        $run->setData(WarmRunInterface::TOTAL_PRODUCTS, '100');
        $run->setData(WarmRunInterface::PROCESSED_PRODUCTS, '40');
        $run->setData(WarmRunInterface::FAILED_PRODUCTS, '2');

        self::assertSame(5, $run->getRunId());
        self::assertSame('product', $run->getWarmType());
        self::assertSame(WarmRunInterface::STATUS_RUNNING, $run->getStatus());
        self::assertSame(100, $run->getTotalProducts());
        self::assertSame(40, $run->getProcessedProducts());
        self::assertSame(2, $run->getFailedProducts());
    }

    public function testAnUnsavedRunHasNoIdRatherThanZero(): void
    {
        self::assertNull($this->entity()->getRunId());
    }

    public function testOnlyARunningRunIsRunning(): void
    {
        self::assertTrue($this->runWithStatus(WarmRunInterface::STATUS_RUNNING)->isRunning());
        self::assertFalse($this->runWithStatus(WarmRunInterface::STATUS_COMPLETE)->isRunning());
        self::assertFalse($this->runWithStatus(WarmRunInterface::STATUS_STALE)->isRunning());
    }

    public function testProgressIsThePercentageProcessed(): void
    {
        self::assertSame(40, $this->progressFor(100, 40));
        self::assertSame(100, $this->progressFor(100, 100));
        self::assertSame(0, $this->progressFor(100, 0));
    }

    /**
     * A run with nothing to do is finished, not stalled at zero - the grid
     * would otherwise show a permanent 0% bar for an empty catalogue.
     */
    public function testARunWithNothingToDoIsAlreadyComplete(): void
    {
        self::assertSame(100, $this->progressFor(0, 0));
    }

    /**
     * A redelivered message must not push progress past the run's total.
     */
    public function testProgressIsCappedAtOneHundred(): void
    {
        self::assertSame(100, $this->progressFor(100, 140));
    }

    /**
     * A negative total is a corrupted row rather than a division to attempt.
     */
    public function testANonsensicalTotalReadsAsComplete(): void
    {
        self::assertSame(100, $this->progressFor(-5, 0));
    }

    private function progressFor(int $total, int $processed): int
    {
        $run = $this->entity();
        $run->setData(WarmRunInterface::TOTAL_PRODUCTS, $total);
        $run->setData(WarmRunInterface::PROCESSED_PRODUCTS, $processed);

        return $run->getProgressPercent();
    }

    private function runWithStatus(string $status): WarmRun
    {
        $run = $this->entity();
        $run->setData(WarmRunInterface::STATUS, $status);

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
}
