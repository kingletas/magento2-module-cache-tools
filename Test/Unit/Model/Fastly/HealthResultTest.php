<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
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

        $this->assertTrue($result->reachable);
        $this->assertTrue($result->isCached());
        $this->assertSame(42, $result->age);
        $this->assertNull($result->error);
    }

    public function testAnUnreachableProbeDefaultsToUnknownWithNoStatus(): void
    {
        $result = new HealthResult('https://example.test/p', reachable: false, error: 'timed out');

        $this->assertFalse($result->reachable);
        $this->assertSame(0, $result->httpStatus);
        $this->assertSame(HealthResult::STATE_UNKNOWN, $result->cacheState);
        $this->assertFalse($result->isCached());
        $this->assertSame('timed out', $result->error);
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

            $this->assertFalse($result->isCached(), $state);
        }
    }
}
