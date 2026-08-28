<?php
/**
 * EnvironmentPolicy.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Environment;

use Commerce\CacheTools\Model\Config;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Decides whether this deployment is production.
 */
class EnvironmentPolicy
{
    public const string PRODUCTION = 'production';
    public const string DEFAULT_ENV_PATH = 'commerce/cache_tools/environment';

    private ?bool $isProduction = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlHostResolver $hostResolver,
        private readonly Config $config,
        private readonly string $environmentConfigPath = self::DEFAULT_ENV_PATH
    ) {
    }

    public function isProduction(): bool
    {
        if ($this->isProduction !== null) {
            return $this->isProduction;
        }

        $productionHost = $this->config->getProductionHost();

        // With no production host configured the guard cannot verify anything,
        // so it must not claim production.
        if ($productionHost === '') {
            return $this->isProduction = false;
        }

        return $this->isProduction = $this->declaredEnvironment() === self::PRODUCTION
            && $this->getHost() === $productionHost;
    }

    public function getHost(): string
    {
        try {
            return $this->hostResolver->resolve((string) $this->storeManager->getStore()->getBaseUrl());
        } catch (Throwable) {
            return '';
        }
    }

    public function describe(): string
    {
        $declared = $this->declaredEnvironment();

        return sprintf(
            'environment=%s, host=%s, expected-production-host=%s, resolved=%s',
            $declared !== '' ? $declared : 'undeclared',
            $this->getHost() !== '' ? $this->getHost() : 'unknown',
            $this->config->getProductionHost() !== '' ? $this->config->getProductionHost() : 'unconfigured',
            $this->isProduction() ? 'production' : 'non-production'
        );
    }

    private function declaredEnvironment(): string
    {
        try {
            return mb_strtolower(trim((string) $this->deploymentConfig->get($this->environmentConfigPath)));
        } catch (Throwable) {
            return '';
        }
    }
}
