<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Warmer\UrlWarmer;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UrlWarmerTest extends TestCase
{
    /** @var string[] */
    private array $fetched = [];

    /** @var array<string, string> */
    private array $headers = [];

    private ?int $timeout = null;
    private int $status = 200;
    private ?\Throwable $fetchFailure = null;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->fetched = [];
        $this->headers = [];
        $this->timeout = null;
        $this->status = 200;
        $this->fetchFailure = null;
        $this->logger = new RecordingLogger();
    }

    public function testAUrlIsFetchedSoTheEdgeCachesIt(): void
    {
        self::assertTrue($this->warmer()->warm('https://shop.test/scrub-top.html'));
        self::assertSame(['https://shop.test/scrub-top.html'], $this->fetched);
    }

    /**
     * Asking for an uncached response, or the edge serves its own copy and the
     * URL stays cold.
     */
    public function testTheRequestAsksTheEdgeForAFreshCopy(): void
    {
        $this->warmer()->warm('https://shop.test/a.html');

        self::assertSame('no-cache', $this->headers['Cache-Control']);
    }

    /**
     * A recognisable agent is what lets an operator tell warmer traffic from
     * shopper traffic in the access log - and exclude it from analytics.
     */
    public function testTheWarmerIdentifiesItself(): void
    {
        $this->warmer()->warm('https://shop.test/a.html');

        self::assertStringContainsString('cache warmer', $this->headers['User-Agent']);
    }

    public function testTheAgentAndTimeoutAreConfigurable(): void
    {
        $this->warmer(timeout: 5, userAgent: 'Acme-Warmer/2.0')->warm('https://shop.test/a.html');

        self::assertSame(5, $this->timeout);
        self::assertSame('Acme-Warmer/2.0', $this->headers['User-Agent']);
    }

    /**
     * A slow origin must not hold a queue worker indefinitely.
     */
    public function testTheRequestIsBounded(): void
    {
        $this->warmer()->warm('https://shop.test/a.html');

        self::assertNotNull($this->timeout);
        self::assertGreaterThan(0, $this->timeout);
    }

    /**
     * A redirect is a cacheable response too, and a warmer that treated 301 as
     * a failure would report every canonicalised URL as broken.
     */
    public function testARedirectCountsAsACacheableSuccess(): void
    {
        $this->status = 301;

        self::assertTrue($this->warmer()->warm('https://shop.test/a.html'));
        self::assertSame([], $this->logger->warnings);
    }

    /**
     * A 404 or a 500 is not cached, and is worth a line in the log.
     */
    public function testAnErrorResponseIsReportedWithItsStatus(): void
    {
        $this->status = 503;

        self::assertFalse($this->warmer()->warm('https://shop.test/a.html'));
        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('503', $this->logger->warnings[0]);
    }

    /**
     * A malformed URL cannot be warmed, and spending a request to find out is a
     * round trip for nothing.
     */
    public function testAMalformedUrlIsRefusedWithoutAFetch(): void
    {
        self::assertFalse($this->warmer()->warm('not a url'));
        self::assertSame([], $this->fetched);
        self::assertCount(1, $this->logger->warnings);
    }

    /**
     * Warming is an optimisation: an unreachable origin is worth a line but
     * must not take the queue consumer with it.
     */
    public function testAnUnreachableOriginIsContainedAndLogged(): void
    {
        $this->fetchFailure = new RuntimeException('Connection timed out');

        self::assertFalse($this->warmer()->warm('https://shop.test/a.html'));
        self::assertCount(1, $this->logger->warnings);
        self::assertSame([], $this->logger->errors);
    }

    private function warmer(int $timeout = 30, string $userAgent = 'Commerce-CacheTools/1.0 (cache warmer)'): UrlWarmer
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('setTimeout')->willReturnCallback(function (int $seconds): void {
            $this->timeout = $seconds;
        });
        $curl->method('addHeader')->willReturnCallback(function (string $name, $value): void {
            $this->headers[$name] = (string) $value;
        });
        $curl->method('get')->willReturnCallback(function (string $url): void {
            if ($this->fetchFailure !== null) {
                throw $this->fetchFailure;
            }

            $this->fetched[] = $url;
        });
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->status);

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return new UrlWarmer($factory, $this->logger, $timeout, $userAgent);
    }
}
