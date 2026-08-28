<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Fastly\Purge\UrlPurgeStrategy;
use Commerce\CacheTools\Model\Fastly\PurgeResult;
use Commerce\CacheTools\Model\Fastly\Purger;
use Commerce\CacheTools\Model\Product\FrontendUrlResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UrlPurgeStrategyTest extends TestCase
{
    /** @var string[] */
    private array $urls = ['https://shop.test/scrub-top.html'];

    /** @var array<int, string[]> */
    private array $purged = [];

    /** @var array<string, bool> URL => whether the purge succeeded. */
    private array $outcomes = [];

    protected function setUp(): void
    {
        $this->urls = ['https://shop.test/scrub-top.html'];
        $this->purged = [];
        $this->outcomes = [];
    }

    public function testItIsOneStrategyAmongOthers(): void
    {
        $this->assertInstanceOf(PurgeStrategyInterface::class, $this->strategy());
    }

    /**
     * A product visible on several store views has a URL per view, and purging
     * only one leaves the others serving the old page.
     */
    public function testEveryStoresUrlIsPurgedInOneCall(): void
    {
        $this->urls = ['https://uk.shop.test/scrub-top.html', 'https://de.shop.test/kittel.html'];

        $this->assertSame(2, $this->strategy()->purgeForProduct($this->product()));
        $this->assertSame([$this->urls], $this->purged);
    }

    /**
     * The count is what the caller reports, so a URL the CDN refused must not
     * be counted as purged.
     */
    public function testOnlyTheUrlsTheCdnAcceptedAreCounted(): void
    {
        $this->urls = ['https://shop.test/a.html', 'https://shop.test/b.html'];
        $this->outcomes = ['https://shop.test/b.html' => false];

        $this->assertSame(1, $this->strategy()->purgeForProduct($this->product()));
    }

    /**
     * A product with no storefront URL has nothing to purge, and no request is
     * made.
     */
    public function testAProductWithNoUrlsIsNotPurged(): void
    {
        $this->urls = [];

        $this->assertSame(0, $this->strategy()->purgeForProduct($this->product()));
        $this->assertSame([], $this->purged);
    }

    private function strategy(): UrlPurgeStrategy
    {
        $resolver = $this->createMock(FrontendUrlResolver::class);
        $resolver->method('resolveForAllStores')->willReturnCallback(fn (): array => $this->urls);

        $purger = $this->createMock(Purger::class);
        $purger->method('purgeUrls')->willReturnCallback(
            function (array $urls): array {
                $this->purged[] = $urls;

                return array_map(
                    fn (string $url): PurgeResult => new PurgeResult(
                        $url,
                        $this->outcomes[$url] ?? true,
                        __('Purged.')
                    ),
                    $urls
                );
            }
        );

        return new UrlPurgeStrategy($resolver, $purger);
    }

    private function product(): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);

        return $product;
    }
}
