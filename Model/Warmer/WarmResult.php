<?php
/**
 * WarmResult.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer;

/**
 * What one warm batch achieved.
 */
class WarmResult
{
    /**
     * @param string[] $messages Per-product failure descriptions.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $warmed,
        public readonly array $messages = []
    ) {
    }

    public function getFailed(): int
    {
        return max(0, $this->total - $this->warmed);
    }
}
