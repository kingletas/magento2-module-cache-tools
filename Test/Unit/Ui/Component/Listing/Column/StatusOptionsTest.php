<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Ui\Component\Listing\Column;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Ui\Component\Listing\Column\StatusOptions;
use Magento\Framework\Data\OptionSourceInterface;
use PHPUnit\Framework\TestCase;

class StatusOptionsTest extends TestCase
{
    public function testItIsUsableAsAGridFilterSource(): void
    {
        $this->assertInstanceOf(OptionSourceInterface::class, new StatusOptions());
    }

    /**
     * The filter offers exactly the statuses the table holds, no more and no
     * fewer.
     */
    public function testEveryStoredStatusIsOfferedAsAFilter(): void
    {
        $values = array_column((new StatusOptions())->toOptionArray(), 'value');

        $this->assertSame(
            [
                WarmRunInterface::STATUS_RUNNING,
                WarmRunInterface::STATUS_COMPLETE,
                WarmRunInterface::STATUS_STALE,
            ],
            $values
        );
    }

    public function testEveryOptionCarriesALabelDistinctFromItsStoredValue(): void
    {
        foreach ((new StatusOptions())->toOptionArray() as $option) {
            $this->assertNotSame('', (string) $option['label']);
            $this->assertNotSame($option['value'], (string) $option['label']);
        }
    }
}
