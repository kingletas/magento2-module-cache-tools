<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Lock;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs work while holding a named distributed lock.
 */
class WarmLock
{
    /**
     * @param int $timeout Seconds to wait for the lock. 0 means try once and skip.
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 0
    ) {
    }

    /**
     * Run $callback under the named lock.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T|null Null when the lock could not be acquired.
     */
    public function runLocked(string $name, callable $callback): mixed
    {
        if (!$this->lockManager->lock($name, $this->timeout)) {
            $this->logger->info(sprintf('Lock "%s" is already held; skipping to avoid double-processing.', $name));

            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release($name);
        }
    }

    public function isLocked(string $name): bool
    {
        return $this->lockManager->isLocked($name);
    }

    /**
     * Release the lock.
     */
    public function release(string $name): bool
    {
        try {
            return $this->lockManager->unlock($name);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Failed to release lock "%s".', $name),
                ['exception' => $e]
            );

            return false;
        }
    }
}
