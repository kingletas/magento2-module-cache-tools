<?php
/**
 * ConfigTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Test\Unit\Fake\ArrayScopeConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The section id is a di.xml argument, never a constant.
 */
class ConfigTest extends TestCase
{
    private const string SECTION = 'commerce_cachetools';

    private EncryptorInterface&MockObject $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value): string => 'decrypted:' . $value
        );
    }

    public function testPathsAreBuiltFromTheInjectedSection(): void
    {
        $config = $this->config(['acme_warming/fastly/enable' => '1'], 'acme_warming');

        $this->assertTrue($config->isFastlyEnabled());
    }

    /**
     * A secret is stored encrypted, so every reader has to decrypt.
     */
    public function testTheFastlyTokenIsDecrypted(): void
    {
        $config = $this->config([self::SECTION . '/fastly/token' => 'cipher']);

        $this->assertSame('decrypted:cipher', $config->getFastlyToken());
    }

    public function testAMissingFastlyTokenIsRefusedRatherThanReturnedEmpty(): void
    {
        $this->expectException(LocalizedException::class);

        $this->config([])->getFastlyToken();
    }

    /**
     * Non-throwing on purpose: callers fall back to discovery by service name,
     * so an unset id is a route rather than a fault.
     */
    public function testAnUnsetServiceIdIsEmptyRatherThanAnError(): void
    {
        $this->assertSame('', $this->config([])->getFastlyServiceId());
    }

    public function testTheServiceIdIsDecryptedWhenSet(): void
    {
        $config = $this->config([self::SECTION . '/fastly/service_id' => 'cipher']);

        $this->assertSame('decrypted:cipher', $config->getFastlyServiceId());
    }

    /**
     * "0" is false and "" is unset — neither is a PHP truthiness question.
     */
    public function testBatchSizesFallBackToTheirDefaultsWhenUnset(): void
    {
        $config = $this->config([]);

        $this->assertSame(Config::DEFAULT_BATCH_SIZE, $config->getSimpleBatchSize());
        $this->assertSame(Config::DEFAULT_BATCH_SIZE, $config->getConfigurableBatchSize());
        $this->assertSame(Config::DEFAULT_STALE_RUN_HOURS, $config->getStaleRunHours());
    }

    public function testAConfiguredBatchSizeWins(): void
    {
        $config = $this->config([self::SECTION . '/warmer/simple_batch_size' => '250']);

        $this->assertSame(250, $config->getSimpleBatchSize());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function config(array $values, string $section = self::SECTION): Config
    {
        return new Config(new ArrayScopeConfig($values), $section, $this->encryptor);
    }
}
