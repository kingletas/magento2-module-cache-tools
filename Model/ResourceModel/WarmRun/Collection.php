<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\ResourceModel\WarmRun;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName")
 */
class Collection extends AbstractCollection
{
    /**
     * Set through the setter rather than by redeclaring the property.
     */
    protected function _construct(): void
    {
        $this->_setIdFieldName(WarmRunInterface::RUN_ID);
        $this->_init(WarmRun::class, WarmRunResource::class);
    }
}
