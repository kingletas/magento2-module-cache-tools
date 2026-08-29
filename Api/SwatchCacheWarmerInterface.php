<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Populates whatever swatch/options cache a store's theme relies on.
 */
interface SwatchCacheWarmerInterface
{
    /**
     * @return bool Whether anything was warmed.
     */
    public function warm(ProductInterface $product): bool;
}
