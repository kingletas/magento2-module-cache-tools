<?php
/**
 * NullSwatchCacheWarmer.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * For stores with no swatch cache to warm.
 */
class NullSwatchCacheWarmer implements SwatchCacheWarmerInterface
{
    public function warm(ProductInterface $product): bool
    {
        return false;
    }
}
