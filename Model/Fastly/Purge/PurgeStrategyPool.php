<?php
/**
 * PurgeStrategyPool.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Config;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Picks the configured purge strategy.
 */
class PurgeStrategyPool
{
    /**
     * @param array<string, PurgeStrategyInterface> $strategies
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $strategies = []
    ) {
        foreach ($this->strategies as $code => $strategy) {
            if (!$strategy instanceof PurgeStrategyInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Purge strategy "%s" must implement %s, got %s.',
                    $code,
                    PurgeStrategyInterface::class,
                    get_debug_type($strategy)
                ));
            }
        }
    }

    /**
     * @return PurgeStrategyInterface|null Null when nothing is configured.
     */
    public function get(): ?PurgeStrategyInterface
    {
        $code = $this->config->getPurgeStrategy();

        if (isset($this->strategies[$code])) {
            return $this->strategies[$code];
        }

        $this->logger->error(sprintf(
            'Configured purge strategy "%s" is not registered; known strategies are: %s.',
            $code,
            $this->strategies === [] ? '(none)' : implode(', ', array_keys($this->strategies))
        ));

        return null;
    }

    /**
     * @return string[]
     */
    public function getAvailableCodes(): array
    {
        return array_keys($this->strategies);
    }
}
