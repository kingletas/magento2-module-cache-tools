<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Run;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\ResourceModel\WarmRun\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Opens, advances and closes warm-run rows.
 */
class WarmRunTracker
{
    public function __construct(
        private readonly WarmRunFactory $runFactory,
        private readonly WarmRunResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether a run of this type is still open.
     */
    public function hasOpenRun(string $warmType): bool
    {
        return $this->resource->hasOpenRun($warmType);
    }

    /**
     * @return WarmRun[] Oldest first.
     */
    public function getOpenRuns(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(WarmRunInterface::STATUS, WarmRunInterface::STATUS_RUNNING);
        $collection->setOrder(WarmRunInterface::RUN_ID, 'ASC');

        /** @var WarmRun[] $runs */
        $runs = array_values($collection->getItems());

        return $runs;
    }

    public function open(string $warmType, int $totalProducts): int
    {
        $run = $this->runFactory->create();
        $run->addData([
            WarmRunInterface::WARM_TYPE => $warmType,
            WarmRunInterface::STATUS => WarmRunInterface::STATUS_RUNNING,
            WarmRunInterface::TOTAL_PRODUCTS => $totalProducts,
            WarmRunInterface::PROCESSED_PRODUCTS => 0,
            WarmRunInterface::FAILED_PRODUCTS => 0,
            WarmRunInterface::STARTED_AT => $this->dateTime->gmtDate(),
        ]);
        $this->resource->save($run);

        $runId = (int) $run->getId();

        $this->logger->info(
            sprintf('Opened warm run #%d (%s, %d product(s)).', $runId, $warmType, $totalProducts)
        );

        return $runId;
    }

    public function incrementProgress(int $runId, int $processed, int $failed): void
    {
        $this->resource->incrementProgress($runId, $processed, $failed);
    }

    public function completeIfDone(int $runId): bool
    {
        $closed = $this->resource->completeIfDone($runId, $this->dateTime->gmtDate());

        if ($closed) {
            $this->logger->info(sprintf('Warm run #%d completed.', $runId));
        }

        return $closed;
    }
}
