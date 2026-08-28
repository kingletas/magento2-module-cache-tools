<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\FastlyClientFactory;
use Commerce\CacheTools\Model\Fastly\PurgeGuard;
use Commerce\CacheTools\Model\Fastly\Purger;
use Commerce\CacheTools\Model\Fastly\ServiceIdProvider;
use Commerce\CacheTools\Model\Warmer\RewarmPublisher;
use Fastly\Api\PurgeApi;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PurgerTest extends TestCase
{
    /** @var array<int, array{call: string, options: array<string, mixed>}> */
    private array $calls = [];

    /** @var string[] */
    private array $rewarmed = [];

    private bool $enabled = true;
    private bool $softDefault = true;
    private ?Phrase $blockReason = null;
    private ?\Throwable $apiFailure = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->rewarmed = [];
        $this->enabled = true;
        $this->softDefault = true;
        $this->blockReason = null;
        $this->apiFailure = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testAUrlIsPurgedAndReportedAsSuccess(): void
    {
        $result = $this->purger()->purgeUrl('https://shop.test/scrub-top.html');

        $this->assertTrue($result->isSuccess);
        $this->assertSame('purgeSingleUrl', $this->calls[0]['call']);
        $this->assertSame('https://shop.test/scrub-top.html', $this->calls[0]['options']['cached_url']);
    }

    /**
     * A soft purge marks objects stale rather than evicting them, so shoppers
     * keep getting a fast stale response while the origin revalidates.
     */
    public function testPurgesAreSoftByDefault(): void
    {
        $this->purger()->purgeUrl('https://shop.test/a.html');

        $this->assertArrayHasKey('fastly_soft_purge', $this->calls[0]['options']);
    }

    public function testAHardPurgeCanBeAskedForExplicitly(): void
    {
        $this->purger()->purgeUrl('https://shop.test/a.html', soft: false);

        $this->assertArrayNotHasKey('fastly_soft_purge', $this->calls[0]['options']);
    }

    public function testTheConfiguredDefaultIsUsedWhenTheCallerDoesNotSay(): void
    {
        $this->softDefault = false;

        $this->purger()->purgeUrl('https://shop.test/a.html');

        $this->assertArrayNotHasKey('fastly_soft_purge', $this->calls[0]['options']);
    }

    /**
     * Refilling on a worker means the next shopper does not pay for the miss,
     * and this request thread does not block on a page load.
     */
    public function testASuccessfulUrlPurgeQueuesARewarm(): void
    {
        $this->purger()->purgeUrl('https://shop.test/scrub-top.html');

        $this->assertSame(['https://shop.test/scrub-top.html'], $this->rewarmed);
    }

    public function testAFailedPurgeQueuesNoRewarm(): void
    {
        $this->apiFailure = new RuntimeException('Fastly returned 503');

        $this->purger()->purgeUrl('https://shop.test/scrub-top.html');

        $this->assertSame([], $this->rewarmed);
    }

    /**
     * Purging a malformed URL cannot succeed, and spending an API call to find
     * that out is a round trip for nothing.
     */
    public function testAMalformedUrlIsRefusedBeforeAnyApiCall(): void
    {
        $result = $this->purger()->purgeUrl('not a url');

        $this->assertFalse($result->isSuccess);
        $this->assertSame([], $this->calls);
    }

    /**
     * The guard is what stops a staging box purging production's cache.
     */
    public function testAGuardedUrlIsRefusedBeforeAnyApiCall(): void
    {
        $this->blockReason = __('This host is not purgeable from this environment.');

        $result = $this->purger()->purgeUrl('https://production.test/a.html');

        $this->assertFalse($result->isSuccess);
        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('not purgeable', (string) $result->message);
    }

    public function testNothingIsPurgedWhileFastlyIsDisabled(): void
    {
        $this->enabled = false;
        $purger = $this->purger();

        $this->assertFalse($purger->purgeUrl('https://shop.test/a.html')->isSuccess);
        $this->assertFalse($purger->purgeKeys(['cat_p_10'])->isSuccess);
        $this->assertFalse($purger->purgeAll(true)->isSuccess);
        $this->assertSame([], $this->calls);
    }

    public function testEachUrlInABatchGetsItsOwnResult(): void
    {
        $results = $this->purger()->purgeUrls(['https://shop.test/a.html', 'not a url']);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccess);
        $this->assertFalse($results[1]->isSuccess);
    }

    public function testASingleKeyIsPurgedThroughTheBulkEndpoint(): void
    {
        $result = $this->purger()->purgeKey('cat_p_10');

        $this->assertTrue($result->isSuccess);
        $this->assertSame('bulkPurgeTag', $this->calls[0]['call']);
        $this->assertSame('cat_p_10', $this->calls[0]['options']['surrogate_key']);
    }

    /**
     * Fastly rejects a bulk purge over 256 keys outright, so the batches stay
     * under it.
     */
    public function testKeysAreChunkedToFastlysPerRequestLimit(): void
    {
        $keys = array_map(static fn (int $i): string => 'cat_p_' . $i, range(1, 600));

        $result = $this->purger()->purgeKeys($keys);

        $this->assertTrue($result->isSuccess);
        $this->assertCount(3, $this->calls);

        foreach ($this->calls as $call) {
            $this->assertLessThanOrEqual(256, count(explode(' ', $call['options']['surrogate_key'])));
        }
    }

    public function testDuplicateAndBlankKeysAreDroppedBeforePurging(): void
    {
        $this->purger()->purgeKeys(['cat_p_10', ' cat_p_10 ', '', 'cat_p_11']);

        $this->assertSame('cat_p_10 cat_p_11', $this->calls[0]['options']['surrogate_key']);
    }

    public function testPurgingNoKeysIsRefusedWithoutAnApiCall(): void
    {
        $result = $this->purger()->purgeKeys(['', '  ']);

        $this->assertFalse($result->isSuccess);
        $this->assertSame([], $this->calls);
    }

    public function testTheKeyPurgeIsScopedToTheResolvedService(): void
    {
        $this->purger()->purgeKeys(['cat_p_10']);

        $this->assertSame('svc_123', $this->calls[0]['options']['service_id']);
    }

    /**
     * Purge-all refuses unless the caller says so explicitly, and the refusal
     * is logged.
     */
    public function testPurgeAllRefusesWithoutExplicitConfirmation(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $result = $this->purger()->purgeAll();

        $this->assertFalse($result->isSuccess);
        $this->assertSame([], $this->calls);
    }

    public function testAConfirmedPurgeAllRunsAndIsWarnedAbout(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $result = $this->purger()->purgeAll(true);

        $this->assertTrue($result->isSuccess);
        $this->assertSame('purgeAll', $this->calls[0]['call']);
    }

    /**
     * A purge-all is never soft: Fastly does not support it, and sending the
     * header would have the request rejected.
     */
    public function testAPurgeAllIsNeverSoft(): void
    {
        $this->purger()->purgeAll(true);

        $this->assertArrayNotHasKey('fastly_soft_purge', $this->calls[0]['options']);
    }

    public function testAGuardedPurgeAllIsRefused(): void
    {
        $this->blockReason = __('This service is not purgeable from this environment.');

        $this->assertFalse($this->purger()->purgeAll(true)->isSuccess);
        $this->assertSame([], $this->calls);
    }

    /**
     * The admin sees a stable sentence and the exception detail goes to the
     * log: interpolating the raw message puts API responses on screen.
     */
    public function testAFailedPurgeReportsAStableSentenceAndLogsTheDetail(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->apiFailure = new RuntimeException('403 Forbidden: invalid token tok_secret');

        $result = $this->purger()->purgeUrl('https://shop.test/a.html');

        $this->assertFalse($result->isSuccess);
        $this->assertStringNotContainsString('tok_secret', (string) $result->message);
        $this->assertStringContainsString('see the log', (string) $result->message);
    }

    private function purger(): Purger
    {
        $config = $this->createMock(Config::class);
        $config->method('isFastlyEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $config->method('isSoftPurgeDefault')->willReturnCallback(fn (): bool => $this->softDefault);
        $config->method('getFastlyServiceName')->willReturn('shop-production');

        $api = $this->createMock(PurgeApi::class);

        foreach (['purgeSingleUrl', 'bulkPurgeTag', 'purgeAll'] as $method) {
            $api->method($method)->willReturnCallback(
                function (array $options = []) use ($method) {
                    if ($this->apiFailure !== null) {
                        throw $this->apiFailure;
                    }

                    $this->calls[] = ['call' => $method, 'options' => $options];

                    return null;
                }
            );
        }

        $clientFactory = $this->createMock(FastlyClientFactory::class);
        $clientFactory->method('createPurgeApi')->willReturn($api);

        $serviceIdProvider = $this->createMock(ServiceIdProvider::class);
        $serviceIdProvider->method('get')->willReturn('svc_123');

        $guard = $this->createMock(PurgeGuard::class);
        $guard->method('blockReasonForUrl')->willReturnCallback(fn (): ?Phrase => $this->blockReason);
        $guard->method('blockReasonForService')->willReturnCallback(fn (): ?Phrase => $this->blockReason);

        $rewarmPublisher = $this->createMock(RewarmPublisher::class);
        $rewarmPublisher->method('publish')->willReturnCallback(function (string $url): void {
            $this->rewarmed[] = $url;
        });

        return new Purger(
            $config,
            $clientFactory,
            $serviceIdProvider,
            $guard,
            $rewarmPublisher,
            $this->logger
        );
    }
}
