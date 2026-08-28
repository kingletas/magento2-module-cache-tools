<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Product;

use Commerce\CacheTools\Model\Product\FrontendUrlResolver;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrontendUrlResolverTest extends TestCase
{
    /** @var array<int, array<string, mixed>> Lookups the finder was asked for. */
    private array $lookups = [];

    /** @var array<string, string> "productId:storeId" => request path. */
    private array $rewrites = ['10:1' => 'scrub-top.html'];

    /** @var array<int, array{active: bool, baseUrl: string}> */
    private array $stores = [
        0 => ['active' => true, 'baseUrl' => 'https://admin.test/'],
        1 => ['active' => true, 'baseUrl' => 'https://shop.test/'],
    ];

    private ?int $requestedStore = null;
    private ?\Throwable $storeFailure = null;
    private ?\Throwable $finderFailure = null;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->lookups = [];
        $this->rewrites = ['10:1' => 'scrub-top.html'];
        $this->stores = [
            0 => ['active' => true, 'baseUrl' => 'https://admin.test/'],
            1 => ['active' => true, 'baseUrl' => 'https://shop.test/'],
        ];
        $this->requestedStore = null;
        $this->storeFailure = null;
        $this->finderFailure = null;
        $this->logger = new RecordingLogger();
    }

    public function testTheStorefrontUrlIsBuiltFromTheRewriteAndTheStoresBaseUrl(): void
    {
        self::assertSame(
            'https://shop.test/scrub-top.html',
            $this->resolver()->resolve($this->product(), 1)
        );
    }

    /**
     * `Product::getProductUrl()` falls back to an admin route, which leaks the
     * secret key.
     */
    public function testTheAdminScopeFallsBackToARealStoreViewRatherThanAnAdminUrl(): void
    {
        $url = $this->resolver()->resolve($this->product(), 0);

        self::assertSame('https://shop.test/scrub-top.html', $url);
        self::assertStringNotContainsString('admin', $url);
    }

    /**
     * 301/302 rewrites are left behind by URL-key changes; they redirect rather
     * than serve, so purging one achieves nothing.
     */
    public function testOnlyANonRedirectingProductRewriteIsLookedUp(): void
    {
        $this->resolver()->resolve($this->product(), 1);

        self::assertSame(
            [
                UrlRewrite::ENTITY_ID => 10,
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::STORE_ID => 1,
                UrlRewrite::REDIRECT_TYPE => 0,
            ],
            $this->lookups[0]
        );
    }

    /**
     * The base URL and the request path are joined with exactly one slash,
     * whichever way each half is stored.
     */
    public function testTheUrlIsJoinedWithOneSlash(): void
    {
        $this->rewrites = ['10:1' => '/scrub-top.html'];

        self::assertSame('https://shop.test/scrub-top.html', $this->resolver()->resolve($this->product(), 1));
    }

    /**
     * A product with no rewrite has no page to purge; returning something
     * plausible would spend a purge request on a URL that 404s.
     */
    public function testAProductWithNoRewriteResolvesToNothing(): void
    {
        $this->rewrites = [];

        self::assertSame('', $this->resolver()->resolve($this->product(), 1));
        self::assertCount(1, $this->logger->infos);
    }

    public function testAStoreThatDoesNotResolveGivesNoUrl(): void
    {
        $this->storeFailure = new NoSuchEntityException(__('No such store.'));

        self::assertSame('', $this->resolver()->resolve($this->product(), 99));
    }

    /**
     * An install with only the admin store has no frontend URL to purge.
     */
    public function testAnInstallWithNoActiveStoreViewGivesNoUrl(): void
    {
        $this->stores = [0 => ['active' => true, 'baseUrl' => 'https://admin.test/']];

        self::assertSame('', $this->resolver()->resolve($this->product(), 0));
    }

    /**
     * Resolving a URL is housekeeping for a purge; a broken rewrite table must
     * not take the product save with it.
     */
    public function testAFailingRewriteLookupIsContainedAndLogged(): void
    {
        $this->finderFailure = new RuntimeException('rewrite table is missing');

        self::assertSame('', $this->resolver()->resolve($this->product(), 1));
        self::assertCount(1, $this->logger->warnings);
    }

    /**
     * A product visible on several store views has a page per view, and purging
     * only one leaves the others serving the old copy.
     */
    public function testEveryActiveStoresUrlIsResolved(): void
    {
        $this->stores[2] = ['active' => true, 'baseUrl' => 'https://de.shop.test/'];
        $this->rewrites['10:2'] = 'kittel.html';

        self::assertSame(
            ['https://shop.test/scrub-top.html', 'https://de.shop.test/kittel.html'],
            $this->resolver()->resolveForAllStores($this->product())
        );
    }

    /**
     * An inactive store view serves nobody, and purging its URL spends a
     * request on a page no shopper can reach.
     */
    public function testAnInactiveStoreIsSkipped(): void
    {
        $this->stores[2] = ['active' => false, 'baseUrl' => 'https://de.shop.test/'];
        $this->rewrites['10:2'] = 'kittel.html';

        self::assertSame(
            ['https://shop.test/scrub-top.html'],
            $this->resolver()->resolveForAllStores($this->product())
        );
    }

    /**
     * Store views sharing a base URL and a rewrite resolve to one page, purged
     * once.
     */
    public function testStoresResolvingToTheSameUrlArePurgedOnce(): void
    {
        $this->stores[2] = ['active' => true, 'baseUrl' => 'https://shop.test/'];
        $this->rewrites['10:2'] = 'scrub-top.html';

        self::assertSame(
            ['https://shop.test/scrub-top.html'],
            $this->resolver()->resolveForAllStores($this->product())
        );
    }

    public function testAStoreTheProductIsNotInContributesNoUrl(): void
    {
        $this->stores[2] = ['active' => true, 'baseUrl' => 'https://de.shop.test/'];

        self::assertSame(
            ['https://shop.test/scrub-top.html'],
            $this->resolver()->resolveForAllStores($this->product())
        );
    }

    private function resolver(): FrontendUrlResolver
    {
        $urlFinder = $this->createMock(UrlFinderInterface::class);
        $urlFinder->method('findOneByData')->willReturnCallback(
            function (array $data): ?UrlRewrite {
                if ($this->finderFailure !== null) {
                    throw $this->finderFailure;
                }

                $this->lookups[] = $data;
                $key = $data[UrlRewrite::ENTITY_ID] . ':' . $data[UrlRewrite::STORE_ID];

                if (!isset($this->rewrites[$key])) {
                    return null;
                }

                $rewrite = $this->createMock(UrlRewrite::class);
                $rewrite->method('getRequestPath')->willReturn($this->rewrites[$key]);

                return $rewrite;
            }
        );

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function ($storeId = null): Store {
                if ($this->storeFailure !== null) {
                    throw $this->storeFailure;
                }

                $id = (int) ($storeId ?? 1);

                if (!isset($this->stores[$id])) {
                    throw new NoSuchEntityException(__('No such store.'));
                }

                return $this->store($id);
            }
        );
        $storeManager->method('getStores')->willReturnCallback(
            function (): array {
                $stores = [];

                foreach (array_keys($this->stores) as $storeId) {
                    if ($storeId !== 0) {
                        $stores[] = $this->store($storeId);
                    }
                }

                return $stores;
            }
        );

        return new FrontendUrlResolver($urlFinder, $storeManager, $this->logger);
    }

    private function store(int $storeId): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('isActive')->willReturn($this->stores[$storeId]['active']);
        $store->method('getBaseUrl')->willReturn($this->stores[$storeId]['baseUrl']);

        return $store;
    }

    private function product(): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);
        $product->method('getSku')->willReturn('SKU-1');

        return $product;
    }
}
