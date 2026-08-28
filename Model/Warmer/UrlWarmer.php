<?php
/**
 * UrlWarmer.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches a URL so the edge caches the response.
 */
class UrlWarmer
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
        private readonly string $userAgent = 'Commerce-CacheTools/1.0 (cache warmer)'
    ) {
    }

    /**
     * @return bool Whether the edge returned a cacheable success.
     */
    public function warm(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->warning(sprintf('Refusing to warm "%s": not a valid URL.', $url));

            return false;
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeoutSeconds);
            $curl->addHeader('User-Agent', $this->userAgent);
            // Ask for an uncached response so the edge stores a fresh copy
            // rather than serving one back to us and recording a hit.
            $curl->addHeader('Cache-Control', 'no-cache');
            $curl->get($url);

            $status = $curl->getStatus();

            if ($status >= 200 && $status < 400) {
                return true;
            }

            $this->logger->warning(sprintf('Warming %s returned HTTP %d.', $url, $status));

            return false;
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Warming %s failed.', $url),
                ['exception' => $e]
            );

            return false;
        }
    }
}
