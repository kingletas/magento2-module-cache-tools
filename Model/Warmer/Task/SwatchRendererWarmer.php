<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default swatch warmer: renders the configurable's JSON config once so the
 * result lands in whatever cache the renderer writes to.
 */
class SwatchRendererWarmer implements SwatchCacheWarmerInterface
{
    public const string DEFAULT_BLOCK = \Magento\Swatches\Block\Product\Renderer\Configurable::class;

    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly LoggerInterface $logger,
        private readonly string $blockClass = self::DEFAULT_BLOCK
    ) {
    }

    public function warm(ProductInterface $product): bool
    {
        if (!class_exists($this->blockClass)) {
            return false;
        }

        try {
            $block = $this->layout->createBlock($this->blockClass);

            if (!method_exists($block, 'setProduct') || !method_exists($block, 'getJsonConfig')) {
                return false;
            }

            $block->setProduct($product);
            // The call is the point: rendering populates the cache.
            $block->getJsonConfig();

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Could not warm the swatch cache for SKU %s.', $product->getSku()),
                ['exception' => $e]
            );

            return false;
        }
    }
}
