<?php
/**
 * WarmTaskInterface.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Api;

use Commerce\CacheTools\Model\Warmer\WarmResult;

/**
 * Warms the caches for a batch of products.
 */
interface WarmTaskInterface
{
    /**
     * @param int[] $productIds
     */
    public function warm(array $productIds): WarmResult;
}
