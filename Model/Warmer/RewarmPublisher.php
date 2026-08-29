<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues a URL to be re-fetched after it has been purged.
 */
class RewarmPublisher
{
    public const string DEFAULT_TOPIC = 'commerce.cachetools.rewarm';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger,
        private readonly string $topic = self::DEFAULT_TOPIC
    ) {
    }

    public function publish(string $url): void
    {
        if (trim($url) === '') {
            return;
        }

        try {
            $this->publisher->publish($this->topic, $url);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Could not queue a re-warm for %s.', $url),
                ['exception' => $e]
            );
        }
    }
}
