<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Fastly\Api\PurgeApi;
use Fastly\Api\ServiceApi;
use Fastly\Configuration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;

/**
 * Builds authenticated Fastly API clients.
 */
class FastlyClientFactory
{
    public const string PACKAGE = 'fastly/fastly';

    private ?PurgeApi $purgeApi = null;
    private ?ServiceApi $serviceApi = null;

    /**
     * The SDK class names are arguments rather than literals, so a test can
     * point them at a class that is not installed.
     *
     * @param string $purgeApiClass   SDK client for purging.
     * @param string $serviceApiClass SDK client for service lookup.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ObjectManagerInterface $objectManager,
        private readonly string $purgeApiClass = PurgeApi::class,
        private readonly string $serviceApiClass = ServiceApi::class
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function createPurgeApi(): PurgeApi
    {
        return $this->purgeApi ??= $this->build($this->purgeApiClass);
    }

    /**
     * @throws LocalizedException
     */
    public function createServiceApi(): ServiceApi
    {
        return $this->serviceApi ??= $this->build($this->serviceApiClass);
    }

    /**
     * Build one SDK client with its own configuration.
     *
     * @throws LocalizedException
     */
    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new LocalizedException(
                __(
                    'Fastly edge purging needs the %1 package, which is not installed: '
                    . 'run "composer require %1", or turn edge purging off.',
                    self::PACKAGE
                )
            );
        }

        return $this->objectManager->create($class, ['config' => $this->configuration()]);
    }

    /**
     * A private Configuration, never the SDK's shared default.
     *
     * @throws LocalizedException
     */
    private function configuration(): Configuration
    {
        return (new Configuration())->setApiToken($this->config->getFastlyToken());
    }
}
