<?php
/**
 * UrlPurgeStrategy.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Fastly\Purger;
use Commerce\CacheTools\Model\Product\FrontendUrlResolver;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Purges every store's frontend URL for the product.
 */
class UrlPurgeStrategy implements PurgeStrategyInterface
{
    public function __construct(
        private readonly FrontendUrlResolver $urlResolver,
        private readonly Purger $purger
    ) {
    }

    public function purgeForProduct(ProductInterface $product): int
    {
        $urls = $this->urlResolver->resolveForAllStores($product);

        if ($urls === []) {
            return 0;
        }

        $results = $this->purger->purgeUrls($urls);

        return count(array_filter($results, static fn ($result): bool => $result->isSuccess));
    }
}
