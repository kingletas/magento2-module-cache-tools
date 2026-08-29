<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model;

use Commerce\Foundation\Model\Config\ModuleConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * All of this module's settings, in one typed reader.
 */
class Config extends ModuleConfig
{
    public const int DEFAULT_BATCH_SIZE = 1000;
    public const int DEFAULT_STALE_RUN_HOURS = 24;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        string $section,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($scopeConfig, $section);
    }

    // ---------------------------------------------------------------- Fastly

    public function isFastlyEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('fastly/enable', $storeId);
    }

    /**
     * Decrypted Fastly API token.
     *
     * @throws LocalizedException When no token is configured.
     */
    public function getFastlyToken(?int $storeId = null): string
    {
        $raw = $this->getString('fastly/token', '', $storeId);

        if ($raw === '') {
            throw new LocalizedException(__('The Fastly API token is not configured.'));
        }

        return $this->encryptor->decrypt($raw);
    }

    /**
     * Configured service id, decrypted, or '' when unset.
     */
    public function getFastlyServiceId(?int $storeId = null): string
    {
        $raw = $this->getString('fastly/service_id', '', $storeId);

        return $raw === '' ? '' : $this->encryptor->decrypt($raw);
    }

    public function getFastlyServiceName(?int $storeId = null): string
    {
        return $this->getString('fastly/service_name', '', $storeId);
    }

    /**
     * Whether purges default to soft (mark stale) rather than hard (evict).
     */
    public function isSoftPurgeDefault(?int $storeId = null): bool
    {
        return $this->isSetFlag('fastly/soft_purge', $storeId);
    }

    /**
     * @return string[]
     */
    public function getExtraCacheUrls(?int $storeId = null): array
    {
        return $this->getList('fastly/extra_urls', $storeId);
    }

    public function getPurgeStrategy(?int $storeId = null): string
    {
        return $this->getString('fastly/purge_strategy', 'url', $storeId);
    }

    // ----------------------------------------------------------- Environment

    /**
     * The production site host, used to decide whether this deployment may
     * purge production.
     */
    public function getProductionHost(?int $storeId = null): string
    {
        return mb_strtolower($this->getString('environment/production_host', '', $storeId));
    }

    public function getNonProductionMarker(?int $storeId = null): string
    {
        return mb_strtolower($this->getString('environment/non_production_marker', 'stage', $storeId));
    }

    // --------------------------------------------------------------- Warming

    public function getSimpleBatchSize(?int $storeId = null): int
    {
        return $this->getPositiveInt('warmer/simple_batch_size', self::DEFAULT_BATCH_SIZE, $storeId);
    }

    public function getConfigurableBatchSize(?int $storeId = null): int
    {
        return $this->getPositiveInt('warmer/configurable_batch_size', self::DEFAULT_BATCH_SIZE, $storeId);
    }

    /**
     * Hours without progress after which an unfinished run is reaped.
     */
    public function getStaleRunHours(?int $storeId = null): int
    {
        return $this->getPositiveInt('warmer/stale_run_hours', self::DEFAULT_STALE_RUN_HOURS, $storeId);
    }
}
