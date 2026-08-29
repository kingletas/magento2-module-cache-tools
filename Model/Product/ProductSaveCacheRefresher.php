<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Product;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\Purge\PurgeStrategyPool;
use Commerce\Foundation\Api\ConfigurableParentSkuResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evicts a saved product from every cache that holds it.
 */
class ProductSaveCacheRefresher
{
    public function __construct(
        private readonly PurgeStrategyPool $strategyPool,
        private readonly KeyPatternPurgerInterface $keyPatternPurger,
        private readonly ConfigurableParentSkuResolverInterface $parentSkuResolver,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function refresh(ProductInterface $product): void
    {
        $sku = (string) $product->getSku();

        if ($sku === '') {
            return;
        }

        $this->purgeEdge($product);
        $this->purgeKeyed($sku);
    }

    private function purgeEdge(ProductInterface $product): void
    {
        if (!$this->config->isFastlyEnabled()) {
            return;
        }

        try {
            $this->strategyPool->get()?->purgeForProduct($product);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Edge purge failed for SKU %s.', $product->getSku()),
                ['exception' => $e]
            );
        }
    }

    private function purgeKeyed(string $sku): void
    {
        if (!$this->keyPatternPurger->isSupported()) {
            return;
        }

        $skus = [$sku];

        try {
            $parentSku = $this->parentSkuResolver->resolve($sku);

            if ($parentSku !== null) {
                $skus[] = $parentSku;
            }
        } catch (Throwable $e) {
            // Purge what we can rather than nothing.
            $this->logger->warning(
                sprintf('Could not resolve the configurable parent of SKU %s.', $sku),
                ['exception' => $e]
            );
        }

        try {
            $this->keyPatternPurger->purgeBySkus($skus);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Key-pattern purge failed for SKU %s.', $sku),
                ['exception' => $e]
            );
        }
    }
}
