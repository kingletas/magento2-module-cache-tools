<?php
/**
 * WarmConsumer.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Queue;

use Commerce\CacheTools\Api\WarmTaskInterface;
use Commerce\CacheTools\Lock\WarmLock;
use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Commerce\CacheTools\Model\Warmer\Run\WarmRunTracker;
use Commerce\CacheTools\Model\Warmer\WarmResult;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes one warm-batch message.
 */
class WarmConsumer
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly State $appState,
        private readonly WarmLock $lock,
        private readonly WarmRunTracker $tracker,
        private readonly LoggerInterface $logger,
        private readonly WarmTaskInterface $simpleWarmer,
        private readonly WarmTaskInterface $configurableWarmer,
        private readonly string $lockPrefix = 'commerce_cachetools_warm_consume_'
    ) {
    }

    public function process(string $message): void
    {
        $this->ensureFrontendArea();

        $decoded = $this->decode($message);

        if ($decoded === null) {
            return;
        }

        [$runId, $type, $productIds] = $decoded;

        // Keyed on the message content so a redelivery of the *same* batch is
        // skipped, while a different batch of the same run proceeds.
        $lockName = sprintf('%s%d_%s', $this->lockPrefix, $runId, hash('xxh128', $message));

        $handled = $this->lock->runLocked($lockName, fn (): bool => $this->warmBatch($runId, $type, $productIds));

        if ($handled === null) {
            // A skipped batch is NOT counted against the run, so the run will
            // never reach its total on its own and only the reaper will close
            // it.
            $this->logger->warning(sprintf(
                'Warm run #%d: skipped a %s batch of %d product(s) already being processed;'
                . ' this run will not reach its total from this message.',
                $runId,
                $type,
                count($productIds)
            ));
        }
    }

    /**
     * @param int[] $productIds
     */
    private function warmBatch(int $runId, string $type, array $productIds): bool
    {
        $batchSize = count($productIds);

        try {
            $result = $this->warmerFor($type)->warm($productIds);
            $this->tracker->incrementProgress($runId, $batchSize, $result->getFailed());
            $this->logProgress($runId, $type, $result);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Warm run #%d: a %s batch failed entirely.', $runId, $type),
                ['exception' => $e]
            );
            // Count the batch as processed-and-failed regardless.
            $this->tracker->incrementProgress($runId, $batchSize, $batchSize);
        }

        $this->tracker->completeIfDone($runId);

        return true;
    }

    private function warmerFor(string $type): WarmTaskInterface
    {
        return $type === BatchQueuer::TYPE_CONFIGURABLE ? $this->configurableWarmer : $this->simpleWarmer;
    }

    private function logProgress(int $runId, string $type, WarmResult $result): void
    {
        foreach ($result->messages as $message) {
            $this->logger->warning(sprintf('Warm run #%d: %s', $runId, $message));
        }

        $this->logger->info(sprintf(
            'Warm run #%d batch (%s): warmed %d of %d, %d failed.',
            $runId,
            $type,
            $result->warmed,
            $result->total,
            $result->getFailed()
        ));
    }

    /**
     * @return array{0: int, 1: string, 2: int[]}|null
     */
    private function decode(string $message): ?array
    {
        try {
            $data = $this->serializer->unserialize($message);
        } catch (Throwable $e) {
            $this->logger->warning('Discarded an unparseable warm message.', ['exception' => $e]);

            return null;
        }

        if (!is_array($data)) {
            $this->logger->warning('Discarded a warm message that did not decode to an array.');

            return null;
        }

        $runId = (int) ($data['run_id'] ?? 0);
        $type = (string) ($data['type'] ?? '');
        $productIds = $data['product_ids'] ?? [];

        if ($runId <= 0 || $type === '' || !is_array($productIds) || $productIds === []) {
            $this->logger->warning('Discarded a malformed warm message.', ['payload' => $data]);

            return null;
        }

        return [$runId, $type, array_values(array_map('intval', $productIds))];
    }

    private function ensureFrontendArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (Throwable) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        }
    }
}
