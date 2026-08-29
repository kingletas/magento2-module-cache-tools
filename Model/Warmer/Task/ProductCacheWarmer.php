<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\WarmResult;
use Commerce\Foundation\Api\ProductImageUrlResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warms the image-url and swatch caches for a batch of products.
 */
class ProductCacheWarmer implements WarmTaskInterface
{
    /**
     * @param string   $productType   Product type this task warms.
     * @param string[] $imageTypes    Image roles to resolve.
     * @param bool     $warmSwatches  Whether the swatch cache applies to this type.
     * @param string[] $loadAttributes Attributes to select; keep this tight, the
     *                                 collection is loaded in full.
     */
    public function __construct(
        private readonly ActiveProductCollection $activeProducts,
        private readonly ProductImageUrlResolverInterface $imageUrlResolver,
        private readonly SwatchCacheWarmerInterface $swatchWarmer,
        private readonly LoggerInterface $logger,
        private readonly string $productType,
        private readonly array $imageTypes = [
            ProductImageUrlResolverInterface::IMAGE_SMALL,
            ProductImageUrlResolverInterface::IMAGE_LARGE,
            ProductImageUrlResolverInterface::IMAGE_THUMBNAIL,
        ],
        private readonly bool $warmSwatches = false,
        private readonly array $loadAttributes = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function warm(array $productIds): WarmResult
    {
        $collection = $this->activeProducts->forIds($this->productType, $productIds);

        if ($this->loadAttributes !== []) {
            $collection->addAttributeToSelect($this->loadAttributes);
        }

        // The stock-status filter is already implied by the ids we were handed,
        // and re-applying it makes the collection join the stock index again.
        $collection->setFlag('has_stock_status_filter', true);

        $warmed = 0;
        $messages = [];
        $items = $collection->getItems();

        foreach ($items as $product) {
            try {
                $this->warmProduct($product);
                $warmed++;
            } catch (Throwable $e) {
                // One unwarmable product must not abandon the rest of the batch.
                $message = sprintf(
                    'Failed warming %s product %s: %s',
                    $this->productType,
                    $product->getSku(),
                    $e->getMessage()
                );
                $this->logger->error($message, ['exception' => $e]);
                $messages[] = $message;
            }
        }

        return new WarmResult(count($items), $warmed, $messages);
    }

    private function warmProduct(ProductInterface $product): void
    {
        foreach ($this->imageTypes as $imageType) {
            // The resolver caches internally; the call is what populates it.
            $this->imageUrlResolver->resolveByProduct($product, $imageType);
        }

        if ($this->warmSwatches) {
            $this->swatchWarmer->warm($product);
        }
    }
}
