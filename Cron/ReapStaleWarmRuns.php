<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Cron;

use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Psr\Log\LoggerInterface;
use Throwable;

class ReapStaleWarmRuns
{
    public function __construct(
        private readonly StaleRunReaper $reaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reaper->reap();
        } catch (Throwable $e) {
            // A throwing cron job is reported as a failed job and retried; this
            // one is housekeeping and should simply be noted.
            $this->logger->error('Failed reaping stale warm runs.', ['exception' => $e]);
        }
    }
}
