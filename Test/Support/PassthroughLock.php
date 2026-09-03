<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Support;

use Commerce\CacheTools\Lock\WarmLock;

/**
 * The lock, in memory.
 *
 * @SuppressWarnings("PHPMD.MissingConstructor")
 */
class PassthroughLock extends WarmLock
{
    /** @var string[] Lock names that were taken, in order. */
    public array $taken = [];

    /**
     * Names that are already held; `runLocked` skips those.
     *
     * @var string[]
     */
    public array $held = [];

    public function __construct()
    {
    }

    public function runLocked(string $name, callable $callback): mixed
    {
        $this->taken[] = $name;

        if (in_array($name, $this->held, true)) {
            return null;
        }

        return $callback();
    }

    public function isLocked(string $name): bool
    {
        return in_array($name, $this->held, true);
    }
}
