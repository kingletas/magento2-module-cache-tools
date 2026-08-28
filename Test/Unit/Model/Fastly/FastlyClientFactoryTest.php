<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\FastlyClientFactory;
use Fastly\Api\PurgeApi;
use Fastly\Api\ServiceApi;
use Fastly\Configuration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

final class FastlyClientFactoryTest extends TestCase
{
    /** @var Configuration[] */
    private array $configurations = [];

    private int $purgeApisBuilt = 0;
    private int $serviceApisBuilt = 0;
    private string $token = 'tok_fastly';

    protected function setUp(): void
    {
        $this->configurations = [];
        $this->purgeApisBuilt = 0;
        $this->serviceApisBuilt = 0;
        $this->token = 'tok_fastly';
    }

    public function testTheClientsAreAuthenticatedWithTheConfiguredToken(): void
    {
        $this->factory()->createPurgeApi();

        self::assertCount(1, $this->configurations);
        self::assertSame('tok_fastly', $this->configurations[0]->getApiToken());
    }

    /**
     * The whole reason this class exists.
     */
    public function testEachClientGetsItsOwnConfigurationRatherThanTheSdkSingleton(): void
    {
        $shared = Configuration::getDefaultConfiguration();
        $sharedTokenBefore = $shared->getApiToken();

        $factory = $this->factory();
        $factory->createPurgeApi();
        $factory->createServiceApi();

        self::assertNotSame($shared, $this->configurations[0]);
        self::assertSame($sharedTokenBefore, Configuration::getDefaultConfiguration()->getApiToken());
    }

    /**
     * Two clients, two configurations: sharing one between them would put the
     * two APIs back on a single mutable object.
     */
    public function testThePurgeAndServiceClientsDoNotShareAConfiguration(): void
    {
        $factory = $this->factory();
        $factory->createPurgeApi();
        $factory->createServiceApi();

        self::assertCount(2, $this->configurations);
        self::assertNotSame($this->configurations[0], $this->configurations[1]);
    }

    /**
     * A purge storm asks for the client once per URL; rebuilding an HTTP client
     * each time discards the connection pool.
     */
    public function testEachClientIsBuiltOncePerFactory(): void
    {
        $factory = $this->factory();

        $factory->createPurgeApi();
        $factory->createPurgeApi();
        $factory->createServiceApi();
        $factory->createServiceApi();

        self::assertSame(1, $this->purgeApisBuilt);
        self::assertSame(1, $this->serviceApisBuilt);
    }

    public function testTheSameClientInstanceComesBackOnEveryCall(): void
    {
        $factory = $this->factory();

        self::assertSame($factory->createPurgeApi(), $factory->createPurgeApi());
        self::assertSame($factory->createServiceApi(), $factory->createServiceApi());
    }

    /**
     * The promise this module's README makes: Fastly is optional, and a store
     * without the SDK installs and runs.
     */
    public function testAStoreWithoutTheSdkIsToldWhichPackageToInstall(): void
    {
        $factory = $this->factory(purgeApiClass: 'Fastly\\Api\\NotInstalledPurgeApi');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/fastly\/fastly/');

        $factory->createPurgeApi();
    }

    public function testTheServiceClientRefusesTheSameWay(): void
    {
        $factory = $this->factory(serviceApiClass: 'Fastly\\Api\\NotInstalledServiceApi');

        $this->expectException(LocalizedException::class);

        $factory->createServiceApi();
    }

    /**
     * The quiet direction.
     */
    public function testWithTheSdkPresentNothingIsRefused(): void
    {
        $factory = $this->factory();

        self::assertInstanceOf(PurgeApi::class, $factory->createPurgeApi());
        self::assertInstanceOf(ServiceApi::class, $factory->createServiceApi());
    }

    private function factory(
        ?string $purgeApiClass = null,
        ?string $serviceApiClass = null
    ): FastlyClientFactory {
        $config = $this->createMock(Config::class);
        $config->method('getFastlyToken')->willReturnCallback(fn (): string => $this->token);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            function (string $class, array $arguments = []): object {
                // The SDK takes its configuration by name, so a positional call
                // would misplace it.
                self::assertArrayHasKey('config', $arguments);
                $this->configurations[] = $arguments['config'];

                if ($class === PurgeApi::class) {
                    $this->purgeApisBuilt++;

                    return $this->createMock(PurgeApi::class);
                }

                $this->serviceApisBuilt++;

                return $this->createMock(ServiceApi::class);
            }
        );

        return new FastlyClientFactory(
            $config,
            $objectManager,
            $purgeApiClass ?? PurgeApi::class,
            $serviceApiClass ?? ServiceApi::class
        );
    }
}
