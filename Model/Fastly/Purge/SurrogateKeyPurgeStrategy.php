<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Fastly\Purger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;

/**
 * Purges the product's surrogate key.
 */
class SurrogateKeyPurgeStrategy implements PurgeStrategyInterface
{
    public function __construct(
        private readonly Purger $purger,
        private readonly string $keyPrefix = ''
    ) {
    }

    public function purgeForProduct(ProductInterface $product): int
    {
        $productId = (int) $product->getId();

        if ($productId === 0) {
            return 0;
        }

        $key = $this->keyPrefix . Product::CACHE_TAG . '_' . $productId;

        return $this->purger->purgeKey($key)->isSuccess ? 1 : 0;
    }
}
