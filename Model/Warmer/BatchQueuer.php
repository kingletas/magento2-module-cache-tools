<?php
/**
 * BatchQueuer.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer;

use Commerce\CacheTools\Lock\WarmLock;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Product\ActiveProductCollection;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use InvalidArgumentException;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Psr\Log\LoggerInterface;

/**
 * Opens a tracked run and publishes its products in batches.
 */
class BatchQueuer
{
    public const string TYPE_SIMPLE = ProductType::TYPE_SIMPLE;
    public const string TYPE_CONFIGURABLE = ConfigurableType::TYPE_CODE;

    public function __construct(
        private readonly ActiveProductCollection $activeProducts,
        private readonly Config $config,
        private readonly Publisher $publisher,
        private readonly WarmRunTracker $tracker,
        private readonly WarmLock $lock,
        private readonly LoggerInterface $logger,
        private readonly string $lockPrefix = 'commerce_cachetools_warm_queue_'
    ) {
    }

    /**
     * @return int|null The run id, or null when the lock is held or a run of
     *                  this type is already open.
     */
    public function queue(string $type): ?int
    {
        $this->assertKnownType($type);

        $runId = $this->lock->runLocked($this->lockPrefix . $type, fn (): ?int => $this->doQueue($type));

        return $runId === null ? null : (int) $runId;
    }

    private function doQueue(string $type): ?int
    {
        if ($this->tracker->hasOpenRun($type)) {
            $this->logger->info(sprintf('A %s warm run is already open; not starting another.', $type));

            return null;
        }

        $productIds = $this->collectProductIds($type);
        $total = count($productIds);
        $runId = $this->tracker->open($type, $total);

        if ($total === 0) {
            $this->tracker->completeIfDone($runId);
            $this->logger->info(sprintf('Warm run #%d had no %s products to queue.', $runId, $type));

            return $runId;
        }

        $batchSize = $type === self::TYPE_SIMPLE
            ? $this->config->getSimpleBatchSize()
            : $this->config->getConfigurableBatchSize();

        $batches = 0;

        foreach (array_chunk($productIds, $batchSize) as $chunk) {
            $this->publisher->publishBatch($runId, $type, $chunk);
            $batches++;
        }

        $this->logger->info(sprintf(
            'Queued warm run #%d: %d %s product(s) in %d batch(es).',
            $runId,
            $total,
            $type,
            $batches
        ));

        return $runId;
    }

    /**
     * @return int[]
     */
    private function collectProductIds(string $type): array
    {
        $collection = $type === self::TYPE_SIMPLE
            ? $this->activeProducts->forSimple()
            : $this->activeProducts->forConfigurable();

        return array_map('intval', $collection->getAllIds());
    }

    private function assertKnownType(string $type): void
    {
        if (!in_array($type, [self::TYPE_SIMPLE, self::TYPE_CONFIGURABLE], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown warm type "%s"; expected "%s" or "%s".',
                $type,
                self::TYPE_SIMPLE,
                self::TYPE_CONFIGURABLE
            ));
        }
    }
}
