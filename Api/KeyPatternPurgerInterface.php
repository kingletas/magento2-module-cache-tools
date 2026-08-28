<?php
/**
 * KeyPatternPurgerInterface.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Api;

/**
 * Removes cache entries whose backend key matches a pattern.
 */
interface KeyPatternPurgerInterface
{
    /**
     * Remove every cache entry whose key contains one of the given SKUs.
     *
     * @param string[] $skus
     *
     * @return int Number of SKUs processed (not keys removed).
     */
    public function purgeBySkus(array $skus): int;

    public function isSupported(): bool;
}
