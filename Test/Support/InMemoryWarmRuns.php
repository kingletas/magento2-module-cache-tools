<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Support;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Magento\Framework\Model\AbstractModel;

/**
 * The `commerce_cachetools_warm_run` table, in an array.
 *
 * @SuppressWarnings(PHPMD.MissingConstructor)
 */
class InMemoryWarmRuns extends WarmRunResource
{
    /** @var array<int, array<string, mixed>> Rows, keyed by run id. */
    public array $rows = [];

    /**
     * Protected rather than private: the performance suite subclasses this to
     * count writes.
     */
    protected int $nextId = 1;

    public function __construct()
    {
    }

    /**
     * @param  AbstractModel $object
     * @return $this
     */
    public function save(AbstractModel $object)
    {
        $runId = (int) ($object->getId() ?? $this->nextId++);
        $object->setId($runId);
        $this->rows[$runId] = $object->getData() + [WarmRunInterface::RUN_ID => $runId];

        return $this;
    }

    public function incrementProgress(int $runId, int $processed, int $failed): int
    {
        if (!isset($this->rows[$runId])) {
            return 0;
        }

        $this->rows[$runId][WarmRunInterface::PROCESSED_PRODUCTS] =
            (int) ($this->rows[$runId][WarmRunInterface::PROCESSED_PRODUCTS] ?? 0) + $processed;
        $this->rows[$runId][WarmRunInterface::FAILED_PRODUCTS] =
            (int) ($this->rows[$runId][WarmRunInterface::FAILED_PRODUCTS] ?? 0) + $failed;

        return 1;
    }

    public function completeIfDone(int $runId, string $finishedAt): bool
    {
        $row = $this->rows[$runId] ?? null;

        if ($row === null || ($row[WarmRunInterface::STATUS] ?? '') !== WarmRunInterface::STATUS_RUNNING) {
            return false;
        }

        if ((int) $row[WarmRunInterface::PROCESSED_PRODUCTS] < (int) $row[WarmRunInterface::TOTAL_PRODUCTS]) {
            return false;
        }

        $this->rows[$runId][WarmRunInterface::STATUS] = WarmRunInterface::STATUS_COMPLETE;
        $this->rows[$runId][WarmRunInterface::FINISHED_AT] = $finishedAt;

        return true;
    }

    public function markStaleRuns(string $noProgressSince, string $finishedAt): int
    {
        $reaped = 0;

        foreach ($this->rows as $runId => $row) {
            if (($row[WarmRunInterface::STATUS] ?? '') !== WarmRunInterface::STATUS_RUNNING) {
                continue;
            }

            $updatedAt = (string) ($row[WarmRunInterface::UPDATED_AT] ?? $row[WarmRunInterface::STARTED_AT] ?? '');

            if ($updatedAt !== '' && $updatedAt < $noProgressSince) {
                $this->rows[$runId][WarmRunInterface::STATUS] = WarmRunInterface::STATUS_STALE;
                $this->rows[$runId][WarmRunInterface::FINISHED_AT] = $finishedAt;
                $reaped++;
            }
        }

        return $reaped;
    }

    public function hasOpenRun(string $warmType): bool
    {
        foreach ($this->rows as $row) {
            if (($row[WarmRunInterface::WARM_TYPE] ?? '') === $warmType
                && ($row[WarmRunInterface::STATUS] ?? '') === WarmRunInterface::STATUS_RUNNING
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move a run's last-progress timestamp back, so a test can let time pass.
     */
    public function noProgressSince(int $runId, string $when): void
    {
        $this->rows[$runId][WarmRunInterface::STARTED_AT] = $when;
        $this->rows[$runId][WarmRunInterface::UPDATED_AT] = $when;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $runId): array
    {
        return $this->rows[$runId] ?? [];
    }

    public function statusOf(int $runId): string
    {
        return (string) ($this->rows[$runId][WarmRunInterface::STATUS] ?? '');
    }
}
