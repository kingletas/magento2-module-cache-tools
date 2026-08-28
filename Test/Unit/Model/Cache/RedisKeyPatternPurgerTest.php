<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Model\Cache\RedisKeyPatternPurger;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Everything here stops short of an actual Redis connection: connecting is the
 * integration suite's job.
 */
class RedisKeyPatternPurgerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $connectionOptions = [
        'server' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ];

    /** @var string[] Paths the deployment config was asked for. */
    private array $configLookups = [];

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connectionOptions = ['server' => '127.0.0.1', 'port' => 6379, 'database' => 0];
        $this->configLookups = [];
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testItSatisfiesThePurgerContract(): void
    {
        $this->assertInstanceOf(KeyPatternPurgerInterface::class, $this->purger());
    }

    /**
     * An undeclared Redis connection is refused rather than guessed.
     */
    public function testAnUndeclaredConnectionIsRefusedRatherThanGuessed(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('key-pattern purging is unavailable'));

        $this->connectionOptions = null;

        $this->assertFalse($this->purger()->isSupported());
    }

    /**
     * A missing database index would default to 0 and purge whichever keyspace
     * is there.
     */
    public function testAPartlyDeclaredConnectionIsRefusedToo(): void
    {
        $halfDeclared = [
            ['port' => 6379, 'database' => 0],
            ['server' => 'h', 'database' => 0],
            ['server' => 'h', 'port' => 1],
        ];

        foreach ($halfDeclared as $options) {
            $this->connectionOptions = $options;
            $this->logger = $this->createMock(LoggerInterface::class);
            $this->logger->expects($this->once())->method('error');

            $this->assertFalse($this->purger()->isSupported());
        }
    }

    /**
     * The path is a di.xml argument, because a store can declare its cache
     * Redis under a named frontend rather than `default`.
     */
    public function testTheDeploymentConfigPathIsConfigurable(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('cache/frontend/page_cache'));

        $this->connectionOptions = null;

        $this->purger('cache/frontend/page_cache/backend_options')->isSupported();

        $this->assertSame(['cache/frontend/page_cache/backend_options'], $this->configLookups);
    }

    /**
     * The failure is remembered, so a store with no Redis does not retry once
     * per save.
     */
    public function testAnUnusableConnectionIsOnlyReportedOnce(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->connectionOptions = null;
        $purger = $this->purger();

        $purger->isSupported();
        $purger->isSupported();
        $purger->purgeBySkus(['SKU-1']);
    }

    public function testNothingIsPurgedWithoutAUsableConnection(): void
    {
        $this->connectionOptions = null;

        $this->assertSame(0, $this->purger()->purgeBySkus(['SKU-1']));
    }

    /**
     * An empty batch is answered before the deployment config is even read, so
     * a save with nothing to purge costs nothing.
     */
    public function testAnEmptyBatchIsAnsweredWithoutTouchingTheConfiguration(): void
    {
        $this->assertSame(0, $this->purger()->purgeBySkus([]));
        $this->assertSame(0, $this->purger()->purgeBySkus(['', '   ']));
        $this->assertSame([], $this->configLookups);
    }

    /**
     * Magento upper-cases cache ids, so a lower-case SKU builds a pattern that
     * matches nothing and the entries survive the purge.
     */
    public function testSkusAreUpperCasedToMatchMagentosCacheIds(): void
    {
        $this->assertSame(['SKU-1'], $this->normalise([' sku-1 ']));
    }

    public function testDuplicateAndBlankSkusAreDropped(): void
    {
        $this->assertSame(['SKU-1', 'SKU-2'], $this->normalise(['SKU-1', 'sku-1', '', '  ', 'SKU-2']));
    }

    /**
     * SKUs are kept in the array value: PHP casts a numeric-string key to int.
     */
    public function testANumericSkuSurvivesDeduplicationAsAString(): void
    {
        $normalised = $this->normalise(['1000', '1000']);

        $this->assertSame(['1000'], $normalised);
        $this->assertIsString($normalised[0]);
    }

    /**
     * @param string[] $skus
     *
     * @return string[]
     */
    private function normalise(array $skus): array
    {
        $method = new ReflectionMethod(RedisKeyPatternPurger::class, 'normalise');

        return $method->invoke($this->purger(), $skus);
    }

    private function purger(string $path = 'cache/frontend/default/backend_options'): RedisKeyPatternPurger
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            function (?string $key = null, $defaultValue = null) {
                $this->configLookups[] = (string) $key;

                return $this->connectionOptions;
            }
        );

        return new RedisKeyPatternPurger($deploymentConfig, $this->logger, $path);
    }
}
