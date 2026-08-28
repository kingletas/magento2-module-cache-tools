<?php
/**
 * NullKeyPatternPurger.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fallback used when the cache backend cannot match keys by pattern.
 */
class NullKeyPatternPurger implements KeyPatternPurgerInterface
{
    private bool $warned = false;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string[] $skus
     */
    public function purgeBySkus(array $skus): int
    {
        if ($skus !== [] && !$this->warned) {
            $this->warned = true;
            $this->logger->warning(
                'Key-pattern cache purging is not available on this cache backend, so SKU-keyed entries'
                . ' (swatch and configurable-options caches) are not being invalidated.'
            );
        }

        return 0;
    }

    public function isSupported(): bool
    {
        return false;
    }
}
