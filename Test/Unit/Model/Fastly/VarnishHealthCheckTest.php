<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Fastly\HealthResult;
use Commerce\CacheTools\Model\Fastly\VarnishHealthCheck;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VarnishHealthCheckTest extends TestCase
{
    /** @var string[] */
    private array $fetched = [];

    /** @var array<string, string|string[]> */
    private array $responseHeaders = [];

    private ?int $timeout = null;
    private int $status = 200;
    private ?\Throwable $fetchFailure = null;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->fetched = [];
        $this->responseHeaders = ['X-Cache' => 'HIT', 'Age' => '120', 'X-Served-By' => 'cache-lhr-1'];
        $this->timeout = null;
        $this->status = 200;
        $this->fetchFailure = null;
        $this->logger = new RecordingLogger();
    }

    /**
     * Whether this URL is served from cache, how old the copy is, and which
     * node answered.
     */
    public function testAProbeReportsTheCacheStateAgeAndNode(): void
    {
        $result = $this->check()->check('https://shop.test/scrub-top.html');

        $this->assertTrue($result->reachable);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(HealthResult::STATE_HIT, $result->cacheState);
        $this->assertSame(120, $result->age);
        $this->assertSame('cache-lhr-1', $result->servedBy);
    }

    /**
     * HTTP header names are case-insensitive, so the probe matches any casing.
     */
    public function testHeaderNamesAreMatchedWhateverTheirCasing(): void
    {
        $this->responseHeaders = ['x-cache' => 'HIT', 'AGE' => '30', 'X-SERVED-BY' => 'cache-lhr-2'];

        $result = $this->check()->check('https://shop.test/a.html');

        $this->assertSame(HealthResult::STATE_HIT, $result->cacheState);
        $this->assertSame(30, $result->age);
        $this->assertSame('cache-lhr-2', $result->servedBy);
    }

    /**
     * Fastly reports the whole chain, e.g. "MISS, HIT" - and a hit anywhere in
     * it means the shopper was served from cache.
     */
    public function testAChainedCacheHeaderIsReadAsAHit(): void
    {
        $this->responseHeaders['X-Cache'] = 'MISS, HIT';

        $this->assertSame(HealthResult::STATE_HIT, $this->check()->check('https://shop.test/a.html')->cacheState);
    }

    public function testAMissAndAPassAreReportedAsThemselves(): void
    {
        $this->responseHeaders['X-Cache'] = 'MISS';
        $this->assertSame(HealthResult::STATE_MISS, $this->check()->check('https://shop.test/a.html')->cacheState);

        $this->responseHeaders['X-Cache'] = 'PASS';
        $this->assertSame(HealthResult::STATE_PASS, $this->check()->check('https://shop.test/a.html')->cacheState);
    }

    /**
     * A response with no cache header is not a miss - it is a response from
     * something that is not a cache at all, which is a different diagnosis.
     */
    public function testAResponseWithNoCacheHeaderIsUnknownRatherThanAMiss(): void
    {
        $this->responseHeaders = [];

        $result = $this->check()->check('https://shop.test/a.html');

        $this->assertSame(HealthResult::STATE_UNKNOWN, $result->cacheState);
        $this->assertNull($result->age);
        $this->assertNull($result->servedBy);
    }

    /**
     * A header sent more than once arrives as a list, and the last value is the
     * one the edge closest to the client set.
     */
    public function testARepeatedHeaderIsReadFromItsLastValue(): void
    {
        $this->responseHeaders['X-Cache'] = ['MISS', 'HIT'];

        $this->assertSame(HealthResult::STATE_HIT, $this->check()->check('https://shop.test/a.html')->cacheState);
    }

    /**
     * A probe from an admin button must not hold the request open on an
     * unresponsive origin.
     */
    public function testTheProbeIsBounded(): void
    {
        $this->check()->check('https://shop.test/a.html');

        $this->assertNotNull($this->timeout);
        $this->assertGreaterThan(0, $this->timeout);
    }

    /**
     * A malformed URL cannot be probed, and spending a request to find that out
     * is a round trip for nothing.
     */
    public function testAMalformedUrlIsRefusedWithoutAProbe(): void
    {
        $result = $this->check()->check('not a url');

        $this->assertFalse($result->reachable);
        $this->assertSame([], $this->fetched);
        $this->assertStringContainsString('not a valid URL', (string) $result->error);
    }

    /**
     * An unreachable URL comes back as a result rather than an exception, and
     * is logged.
     */
    public function testAnUnreachableUrlIsReportedAsAResultAndLogged(): void
    {
        $this->fetchFailure = new RuntimeException('Connection timed out');

        $result = $this->check()->check('https://shop.test/a.html');

        $this->assertFalse($result->reachable);
        $this->assertStringContainsString('Connection timed out', (string) $result->error);
        $this->assertCount(1, $this->logger->warnings);
    }

    /**
     * An error status is still a reachable edge: "the page 500s" and "the CDN
     * is unreachable" send an operator to different places.
     */
    public function testAnErrorStatusIsStillAReachableEdge(): void
    {
        $this->status = 503;

        $result = $this->check()->check('https://shop.test/a.html');

        $this->assertTrue($result->reachable);
        $this->assertSame(503, $result->httpStatus);
    }

    private function check(): VarnishHealthCheck
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('setTimeout')->willReturnCallback(function (int $seconds): void {
            $this->timeout = $seconds;
        });
        $curl->method('get')->willReturnCallback(function (string $url): void {
            if ($this->fetchFailure !== null) {
                throw $this->fetchFailure;
            }

            $this->fetched[] = $url;
        });
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->status);
        $curl->method('getHeaders')->willReturnCallback(fn (): array => $this->responseHeaders);

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return new VarnishHealthCheck($factory, $this->logger);
    }
}
