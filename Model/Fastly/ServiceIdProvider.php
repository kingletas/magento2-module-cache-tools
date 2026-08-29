<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Resolves the Fastly service id: from config if set, otherwise discovered by
 * service name and memoised for the request.
 */
class ServiceIdProvider
{
    private ?string $discovered = null;

    public function __construct(
        private readonly Config $config,
        private readonly FastlyClientFactory $clientFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function get(): string
    {
        $configured = $this->config->getFastlyServiceId();

        if ($configured !== '') {
            return $configured;
        }

        return $this->discovered ??= $this->discoverByName();
    }

    /**
     * @throws LocalizedException
     */
    private function discoverByName(): string
    {
        $name = $this->config->getFastlyServiceName();

        if ($name === '') {
            throw new LocalizedException(
                __('No Fastly service id is configured, and no service name is set to discover one.')
            );
        }

        $serviceId = (string) $this->clientFactory->createServiceApi()
            ->searchService(['name' => $name])
            ->getId();

        if ($serviceId === '') {
            throw new LocalizedException(__('No Fastly service was found named "%1".', $name));
        }

        $this->logger->info(sprintf('Resolved Fastly service "%s" to id %s.', $name, $serviceId));

        return $serviceId;
    }
}
