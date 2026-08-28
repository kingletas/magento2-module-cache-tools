<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Warmer\WarmResult;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WarmResultTest extends TestCase
{
    public function testItCountsWhatWasAttemptedAndWhatSucceeded(): void
    {
        $result = new WarmResult(10, 8);

        self::assertSame(10, $result->total);
        self::assertSame(8, $result->warmed);
        self::assertSame(2, $result->getFailed());
    }

    /**
     * A batch that somehow warmed more than it attempted is a counting bug, not
     * a negative failure count written into the run's grid row.
     */
    public function testTheFailureCountNeverGoesNegative(): void
    {
        self::assertSame(0, (new WarmResult(8, 10))->getFailed());
    }

    public function testAFullySuccessfulBatchHasNoFailures(): void
    {
        self::assertSame(0, (new WarmResult(10, 10))->getFailed());
    }

    /**
     * The per-product messages say which URLs a run could not reach; a count
     * alone does not.
     */
    public function testTheFailureMessagesTravelWithTheCounts(): void
    {
        $result = new WarmResult(2, 1, ['SKU-1: HTTP 500']);

        self::assertSame(['SKU-1: HTTP 500'], $result->messages);
    }

    public function testABatchWithNothingToWarmIsAllZeroes(): void
    {
        $result = new WarmResult(0, 0);

        self::assertSame(0, $result->getFailed());
        self::assertSame([], $result->messages);
    }

    public function testItIsImmutable(): void
    {
        foreach (['total', 'warmed', 'messages'] as $property) {
            self::assertTrue(
                (new ReflectionProperty(WarmResult::class, $property))->isReadOnly(),
                sprintf('%s must be read-only.', $property)
            );
        }
    }
}
