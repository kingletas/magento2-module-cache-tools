<?php
/**
 * PurgeStrategyInterface.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * How a saved product should be evicted from the edge cache.
 */
interface PurgeStrategyInterface
{
    /**
     * @return int Number of purge requests issued.
     */
    public function purgeForProduct(ProductInterface $product): int;
}
