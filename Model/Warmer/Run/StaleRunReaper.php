<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Run;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Closes runs that have stopped making progress.
 */
class StaleRunReaper
{
    public function __construct(
        private readonly WarmRunResource $resource,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int Runs reaped.
     */
    public function reap(): int
    {
        $hours = $this->config->getStaleRunHours();
        $cutoff = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime(sprintf('-%d hours', $hours), $this->dateTime->gmtTimestamp())
        );

        $reaped = $this->resource->markStaleRuns($cutoff, $this->dateTime->gmtDate());

        if ($reaped > 0) {
            $this->logger->warning(sprintf(
                'Reaped %d warm run(s) with no progress since %s (%d hour threshold).',
                $reaped,
                $cutoff,
                $hours
            ));
        }

        return $reaped;
    }
}
