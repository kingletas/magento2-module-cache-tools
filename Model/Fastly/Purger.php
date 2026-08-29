<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Warmer\RewarmPublisher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Purges content from the Fastly edge cache.
 */
class Purger
{
    /**
     * Fastly caps one bulk surrogate-key purge at 256 keys.
     */
    private const int MAX_BULK_KEYS = 256;

    private const int SOFT_PURGE_HEADER = 1;

    public function __construct(
        private readonly Config $config,
        private readonly FastlyClientFactory $clientFactory,
        private readonly ServiceIdProvider $serviceIdProvider,
        private readonly PurgeGuard $guard,
        private readonly RewarmPublisher $rewarmPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Purge one URL.
     *
     * @param bool|null $soft Null uses the configured default.
     */
    public function purgeUrl(string $url, ?bool $soft = null): PurgeResult
    {
        if (!$this->config->isFastlyEnabled()) {
            return $this->disabled($url);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new PurgeResult($url, isSuccess: false, message: __('"%1" is not a valid URL.', $url));
        }

        $blocked = $this->guard->blockReasonForUrl($url);

        if ($blocked !== null) {
            return new PurgeResult($url, isSuccess: false, message: $blocked);
        }

        $soft = $soft ?? $this->config->isSoftPurgeDefault();

        $result = $this->execute($url, sprintf('URL %s', $url), function () use ($url, $soft): void {
            $this->clientFactory->createPurgeApi()->purgeSingleUrl($this->withSoft(['cached_url' => $url], $soft));
        });

        // Refill the cache on a worker rather than making the next shopper pay
        // for the miss — and without blocking this request thread on a page
        // load.
        if ($result->isSuccess) {
            $this->rewarmPublisher->publish($url);
        }

        return $result;
    }

    /**
     * Purge several URLs.
     *
     * @param string[] $urls
     *
     * @return PurgeResult[]
     */
    public function purgeUrls(array $urls, ?bool $soft = null): array
    {
        return array_map(
            fn ($url): PurgeResult => $this->purgeUrl((string) $url, $soft),
            array_values($urls)
        );
    }

    public function purgeKey(string $surrogateKey, ?bool $soft = null): PurgeResult
    {
        return $this->purgeKeys([$surrogateKey], $soft);
    }

    /**
     * Purge several surrogate keys, chunked to Fastly's per-request limit.
     *
     * @param string[] $surrogateKeys
     */
    public function purgeKeys(array $surrogateKeys, ?bool $soft = null): PurgeResult
    {
        if (!$this->config->isFastlyEnabled()) {
            return $this->disabled('keys');
        }

        $keys = array_values(array_unique(array_filter(
            array_map(static fn ($key): string => trim((string) $key), $surrogateKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($keys === []) {
            return new PurgeResult('keys', isSuccess: false, message: __('No surrogate keys were provided.'));
        }

        $blocked = $this->guard->blockReasonForService($this->config->getFastlyServiceName());

        if ($blocked !== null) {
            return new PurgeResult('keys', isSuccess: false, message: $blocked);
        }

        $soft = $soft ?? $this->config->isSoftPurgeDefault();
        $target = sprintf('%d key(s)', count($keys));

        return $this->execute($target, $target, function () use ($keys, $soft): void {
            $serviceId = $this->serviceIdProvider->get();
            $api = $this->clientFactory->createPurgeApi();

            foreach (array_chunk($keys, self::MAX_BULK_KEYS) as $chunk) {
                $api->bulkPurgeTag($this->withSoft([
                    'service_id' => $serviceId,
                    'surrogate_key' => implode(' ', $chunk),
                ], $soft));
            }
        });
    }

    public function purgeAll(bool $confirm = false): PurgeResult
    {
        if (!$this->config->isFastlyEnabled()) {
            return $this->disabled('all');
        }

        if (!$confirm) {
            $this->logger->warning('Refused a Fastly purge-all: it was not explicitly confirmed.');

            return new PurgeResult(
                'all',
                isSuccess: false,
                message: __('Purge-all was not confirmed, so the entire cache was not flushed.')
            );
        }

        $blocked = $this->guard->blockReasonForService($this->config->getFastlyServiceName());

        if ($blocked !== null) {
            return new PurgeResult('all', isSuccess: false, message: $blocked);
        }

        $this->logger->warning('Executing a Fastly purge-all: the entire service cache will be flushed.');

        return $this->execute('all', 'the entire cache', function (): void {
            $this->clientFactory->createPurgeApi()->purgeAll([
                'service_id' => $this->serviceIdProvider->get(),
            ]);
        });
    }

    private function execute(string $target, string $label, callable $purge): PurgeResult
    {
        try {
            $purge();
            $this->logger->info(sprintf('Fastly purge sent for %s.', $label));

            return new PurgeResult(
                $target,
                isSuccess: true,
                message: __('A cache purge has been sent for %1.', $label)
            );
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Fastly purge failed for %s: %s', $label, $e->getMessage()),
                ['exception' => $e]
            );

            // The admin sees a stable sentence; the exception detail is in the
            // log.
            return new PurgeResult(
                $target,
                isSuccess: false,
                message: __('The cache purge for %1 failed; see the log.', $label)
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withSoft(array $options, bool $soft): array
    {
        if ($soft) {
            $options['fastly_soft_purge'] = self::SOFT_PURGE_HEADER;
        }

        return $options;
    }

    private function disabled(string $target): PurgeResult
    {
        return new PurgeResult(
            $target,
            isSuccess: false,
            message: __('Fastly purging is disabled in configuration.')
        );
    }
}
