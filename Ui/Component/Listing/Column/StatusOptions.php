<?php
/**
 * StatusOptions.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Ui\Component\Listing\Column;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Magento\Framework\Data\OptionSourceInterface;

class StatusOptions implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => WarmRunInterface::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => WarmRunInterface::STATUS_COMPLETE, 'label' => __('Complete')],
            ['value' => WarmRunInterface::STATUS_STALE, 'label' => __('Stale')],
        ];
    }
}
