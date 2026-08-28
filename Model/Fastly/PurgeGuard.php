<?php
/**
 * PurgeGuard.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Environment\EnvironmentPolicy;
use Commerce\CacheTools\Model\Environment\UrlHostResolver;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Stops a lower environment from purging production's edge cache.
 */
class PurgeGuard
{
    public function __construct(
        private readonly EnvironmentPolicy $environment,
        private readonly UrlHostResolver $hostResolver,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function blockReasonForUrl(string $url): ?Phrase
    {
        if ($this->environment->isProduction()) {
            return null;
        }

        $productionHost = $this->config->getProductionHost();

        if ($productionHost === '') {
            // Nothing configured to compare against.
            $this->logger->info(
                'No production host is configured, so URL purges cannot be checked against it.'
            );

            return null;
        }

        if (strcasecmp($this->hostResolver->resolve($url), $productionHost) !== 0) {
            return null;
        }

        return $this->block(__(
            'Refusing to purge the production URL %1 from a non-production environment (%2).',
            $url,
            $this->environment->describe()
        ));
    }

    public function blockReasonForService(string $serviceName): ?Phrase
    {
        $serviceName = trim($serviceName);

        if ($this->environment->isProduction()) {
            if ($serviceName !== '' && $this->isNonProductionService($serviceName)) {
                // Harmless direction — production flushing a lower environment
                // — but it means the service is misconfigured, so warn rather
                // than block.
                $this->logger->warning(sprintf(
                    'Production is configured with the non-production Fastly service "%s" (%s).',
                    $serviceName,
                    $this->environment->describe()
                ));
            }

            return null;
        }

        if ($serviceName === '') {
            return $this->block(__(
                'Refusing to purge a Fastly service from a non-production environment (%1): no service name is'
                . ' configured, so it cannot be verified as non-production. Set the Fastly service name to this'
                . " environment's own service.",
                $this->environment->describe()
            ));
        }

        if ($this->isNonProductionService($serviceName)) {
            return null;
        }

        return $this->block(__(
            'Refusing to purge the Fastly service "%1" from a non-production environment (%2):'
            . ' its name does not contain the non-production marker "%3".',
            $serviceName,
            $this->environment->describe(),
            $this->config->getNonProductionMarker()
        ));
    }

    private function isNonProductionService(string $serviceName): bool
    {
        $marker = $this->config->getNonProductionMarker();

        return $marker !== '' && str_contains(mb_strtolower($serviceName), $marker);
    }

    private function block(Phrase $reason): Phrase
    {
        $this->logger->error($reason->render());

        return $reason;
    }
}
