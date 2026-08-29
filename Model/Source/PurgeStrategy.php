<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Source;

use Commerce\CacheTools\Model\Fastly\Purge\PurgeStrategyPool;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Purge-strategy options for system configuration.
 */
class PurgeStrategy implements OptionSourceInterface
{
    /**
     * @param array<string, string> $labels Strategy code => human label.
     */
    public function __construct(
        private readonly PurgeStrategyPool $pool,
        private readonly array $labels = []
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->pool->getAvailableCodes() as $code) {
            $options[] = [
                'value' => $code,
                'label' => $this->labels[$code] ?? ucfirst(str_replace('_', ' ', $code)),
            ];
        }

        return $options;
    }
}
