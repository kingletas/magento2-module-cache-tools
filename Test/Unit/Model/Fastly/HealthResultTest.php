<?php
/**
 * HealthResultTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Fastly\HealthResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HealthResultTest extends TestCase
{
    public function testAProbedUrlCarriesWhatTheEdgeSaid(): void
    {
        $result = new HealthResult(
            'https://example.test/p',
            reachable: true,
            httpStatus: 200,
            cacheState: HealthResult::STATE_HIT,
            age: 42,
            servedBy: 'cache-lhr-1'
        );

        self::assertTrue($result->reachable);
        self::assertTrue($result->isCached());
        self::assertSame(42, $result->age);
        self::assertNull($result->error);
    }

    public function testAnUnreachableProbeDefaultsToUnknownWithNoStatus(): void
    {
        $result = new HealthResult('https://example.test/p', reachable: false, error: 'timed out');

        self::assertFalse($result->reachable);
        self::assertSame(0, $result->httpStatus);
        self::assertSame(HealthResult::STATE_UNKNOWN, $result->cacheState);
        self::assertFalse($result->isCached());
        self::assertSame('timed out', $result->error);
    }

    /**
     * An unreachable probe with no reason is indistinguishable from a probe
     * nobody ran, which is the state the operator most needs told apart.
     */
    public function testAnUnreachableProbeMustSayWhy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthResult('https://example.test/p', reachable: false);
    }

    public function testOnlyAHitCountsAsCached(): void
    {
        foreach ([HealthResult::STATE_MISS, HealthResult::STATE_PASS, HealthResult::STATE_UNKNOWN] as $state) {
            $result = new HealthResult('https://example.test/p', reachable: true, cacheState: $state);

            self::assertFalse($result->isCached(), $state);
        }
    }
}
