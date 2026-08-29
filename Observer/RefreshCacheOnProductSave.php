<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Observer;

use Commerce\CacheTools\Model\Product\ProductSaveCacheRefresher;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class RefreshCacheOnProductSave implements ObserverInterface
{
    public function __construct(
        private readonly ProductSaveCacheRefresher $refresher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');

        if (!$product instanceof ProductInterface) {
            return;
        }

        try {
            $this->refresher->refresh($product);
        } catch (Throwable $e) {
            // Never let cache housekeeping fail the product save that triggered
            // it: the save has already committed.
            $this->logger->error(
                sprintf('Cache refresh failed after saving SKU %s.', $product->getSku()),
                ['exception' => $e]
            );
        }
    }
}
