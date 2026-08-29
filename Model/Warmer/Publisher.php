<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Publishes warm-batch messages.
 */
class Publisher
{
    public const string DEFAULT_TOPIC = 'commerce.cachetools.warm';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly string $topic = self::DEFAULT_TOPIC
    ) {
    }

    /**
     * @param int[] $productIds
     */
    public function publishBatch(int $runId, string $type, array $productIds): void
    {
        $this->publisher->publish($this->topic, $this->serializer->serialize([
            'run_id' => $runId,
            'type' => $type,
            'product_ids' => array_values(array_map('intval', $productIds)),
        ]));
    }
}
