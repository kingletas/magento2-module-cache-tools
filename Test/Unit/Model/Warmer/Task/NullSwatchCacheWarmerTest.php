<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Commerce\CacheTools\Model\Warmer\Task\NullSwatchCacheWarmer;
use Magento\Catalog\Api\Data\ProductInterface;
use PHPUnit\Framework\TestCase;

class NullSwatchCacheWarmerTest extends TestCase
{
    /**
     * Keeps the interface bound and the warmer constructable on a store with no
     * swatches.
     */
    public function testItIsTheDefaultBindingForTheSwatchContract(): void
    {
        $this->assertInstanceOf(SwatchCacheWarmerInterface::class, new NullSwatchCacheWarmer());
    }

    /**
     * False, not true: the result counts what was actually warmed.
     */
    public function testItReportsThatNothingWasWarmed(): void
    {
        $this->assertFalse((new NullSwatchCacheWarmer())->warm($this->createMock(ProductInterface::class)));
    }
}
