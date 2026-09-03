<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Warmer\Run;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One cache-warming cycle, as shown in the admin grid.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName")
 */
class WarmRun extends AbstractModel implements WarmRunInterface
{
    protected function _construct(): void
    {
        $this->_init(WarmRunResource::class);
    }

    public function getRunId(): ?int
    {
        $value = $this->getData(self::RUN_ID);

        return $value === null ? null : (int) $value;
    }

    public function getWarmType(): string
    {
        return (string) $this->getData(self::WARM_TYPE);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function getTotalProducts(): int
    {
        return (int) $this->getData(self::TOTAL_PRODUCTS);
    }

    public function getProcessedProducts(): int
    {
        return (int) $this->getData(self::PROCESSED_PRODUCTS);
    }

    public function getFailedProducts(): int
    {
        return (int) $this->getData(self::FAILED_PRODUCTS);
    }

    public function isRunning(): bool
    {
        return $this->getStatus() === self::STATUS_RUNNING;
    }

    public function getProgressPercent(): int
    {
        $total = $this->getTotalProducts();

        if ($total <= 0) {
            return 100;
        }

        return (int) min(100, round(($this->getProcessedProducts() / $total) * 100));
    }
}
