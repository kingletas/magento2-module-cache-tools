<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Model\Cache\RedisKeyPatternPurger;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Everything here stops short of an actual Redis connection: connecting is the
 * integration suite's job.
 */
final class RedisKeyPatternPurgerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $connectionOptions = [
        'server' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ];

    /** @var string[] Paths the deployment config was asked for. */
    private array $configLookups = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->connectionOptions = ['server' => '127.0.0.1', 'port' => 6379, 'database' => 0];
        $this->configLookups = [];
        $this->logger = new RecordingLogger();
    }

    public function testItSatisfiesThePurgerContract(): void
    {
        self::assertInstanceOf(KeyPatternPurgerInterface::class, $this->purger());
    }

    /**
     * An undeclared Redis connection is refused rather than guessed.
     */
    public function testAnUndeclaredConnectionIsRefusedRatherThanGuessed(): void
    {
        $this->connectionOptions = null;

        self::assertFalse($this->purger()->isSupported());
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('key-pattern purging is unavailable', $this->logger->errors[0]);
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
            $this->logger = new RecordingLogger();

            self::assertFalse($this->purger()->isSupported());
            self::assertCount(1, $this->logger->errors);
        }
    }

    /**
     * The path is a di.xml argument, because a store can declare its cache
     * Redis under a named frontend rather than `default`.
     */
    public function testTheDeploymentConfigPathIsConfigurable(): void
    {
        $this->connectionOptions = null;

        $this->purger('cache/frontend/page_cache/backend_options')->isSupported();

        self::assertSame(['cache/frontend/page_cache/backend_options'], $this->configLookups);
        self::assertStringContainsString('cache/frontend/page_cache', $this->logger->errors[0]);
    }

    /**
     * The failure is remembered, so a store with no Redis does not retry once
     * per save.
     */
    public function testAnUnusableConnectionIsOnlyReportedOnce(): void
    {
        $this->connectionOptions = null;
        $purger = $this->purger();

        $purger->isSupported();
        $purger->isSupported();
        $purger->purgeBySkus(['SKU-1']);

        self::assertCount(1, $this->logger->errors);
    }

    public function testNothingIsPurgedWithoutAUsableConnection(): void
    {
        $this->connectionOptions = null;

        self::assertSame(0, $this->purger()->purgeBySkus(['SKU-1']));
    }

    /**
     * An empty batch is answered before the deployment config is even read, so
     * a save with nothing to purge costs nothing.
     */
    public function testAnEmptyBatchIsAnsweredWithoutTouchingTheConfiguration(): void
    {
        self::assertSame(0, $this->purger()->purgeBySkus([]));
        self::assertSame(0, $this->purger()->purgeBySkus(['', '   ']));
        self::assertSame([], $this->configLookups);
    }

    /**
     * Magento upper-cases cache ids, so a lower-case SKU builds a pattern that
     * matches nothing and the entries survive the purge.
     */
    public function testSkusAreUpperCasedToMatchMagentosCacheIds(): void
    {
        self::assertSame(['SKU-1'], $this->normalise([' sku-1 ']));
    }

    public function testDuplicateAndBlankSkusAreDropped(): void
    {
        self::assertSame(['SKU-1', 'SKU-2'], $this->normalise(['SKU-1', 'sku-1', '', '  ', 'SKU-2']));
    }

    /**
     * SKUs are kept in the array value: PHP casts a numeric-string key to int.
     */
    public function testANumericSkuSurvivesDeduplicationAsAString(): void
    {
        $normalised = $this->normalise(['1000', '1000']);

        self::assertSame(['1000'], $normalised);
        self::assertIsString($normalised[0]);
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
