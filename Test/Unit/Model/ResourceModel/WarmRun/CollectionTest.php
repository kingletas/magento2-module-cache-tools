<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\ResourceModel\WarmRun;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\ResourceModel\WarmRun\Collection;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The real constructor builds a SELECT through the object manager, which a unit
 * test does not have.
 */
class CollectionTest extends TestCase
{
    public function testTheCollectionIsWiredToTheEntityAndItsResource(): void
    {
        $collection = $this->collection();

        $this->assertSame(WarmRun::class, $collection->getModelName());
        $this->assertSame(WarmRunResource::class, $collection->getResourceModelName());
    }

    /**
     * Set through the setter: the parent declares `$_idFieldName` untyped.
     */
    public function testTheIdFieldIsSetThroughTheSetter(): void
    {
        $this->assertSame(WarmRunInterface::RUN_ID, $this->collection()->getIdFieldName());
    }

    public function testTheIdFieldIsNotTheFrameworkDefault(): void
    {
        $this->assertNotSame('id', $this->collection()->getIdFieldName());
    }

    private function collection(): Collection
    {
        $collection = (new ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($collection, '_construct'))->invoke($collection);

        return $collection;
    }
}
