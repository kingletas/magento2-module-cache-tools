<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\FastlyClientFactory;
use Commerce\CacheTools\Model\Fastly\ServiceIdProvider;
use Fastly\Api\ServiceApi;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServiceIdProviderTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $searches = [];

    private string $configuredId = '';
    private string $configuredName = 'shop-production';
    private string $discoveredId = 'svc_123';
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->searches = [];
        $this->configuredId = '';
        $this->configuredName = 'shop-production';
        $this->discoveredId = 'svc_123';
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * A configured id is the operator's explicit choice and costs no API call.
     */
    public function testAConfiguredIdIsUsedWithoutAskingFastly(): void
    {
        $this->configuredId = 'svc_configured';

        $this->assertSame('svc_configured', $this->provider()->get());
        $this->assertSame([], $this->searches);
    }

    public function testAnUnconfiguredIdIsDiscoveredByServiceName(): void
    {
        $this->assertSame('svc_123', $this->provider()->get());
        $this->assertSame([['name' => 'shop-production']], $this->searches);
    }

    /**
     * A purge storm resolves the service once per URL; discovering it each time
     * spends an API call before every purge.
     */
    public function testTheDiscoveredIdIsMemoisedForTheRequest(): void
    {
        $provider = $this->provider();

        $provider->get();
        $provider->get();

        $this->assertCount(1, $this->searches);
    }

    /**
     * A discovered service id is logged, because it is the first thing to
     * check.
     */
    public function testADiscoveryIsRecordedWithBothTheNameAndTheId(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(
                static fn (string $message): bool=> str_contains($message, 'shop-production')
                    && str_contains($message, 'svc_123')
            ));

        $this->provider()->get();
    }

    /**
     * Neither setting configured is a misconfiguration, not something to guess
     * at - purging the wrong service is worse than not purging.
     */
    public function testWithNeitherAnIdNorANameTheProviderRefuses(): void
    {
        $this->configuredName = '';

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no service name is set');

        $this->provider()->get();
    }

    /**
     * A service name that matches nothing is refused rather than reported as a
     * successful purge.
     */
    public function testANameThatMatchesNoServiceIsRefused(): void
    {
        $this->discoveredId = '';

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('shop-production');

        $this->provider()->get();
    }

    private function provider(): ServiceIdProvider
    {
        $config = $this->createMock(Config::class);
        $config->method('getFastlyServiceId')->willReturnCallback(fn (): string => $this->configuredId);
        $config->method('getFastlyServiceName')->willReturnCallback(fn (): string => $this->configuredName);

        $service = new class ($this->discoveredId) {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }
        };

        $serviceApi = $this->createMock(ServiceApi::class);
        $serviceApi->method('searchService')->willReturnCallback(
            function (array $options = []) use ($service) {
                $this->searches[] = $options;

                return $service;
            }
        );

        $clientFactory = $this->createMock(FastlyClientFactory::class);
        $clientFactory->method('createServiceApi')->willReturn($serviceApi);

        return new ServiceIdProvider($config, $clientFactory, $this->logger);
    }
}
