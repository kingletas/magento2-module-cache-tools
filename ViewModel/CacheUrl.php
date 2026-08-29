<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\ViewModel;

use Commerce\CacheTools\Model\Config;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Supplies the Cache Management buttons with the URLs they can act on.
 */
class CacheUrl implements ArgumentInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly Json $json,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isFastlyEnabled();
    }

    /**
     * Every store's base URL plus any extra URLs from configuration.
     *
     * @return string[]
     */
    public function getCacheUrls(): array
    {
        $urls = [];

        try {
            foreach ($this->storeManager->getStores() as $store) {
                if ($store->isActive()) {
                    $urls[] = rtrim($store->getBaseUrl(), '/') . '/';
                }
            }
        } catch (Throwable) {
            // A broken store configuration should not blank the whole panel.
            $urls = [];
        }

        return array_values(array_unique(array_merge($urls, $this->config->getExtraCacheUrls())));
    }

    public function getJsonConfig(): string
    {
        return $this->json->serialize([
            'flushUrl' => $this->urlBuilder->getUrl('commerce_cachetools/varnish/flush'),
            'healthUrl' => $this->urlBuilder->getUrl('commerce_cachetools/varnish/healthCheck'),
            'urls' => $this->getCacheUrls(),
        ]);
    }
}
