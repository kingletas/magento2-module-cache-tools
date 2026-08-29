<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Api\Data;

/**
 * One cache-warming cycle, as shown in the admin grid.
 */
interface WarmRunInterface
{
    public const string RUN_ID = 'run_id';
    public const string WARM_TYPE = 'warm_type';
    public const string STATUS = 'status';
    public const string TOTAL_PRODUCTS = 'total_products';
    public const string PROCESSED_PRODUCTS = 'processed_products';
    public const string FAILED_PRODUCTS = 'failed_products';
    public const string STARTED_AT = 'started_at';
    public const string UPDATED_AT = 'updated_at';
    public const string FINISHED_AT = 'finished_at';

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_COMPLETE = 'complete';
    public const string STATUS_STALE = 'stale';

    public function getRunId(): ?int;

    public function getWarmType(): string;

    public function getStatus(): string;

    public function getTotalProducts(): int;

    public function getProcessedProducts(): int;

    public function getFailedProducts(): int;

    public function isRunning(): bool;

    public function getProgressPercent(): int;
}
