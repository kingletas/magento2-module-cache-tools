<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a product's absolute **frontend** URL, from any area.
 */
class FrontendUrlResolver
{
    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string Absolute frontend URL, or '' when the product has no usable rewrite.
     */
    public function resolve(ProductInterface $product, ?int $storeId = null): string
    {
        try {
            $store = $this->resolveStore($storeId);

            if ($store === null) {
                return '';
            }

            $requestPath = $this->findRequestPath((int) $product->getId(), (int) $store->getId());

            if ($requestPath === '') {
                $this->logger->info(sprintf(
                    'Product #%d has no frontend URL rewrite in store %d; nothing to purge.',
                    (int) $product->getId(),
                    (int) $store->getId()
                ));

                return '';
            }

            return rtrim($store->getBaseUrl(), '/') . '/' . ltrim($requestPath, '/');
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Could not resolve a frontend URL for product #%d.', (int) $product->getId()),
                ['exception' => $e]
            );

            return '';
        }
    }

    /**
     * @return string[] Absolute frontend URLs, one per store the product is in.
     */
    public function resolveForAllStores(ProductInterface $product): array
    {
        $urls = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }

            $url = $this->resolve($product, (int) $store->getId());

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function findRequestPath(int $productId, int $storeId): string
    {
        $rewrite = $this->urlFinder->findOneByData([
            UrlRewrite::ENTITY_ID => $productId,
            UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::STORE_ID => $storeId,
            // Exclude 301/302 rewrites left behind by URL-key changes; they
            // redirect rather than serve, so purging them achieves nothing.
            UrlRewrite::REDIRECT_TYPE => 0,
        ]);

        return $rewrite === null ? '' : (string) $rewrite->getRequestPath();
    }

    private function resolveStore(?int $storeId): ?Store
    {
        try {
            $store = $this->storeManager->getStore($storeId ?? null);
        } catch (Throwable) {
            return null;
        }

        if (!$store instanceof Store) {
            return null;
        }

        // The admin store has no frontend base URL worth purging.
        if ((int) $store->getId() === Store::DEFAULT_STORE_ID) {
            foreach ($this->storeManager->getStores() as $candidate) {
                if ($candidate instanceof Store && $candidate->getIsActive()) {
                    return $candidate;
                }
            }

            return null;
        }

        return $store;
    }
}
