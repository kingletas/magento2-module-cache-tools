<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\ViewModel;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\ViewModel\CacheUrl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CacheUrlTest extends TestCase
{
    /** @var array<int, array{active: bool, baseUrl: string}> */
    private array $stores = [1 => ['active' => true, 'baseUrl' => 'https://shop.test/']];

    /** @var string[] */
    private array $extraUrls = [];

    private bool $enabled = true;
    private ?\Throwable $storeFailure = null;

    protected function setUp(): void
    {
        $this->stores = [1 => ['active' => true, 'baseUrl' => 'https://shop.test/']];
        $this->extraUrls = [];
        $this->enabled = true;
        $this->storeFailure = null;
    }

    public function testItIsUsableAsALayoutViewModel(): void
    {
        self::assertInstanceOf(ArgumentInterface::class, $this->viewModel());
    }

    public function testThePanelIsOffWhenFastlyIsDisabled(): void
    {
        self::assertTrue($this->viewModel()->isEnabled());

        $this->enabled = false;

        self::assertFalse($this->viewModel()->isEnabled());
    }

    /**
     * Every store's home page is what an operator most often wants to purge or
     * probe, so it is offered without anyone configuring it.
     */
    public function testEveryActiveStoresHomePageIsOffered(): void
    {
        $this->stores[2] = ['active' => true, 'baseUrl' => 'https://de.shop.test/'];

        self::assertSame(
            ['https://shop.test/', 'https://de.shop.test/'],
            $this->viewModel()->getCacheUrls()
        );
    }

    public function testAnInactiveStoreIsNotOffered(): void
    {
        $this->stores[2] = ['active' => false, 'baseUrl' => 'https://de.shop.test/'];

        self::assertSame(['https://shop.test/'], $this->viewModel()->getCacheUrls());
    }

    /**
     * A base URL is stored with or without its trailing slash depending on how
     * it was typed, and the two forms are different cache entries.
     */
    public function testTheBaseUrlsAreNormalisedToOneTrailingSlash(): void
    {
        $this->stores = [1 => ['active' => true, 'baseUrl' => 'https://shop.test']];

        self::assertSame(['https://shop.test/'], $this->viewModel()->getCacheUrls());
    }

    /**
     * A store's busiest page is rarely its home page; the extra URLs are where
     * a category landing page gets added.
     */
    public function testConfiguredExtraUrlsAreOfferedAlongsideTheStores(): void
    {
        $this->extraUrls = ['https://shop.test/scrubs.html'];

        self::assertSame(
            ['https://shop.test/', 'https://shop.test/scrubs.html'],
            $this->viewModel()->getCacheUrls()
        );
    }

    /**
     * An extra URL that repeats a store's home page would show twice in the
     * dropdown and purge twice if picked.
     */
    public function testADuplicateExtraUrlIsOfferedOnce(): void
    {
        $this->extraUrls = ['https://shop.test/'];

        self::assertSame(['https://shop.test/'], $this->viewModel()->getCacheUrls());
    }

    /**
     * A broken store configuration should not blank the whole panel: the
     * configured URLs are still worth offering.
     */
    public function testABrokenStoreConfigurationStillLeavesTheConfiguredUrls(): void
    {
        $this->storeFailure = new RuntimeException('store table is unreadable');
        $this->extraUrls = ['https://shop.test/scrubs.html'];

        self::assertSame(['https://shop.test/scrubs.html'], $this->viewModel()->getCacheUrls());
    }

    public function testTheJsConfigCarriesBothEndpointsAndTheUrls(): void
    {
        $config = (array) (new Json())->unserialize($this->viewModel()->getJsonConfig());

        self::assertSame('https://admin.test/commerce_cachetools/varnish/flush', $config['flushUrl']);
        self::assertSame('https://admin.test/commerce_cachetools/varnish/healthCheck', $config['healthUrl']);
        self::assertSame(['https://shop.test/'], $config['urls']);
    }

    public function testTheJsConfigIsJsonRatherThanAnArray(): void
    {
        self::assertJson($this->viewModel()->getJsonConfig());
    }

    private function viewModel(): CacheUrl
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturnCallback(
            function (): array {
                if ($this->storeFailure !== null) {
                    throw $this->storeFailure;
                }

                $stores = [];

                foreach ($this->stores as $storeId => $definition) {
                    $store = $this->createMock(Store::class);
                    $store->method('getId')->willReturn($storeId);
                    $store->method('isActive')->willReturn($definition['active']);
                    $store->method('getBaseUrl')->willReturn($definition['baseUrl']);
                    $stores[] = $store;
                }

                return $stores;
            }
        );

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route, ?array $params = null): string => 'https://admin.test/' . $route
        );

        $config = $this->createMock(Config::class);
        $config->method('isFastlyEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $config->method('getExtraCacheUrls')->willReturnCallback(fn (): array => $this->extraUrls);

        return new CacheUrl($storeManager, $urlBuilder, new Json(), $config);
    }
}
