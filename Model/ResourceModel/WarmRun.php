<?php
/**
 * WarmRun.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\ResourceModel;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Zend_Db_Expr;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class WarmRun extends AbstractDb
{
    public const string TABLE_NAME = 'commerce_cachetools_warm_run';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, WarmRunInterface::RUN_ID);
    }

    /**
     * Advance a run's counters atomically.
     *
     * @return int Rows affected; 0 means the run no longer exists.
     */
    public function incrementProgress(int $runId, int $processed, int $failed): int
    {
        $connection = $this->getConnection();

        return (int) $connection->update(
            $this->getMainTable(),
            [
                WarmRunInterface::PROCESSED_PRODUCTS => new Zend_Db_Expr(
                    $connection->quoteIdentifier(WarmRunInterface::PROCESSED_PRODUCTS) . ' + ' . (int) $processed
                ),
                WarmRunInterface::FAILED_PRODUCTS => new Zend_Db_Expr(
                    $connection->quoteIdentifier(WarmRunInterface::FAILED_PRODUCTS) . ' + ' . (int) $failed
                ),
            ],
            [
                $connection->quoteInto(WarmRunInterface::RUN_ID . ' = ?', $runId),
                $connection->quoteInto(WarmRunInterface::STATUS . ' = ?', WarmRunInterface::STATUS_RUNNING),
            ]
        );
    }

    /**
     * Close a run if every product has been accounted for.
     *
     * @return bool Whether this call is the one that closed the run.
     */
    public function completeIfDone(int $runId, string $finishedAt): bool
    {
        $connection = $this->getConnection();

        $affected = (int) $connection->update(
            $this->getMainTable(),
            [
                WarmRunInterface::STATUS => WarmRunInterface::STATUS_COMPLETE,
                WarmRunInterface::FINISHED_AT => $finishedAt,
            ],
            [
                $connection->quoteInto(WarmRunInterface::RUN_ID . ' = ?', $runId),
                $connection->quoteInto(WarmRunInterface::STATUS . ' = ?', WarmRunInterface::STATUS_RUNNING),
                $connection->quoteIdentifier(WarmRunInterface::PROCESSED_PRODUCTS)
                    . ' >= ' . $connection->quoteIdentifier(WarmRunInterface::TOTAL_PRODUCTS),
            ]
        );

        return $affected > 0;
    }

    /**
     * Mark runs stale when they have made no progress for too long.
     *
     * @return int Runs reaped.
     */
    public function markStaleRuns(string $noProgressSince, string $finishedAt): int
    {
        $connection = $this->getConnection();

        return (int) $connection->update(
            $this->getMainTable(),
            [
                WarmRunInterface::STATUS => WarmRunInterface::STATUS_STALE,
                WarmRunInterface::FINISHED_AT => $finishedAt,
            ],
            [
                $connection->quoteInto(WarmRunInterface::STATUS . ' = ?', WarmRunInterface::STATUS_RUNNING),
                $connection->quoteInto(WarmRunInterface::UPDATED_AT . ' < ?', $noProgressSince),
            ]
        );
    }

    public function hasOpenRun(string $warmType): bool
    {
        $connection = $this->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [new Zend_Db_Expr('1')])
                ->where(WarmRunInterface::WARM_TYPE . ' = ?', $warmType)
                ->where(WarmRunInterface::STATUS . ' = ?', WarmRunInterface::STATUS_RUNNING)
                ->limit(1)
        );
    }
}
