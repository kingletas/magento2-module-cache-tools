<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Environment;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Environment\EnvironmentPolicy;
use Commerce\CacheTools\Model\Environment\UrlHostResolver;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The guard in front of everything destructive in this module.
 */
class EnvironmentPolicyTest extends TestCase
{
    public function testBothSignalsAgreeingMeansProduction(): void
    {
        $this->assertTrue(
            $this->policy(declared: 'production', host: 'www.example.com', productionHost: 'www.example.com')
                ->isProduction()
        );
    }

    #[DataProvider('nonProductionCases')]
    public function testAnythingLessThanBothSignalsIsNotProduction(
        ?string $declared,
        string $host,
        string $productionHost
    ): void {
        $this->assertFalse($this->policy($declared, $host, $productionHost)->isProduction());
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function nonProductionCases(): array
    {
        return [
            'declared stage, host matches' => ['stage', 'www.example.com', 'www.example.com'],
            'declared production, host differs' => ['production', 'stage.example.com', 'www.example.com'],
            'nothing declared' => [null, 'www.example.com', 'www.example.com'],
            'declared empty' => ['', 'www.example.com', 'www.example.com'],
            'host unresolvable' => ['production', '', 'www.example.com'],
            'no production host configured' => ['production', 'www.example.com', ''],
            'neither signal' => [null, '', ''],
        ];
    }

    /**
     * The one that matters most.
     */
    public function testAnUnconfiguredProductionHostIsRefusedEvenWhenEnvPhpSaysProduction(): void
    {
        $this->assertFalse(
            $this->policy(declared: 'production', host: 'www.example.com', productionHost: '')
                ->isProduction()
        );
    }

    #[DataProvider('casings')]
    public function testTheDeclaredEnvironmentIsMatchedCaseAndWhitespaceInsensitively(string $declared): void
    {
        $this->assertTrue(
            $this->policy($declared, 'www.example.com', 'www.example.com')->isProduction()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function casings(): array
    {
        return [
            'lower' => ['production'],
            'upper' => ['PRODUCTION'],
            'mixed' => ['Production'],
            'padded' => ['  production  '],
        ];
    }

    /**
     * env.php being unreadable is a deployment fault, and the safe reading of a
     * fault is "not production".
     */
    public function testAnUnreadableDeploymentConfigIsNotProduction(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willThrowException(new RuntimeException('env.php is unreadable'));

        $this->assertFalse(
            $this->policy(
                host: 'www.example.com',
                productionHost: 'www.example.com',
                deploymentConfig: $deploymentConfig
            )->isProduction()
        );
    }

    /**
     * A store manager that throws must not abort the purge with a stack trace.
     */
    public function testAThrowingStoreManagerResolvesToAnUnknownHostRatherThanThrowing(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new RuntimeException('no store'));

        $policy = $this->policy(
            declared: 'production',
            productionHost: 'www.example.com',
            storeManager: $storeManager
        );

        $this->assertSame('', $policy->getHost());
        $this->assertFalse($policy->isProduction());
    }

    /**
     * The verdict is consulted repeatedly on a purge path, and both signals
     * involve I/O.
     */
    public function testTheVerdictIsComputedOnce(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->expects($this->once())->method('get')->willReturn('production');

        $policy = $this->policy(
            host: 'www.example.com',
            productionHost: 'www.example.com',
            deploymentConfig: $deploymentConfig
        );

        $policy->isProduction();
        $policy->isProduction();
        $policy->isProduction();
    }

    /**
     * `describe()` names what was seen, because it goes into the line
     * explaining a refusal.
     */
    public function testDescribeNamesEverySignalIncludingTheMissingOnes(): void
    {
        $description = $this->policy(declared: null, host: '', productionHost: '')->describe();

        $this->assertStringContainsString('environment=undeclared', $description);
        $this->assertStringContainsString('host=unknown', $description);
        $this->assertStringContainsString('expected-production-host=unconfigured', $description);
    }

    public function testDescribeCarriesTheRealValuesWhenThereAreSome(): void
    {
        $description = $this->policy('stage', 'stage.example.com', 'www.example.com')->describe();

        $this->assertStringContainsString('environment=stage', $description);
        $this->assertStringContainsString('host=stage.example.com', $description);
        $this->assertStringContainsString('expected-production-host=www.example.com', $description);
    }

    private function policy(
        ?string $declared = null,
        string $host = '',
        string $productionHost = '',
        ?DeploymentConfig $deploymentConfig = null,
        ?StoreManagerInterface $storeManager = null
    ): EnvironmentPolicy {
        if ($deploymentConfig === null) {
            $deploymentConfig = $this->createMock(DeploymentConfig::class);
            $deploymentConfig->method('get')->willReturn($declared);
        }

        if ($storeManager === null) {
            $store = $this->createMock(Store::class);
            $store->method('getBaseUrl')->willReturn($host === '' ? '' : 'https://' . $host . '/');

            $storeManager = $this->createMock(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }

        $config = $this->createMock(Config::class);
        $config->method('getProductionHost')->willReturn($productionHost);

        return new EnvironmentPolicy(
            $deploymentConfig,
            $storeManager,
            new UrlHostResolver(),
            $config
        );
    }
}
