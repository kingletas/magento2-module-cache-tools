<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Probes a URL and reports how the edge is serving it.
 */
class VarnishHealthCheck
{
    private const string HEADER_CACHE_STATE = 'x-cache';
    private const string HEADER_AGE = 'age';
    private const string HEADER_SERVED_BY = 'x-served-by';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 15
    ) {
    }

    public function check(string $url): HealthResult
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new HealthResult(
                $url,
                reachable: false,
                error: (string) __('"%1" is not a valid URL.', $url)
            );
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeoutSeconds);
            $curl->get($url);

            $headers = $this->normaliseHeaders($curl->getHeaders());

            return new HealthResult(
                $url,
                reachable: true,
                httpStatus: (int) $curl->getStatus(),
                cacheState: $this->readCacheState($headers),
                age: isset($headers[self::HEADER_AGE]) ? (int) $headers[self::HEADER_AGE] : null,
                servedBy: $headers[self::HEADER_SERVED_BY] ?? null
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Health check failed for %s.', $url),
                ['exception' => $e]
            );

            return new HealthResult($url, reachable: false, error: $e->getMessage());
        }
    }

    /**
     * Lower-case the header names; HTTP header names are case-insensitive and
     * which case a CDN sends is not something to depend on.
     *
     * @param array<string, string|string[]> $headers
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[mb_strtolower((string) $name)] = is_array($value)
                ? (string) end($value)
                : (string) $value;
        }

        return $normalised;
    }

    /**
     * @param array<string, string> $headers
     */
    private function readCacheState(array $headers): string
    {
        $value = mb_strtoupper($headers[self::HEADER_CACHE_STATE] ?? '');

        // Fastly reports the whole chain, e.g. "MISS, HIT".
        return match (true) {
            str_contains($value, HealthResult::STATE_HIT) => HealthResult::STATE_HIT,
            str_contains($value, HealthResult::STATE_PASS) => HealthResult::STATE_PASS,
            str_contains($value, HealthResult::STATE_MISS) => HealthResult::STATE_MISS,
            default => HealthResult::STATE_UNKNOWN,
        };
    }
}
